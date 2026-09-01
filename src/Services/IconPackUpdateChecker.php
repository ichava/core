<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Simtabi\Laranail\Ichava\Constants\JsonConfigConstants;
use Simtabi\Laranail\Ichava\Events\IconPackUpdateAvailable;
use Throwable;

/**
 * Discovers registered icon packs that are behind their upstream source.
 *
 * Reads each pack's `upstream` block (declared in its config.json),
 * hits the `version_check_url`, compares the published tag with the
 * pack's `current_version`, and dispatches IconPackUpdateAvailable for
 * each stale pack. Responses are cached for `cache_ttl` seconds to
 * dodge GitHub's 60/hour unauthenticated rate limit.
 *
 * Design notes:
 *
 * - Distributed config: each pack owns its upstream metadata.
 * - Centralised execution: the checker is the only place that knows
 *   how to GET a version_check_url and parse provider responses.
 * - Pluggable parsers per `source.type`: GitHub today; npm, packagist,
 *   custom URL trivial to add as a new `parse*Response` method.
 * - Event-driven notifications: the host app decides what to do with
 *   the news (Slack, email, dashboard...).
 */
class IconPackUpdateChecker
{
    /** Cache key prefix for HTTP responses. */
    protected const CACHE_PREFIX = 'ichava:icon-pack-update-check:';

    /** Default cache TTL (12 hours). Configurable via constructor. */
    protected const DEFAULT_CACHE_TTL = 43_200;

    public function __construct(
        protected IconRegistry $registry,
        protected ?IchavaLogger $logger = null,
        protected int $cacheTtl = self::DEFAULT_CACHE_TTL,
        protected int $httpTimeout = 15,
        protected ?Closure $constantsResolver = null,
    ) {}

    /**
     * Override the constants-class lookup strategy. Tests use this to
     * inject canned IconsConstants subclasses without registering real
     * service providers; production code never calls it.
     *
     * @param  Closure(string):?class-string<JsonConfigConstants>  $resolver
     */
    public function setConstantsResolver(Closure $resolver): void
    {
        $this->constantsResolver = $resolver;
    }

    /**
     * Check every registered pack with an `upstream` block.
     *
     * Returns one CheckResult per (package, source) pair -- packs that
     * declare `additional_sources` produce one row per secondary tracker
     * alongside their primary row. Dispatches IconPackUpdateAvailable
     * for each stale source.
     *
     * @return list<array{
     *     package: string,
     *     source: string,
     *     status: 'up-to-date'|'update-available'|'unreachable'|'no-upstream'|'error',
     *     current: ?string,
     *     latest: ?string,
     *     release_url: ?string,
     *     reason: ?string,
     * }>
     */
    public function checkAll(?string $packageFilter = null): array
    {
        $results = [];
        foreach ($this->registry->all() as $packageName => $_metadata) {
            if ($packageFilter !== null && $packageName !== $packageFilter) {
                continue;
            }
            foreach ($this->checkPackage($packageName) as $row) {
                $results[] = $row;
            }
        }

        return $results;
    }

    /**
     * Check a single registered pack and yield one row per (primary +
     * additional_sources) entry. Use this when you want to walk every
     * source a pack declares; use checkOne() when you only need the
     * primary tracker.
     *
     * @return list<array<string, mixed>>
     */
    public function checkPackage(string $packageName): array
    {
        $rows = [$this->checkOne($packageName)];

        $constantsClass = $this->resolveConstants($packageName);
        if ($constantsClass === null) {
            return $rows;
        }

        foreach ($constantsClass::getUpstreamAdditionalSources() as $extra) {
            $rows[] = $this->checkAdditional($packageName, $extra);
        }

        return $rows;
    }

    /**
     * Check a single registered pack's primary source by name.
     *
     * Looks up the pack's IconsConstants class (always at
     * <Vendor>\<Pkg>\Constants\IconsConstants by convention), reads
     * its upstream block, and dispatches one IconPackUpdateAvailable
     * if the pack is behind.
     *
     * @return array{
     *     package: string,
     *     source: string,
     *     status: 'up-to-date'|'update-available'|'unreachable'|'no-upstream'|'error',
     *     current: ?string,
     *     latest: ?string,
     *     release_url: ?string,
     *     reason: ?string,
     * }
     */
    public function checkOne(string $packageName): array
    {
        $constantsClass = $this->resolveConstants($packageName);
        if ($constantsClass === null || ! $constantsClass::hasUpstream()) {
            return [
                'package' => $packageName,
                'source' => 'primary',
                'status' => 'no-upstream',
                'current' => null,
                'latest' => null,
                'release_url' => null,
                'reason' => 'Pack does not declare an upstream block in config.json',
            ];
        }

        return $this->evaluateSource(
            $packageName,
            'primary',
            $constantsClass::getUpstream(),
            $constantsClass::getUpstreamCurrentVersion(),
        );
    }

    /**
     * Run the same fetch + parse + dispatch flow as the primary source
     * for one entry in `upstream.additional_sources`. The `current_version`
     * is read from the source entry itself (each additional tracker pins
     * its own snapshot version).
     *
     * @return array<string, mixed>
     */
    protected function checkAdditional(string $packageName, array $extraSource): array
    {
        $name = (string) ($extraSource['name'] ?? 'secondary');

        // Each additional source is a self-contained mini-upstream block.
        // The shape mirrors the primary one: source/current_version/url/cdn.
        $upstream = [
            'source' => $extraSource,
            'current_version' => $extraSource['current_version'] ?? null,
            'version_check_url' => $extraSource['version_check_url'] ?? null,
        ];

        return $this->evaluateSource(
            $packageName,
            $name,
            $upstream,
            $extraSource['current_version'] ?? null,
        );
    }

    /**
     * Shared core of the per-source check. Handles missing-url,
     * unreachable, and parse-failure branches the same way for primary
     * and additional sources.
     *
     * @return array<string, mixed>
     */
    protected function evaluateSource(
        string $packageName,
        string $sourceName,
        array $upstream,
        ?string $current,
    ): array {
        $url = $upstream['version_check_url'] ?? null;

        if (! $url) {
            return [
                'package' => $packageName,
                'source' => $sourceName,
                'status' => 'no-upstream',
                'current' => $current,
                'latest' => null,
                'release_url' => null,
                'reason' => 'upstream.version_check_url is missing',
            ];
        }

        try {
            $payload = $this->fetch($url);
        } catch (Throwable $e) {
            return [
                'package' => $packageName,
                'source' => $sourceName,
                'status' => 'unreachable',
                'current' => $current,
                'latest' => null,
                'release_url' => null,
                'reason' => $e->getMessage(),
            ];
        }

        $latest = $this->parseLatest($upstream, $payload);
        if ($latest === null) {
            return [
                'package' => $packageName,
                'source' => $sourceName,
                'status' => 'error',
                'current' => $current,
                'latest' => null,
                'release_url' => $this->resolveReleaseUrl($upstream, $payload, null),
                'reason' => 'Could not parse latest version from upstream response',
            ];
        }

        $releaseUrl = $this->resolveReleaseUrl($upstream, $payload, $latest);
        if ($this->isStale($current, $latest)) {
            Event::dispatch(new IconPackUpdateAvailable(
                packageName: $packageName,
                currentVersion: $current,
                latestVersion: $latest,
                releaseUrl: $releaseUrl,
                releaseNotes: isset($payload['body']) ? substr((string) $payload['body'], 0, 2_000) : null,
                sourceName: $sourceName,
            ));

            return [
                'package' => $packageName,
                'source' => $sourceName,
                'status' => 'update-available',
                'current' => $current,
                'latest' => $latest,
                'release_url' => $releaseUrl,
                'reason' => null,
            ];
        }

        return [
            'package' => $packageName,
            'source' => $sourceName,
            'status' => 'up-to-date',
            'current' => $current,
            'latest' => $latest,
            'release_url' => $releaseUrl,
            'reason' => null,
        ];
    }

    /**
     * Build a human-clickable release URL per source type. GitHub gives us
     * one for free via `html_url`; for everything else we synthesise the
     * canonical "view this version on the registry" URL.
     *
     * Returns null when we can't determine a sensible URL (e.g. the pack
     * uses source.type=url without registering source.release_url_template).
     */
    protected function resolveReleaseUrl(array $upstream, array $payload, ?string $version): ?string
    {
        $type = $upstream['source']['type'] ?? 'github';
        $source = $upstream['source'] ?? [];

        return match ($type) {
            'github' => $payload['html_url'] ?? $this->githubReleaseUrl($source, $version),
            'github-tag' => $this->githubReleaseUrl($source, $version),
            'npm' => $this->npmReleaseUrl($source, $version),
            'packagist' => $this->packagistReleaseUrl($source, $version),
            'url' => $this->interpolateTemplate(
                $source['release_url_template'] ?? null,
                ['version' => $version ?? ''],
            ),
            default => null,
        };
    }

    /**
     * https://github.com/<owner>/<repo>/releases/tag/v<version>
     * Falls back to the repo root when owner/repo aren't both declared.
     */
    protected function githubReleaseUrl(array $source, ?string $version): ?string
    {
        $owner = $source['owner'] ?? null;
        $repo = $source['repo'] ?? null;
        if (! $owner || ! $repo) {
            return null;
        }
        if ($version === null) {
            return "https://github.com/{$owner}/{$repo}";
        }

        return "https://github.com/{$owner}/{$repo}/releases/tag/v{$version}";
    }

    /**
     * https://www.npmjs.com/package/<package>/v/<version>
     * Scoped packages (@x/y) are encoded by npm automatically.
     */
    protected function npmReleaseUrl(array $source, ?string $version): ?string
    {
        $package = $source['package'] ?? null;
        if (! $package) {
            return null;
        }
        if ($version === null) {
            return "https://www.npmjs.com/package/{$package}";
        }

        return "https://www.npmjs.com/package/{$package}/v/{$version}";
    }

    /**
     * https://packagist.org/packages/<vendor>/<package>#<version>
     */
    protected function packagistReleaseUrl(array $source, ?string $version): ?string
    {
        $vendor = $source['vendor'] ?? null;
        $package = $source['package'] ?? null;
        if (! $vendor || ! $package) {
            return null;
        }
        $base = "https://packagist.org/packages/{$vendor}/{$package}";

        return $version === null ? $base : "{$base}#{$version}";
    }

    /**
     * Replace `{token}` segments in a template with values from $bindings.
     * Returns null when the template is missing entirely.
     */
    protected function interpolateTemplate(?string $template, array $bindings): ?string
    {
        if ($template === null || $template === '') {
            return null;
        }
        foreach ($bindings as $key => $value) {
            $template = str_replace('{'.$key.'}', (string) $value, $template);
        }

        return $template;
    }

    /**
     * Find the IconsConstants class for a pack via the Ichava naming
     * convention (every child pack ships <Vendor>\<Pkg>\Constants\IconsConstants).
     *
     * A custom resolver passed via the constructor / setConstantsResolver()
     * short-circuits the default discovery; useful for tests that don't
     * want to register a full service provider per fixture.
     */
    protected function resolveConstants(string $packageName): ?string
    {
        if ($this->constantsResolver !== null) {
            return ($this->constantsResolver)($packageName);
        }

        // Search loaded service providers for one whose namespace matches the package.
        // Each pack's provider lives at <Vendor>\<Pkg>\Providers\IconsServiceProvider,
        // so we can derive the Constants FQCN by namespace surgery.
        foreach (array_keys(app()->getLoadedProviders()) as $providerClass) {
            if (! str_ends_with($providerClass, '\\Providers\\IconsServiceProvider')) {
                continue;
            }
            $namespace = substr($providerClass, 0, -strlen('\\Providers\\IconsServiceProvider'));
            $constants = $namespace.'\\Constants\\IconsConstants';
            if (! class_exists($constants) || ! is_subclass_of($constants, JsonConfigConstants::class)) {
                continue;
            }
            try {
                if ($constants::getVendorPackage() === $packageName) {
                    return $constants;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * GET the version-check URL with caching. Cache TTL defaults to 12
     * hours; pass a lower value to the constructor for tighter polling.
     */
    protected function fetch(string $url): array
    {
        $cacheKey = self::CACHE_PREFIX.md5($url);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($url): array {
            $response = Http::timeout($this->httpTimeout)
                ->acceptJson()
                ->withUserAgent('ichava-icon-pack-update-checker (https://github.com/ichava/core)')
                ->get($url);
            $response->throw();

            return $response->json() ?? [];
        });
    }

    /**
     * Pull the latest version string out of the upstream response.
     *
     * Supported source types and the endpoints they expect:
     *
     *   github     https://api.github.com/repos/<owner>/<repo>/releases/latest
     *              -> response.tag_name (often "v17.0.0"; the leading "v"
     *              is stripped so version_compare works cleanly).
     *
     *   github-tag https://api.github.com/repos/<owner>/<repo>/tags?per_page=1
     *              -> response[0].name (for projects that use git tags but
     *              not GitHub releases, e.g. lipis/flag-icons).
     *
     *   npm        https://registry.npmjs.org/<package>/latest
     *              -> response.version (no rate limit; preferred when an
     *              upstream publishes to npm).
     *
     *   packagist  https://repo.packagist.org/p2/<vendor>/<package>.json
     *              -> response.packages.<vendor>/<package>[0].version
     *
     *   url        custom JSON endpoint; ``source.version_field`` is a
     *              dot-path into the response. Escape hatch for vendors
     *              not on github/npm/packagist.
     */
    protected function parseLatest(array $upstream, array $payload): ?string
    {
        $type = $upstream['source']['type'] ?? 'github';

        return match ($type) {
            'github' => $this->trimVersion($payload['tag_name'] ?? null),
            'github-tag' => $this->trimVersion(
                $payload[0]['name'] ?? ($payload[0]['ref'] ?? null),
            ),
            'npm' => $this->trimVersion($payload['version'] ?? null),
            'packagist' => $this->parsePackagist($upstream, $payload),
            'url' => $this->parseDotPath($payload, $upstream['source']['version_field'] ?? 'version'),
            default => $this->trimVersion($payload['version'] ?? $payload['tag_name'] ?? null),
        };
    }

    /**
     * Packagist p2 endpoint returns `packages.<vendor>/<package>` as a list
     * of versions sorted newest-first. We take the first entry's `version`.
     */
    protected function parsePackagist(array $upstream, array $payload): ?string
    {
        $key = ($upstream['source']['vendor'] ?? '').'/'.($upstream['source']['package'] ?? '');
        $key = trim($key, '/');
        if ($key === '') {
            return null;
        }
        $versions = $payload['packages'][$key] ?? [];
        $latest = $versions[0]['version'] ?? null;
        // Packagist tags often look like "v1.2.3" or "dev-main"; the latter
        // shouldn't compare as "newest" so we filter dev-* refs out.
        if (! is_string($latest) || str_starts_with($latest, 'dev-')) {
            // Walk the list until we find a stable-looking entry.
            foreach ($versions as $entry) {
                $v = $entry['version'] ?? null;
                if (is_string($v) && ! str_starts_with($v, 'dev-')) {
                    return $this->trimVersion($v);
                }
            }

            return null;
        }

        return $this->trimVersion($latest);
    }

    /**
     * Walk a dot-separated path into a nested array. ``"dist-tags.latest"``
     * returns ``$payload['dist-tags']['latest']`` or null if any segment
     * is missing.
     */
    protected function parseDotPath(array $payload, string $path): ?string
    {
        $cursor = $payload;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return is_string($cursor) ? $this->trimVersion($cursor) : null;
    }

    /**
     * "v17.0.0" -> "17.0.0". null in, null out.
     */
    protected function trimVersion(?string $tag): ?string
    {
        if ($tag === null || $tag === '') {
            return null;
        }

        return ltrim($tag, 'vV ');
    }

    /**
     * String-compare the two versions. Most icon vendors use semver or
     * date-stamped tags; both compare correctly with `version_compare`
     * in 99% of cases. If a vendor uses something weird, the pack can
     * override this check via its own command.
     */
    protected function isStale(?string $current, string $latest): bool
    {
        if ($current === null || $current === '') {
            return true;
        }

        return version_compare($current, $latest, '<');
    }
}
