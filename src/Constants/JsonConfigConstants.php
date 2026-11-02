<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Constants;

use UnexpectedValueException;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Data\VariantData;
use Simtabi\Laranail\Ichava\Support\PackConfigData;
use Simtabi\Laranail\Ichava\Services\ConfigurationService;

/**
 * Abstract base for icon-package metadata classes that derive their values
 * from a per-package `config.json` rather than hard-coded constants.
 *
 * Extend, implement `getConfigPath()`, and the package-name / vendor /
 * version / variant / category / prefix accessors become available with
 * lazy-loaded, per-class-cached reads.
 *
 * @see ConfigurationService
 */
abstract class JsonConfigConstants
{
    /**
     * Cached config data per class
     *
     * @var array<string, array<array-key, mixed>>
     */
    private static array $configs = [];

    /**
     * Clear cached config (useful for testing)
     */
    final public static function clearCache(): void
    {
        unset(self::$configs[static::class]);
    }

    /**
     * Get vendor name from package.name (vendor/package → vendor)
     */
    public static function getVendorName(): string
    {
        return Helpers::getVendorFromPackage(self::requiredString(['package', 'name']));
    }

    /**
     * Get package name from package.name (vendor/package → package)
     */
    public static function getPackageName(): string
    {
        return Helpers::getPackageFromIdentifier(self::requiredString(['package', 'name']));
    }

    /**
     * Get full vendor/package name
     */
    public static function getVendorPackage(): string
    {
        return self::requiredString(['package', 'name']);
    }

    /**
     * Get icon prefix from config.icon_prefix
     */
    public static function getPrefix(): string
    {
        return self::requiredString(['config', 'icon_prefix']);
    }

    /**
     * Get categories from metadata.data.categories
     *
     * @return list<array-key>
     */
    public static function getCategories(): array
    {
        return array_keys(self::arrayValue(['metadata', 'data', 'categories']));
    }

    /**
     * Get variants from metadata.data.variants (keys only)
     *
     * @return list<array-key>
     */
    public static function getVariants(): array
    {
        return array_keys(self::arrayValue(['metadata', 'data', 'variants']));
    }

    /**
     * Get all variants with full metadata
     *
     * @return array<array-key, mixed>
     */
    public static function getVariantsWithMetadata(): array
    {
        return self::arrayValue(['metadata', 'data', 'variants']);
    }

    /**
     * Get default variant
     */
    public static function getDefaultVariant(): ?string
    {
        $variants = static::getVariantsWithMetadata();

        foreach ($variants as $slug => $data) {
            if (is_array($data) && ($data['default'] ?? false)) {
                return (string) $slug;
            }
        }

        $firstKey = array_key_first($variants);

        return $firstKey === null ? null : (string) $firstKey;
    }

    /**
     * Get default category from metadata.data.categories (first entry flagged default,
     * else first listed). Returns null when no categories are defined.
     */
    public static function getDefaultCategory(): ?string
    {
        $categories = self::arrayValue(['metadata', 'data', 'categories']);

        foreach ($categories as $slug => $data) {
            if (is_array($data) && ($data['default'] ?? false)) {
                return (string) $slug;
            }
        }

        $firstKey = array_key_first($categories);

        return $firstKey === null ? null : (string) $firstKey;
    }

    /**
     * Get variant metadata
     *
     * @return array<array-key, mixed>|null
     */
    public static function getVariantMetadata(string $variant): ?array
    {
        $metadata = static::getVariantsWithMetadata()[$variant] ?? null;

        return is_array($metadata) ? $metadata : null;
    }

    /**
     * Get variant display name
     */
    public static function getVariantName(string $variant): string
    {
        return static::getVariantData($variant)->label;
    }

    /**
     * Get variant description
     */
    public static function getVariantDescription(string $variant): string
    {
        return static::getVariantData($variant)->description;
    }

    /**
     * Get variant attributes (for SVG rendering)
     *
     * @return array<array-key, mixed>
     */
    public static function getVariantAttributes(string $variant): array
    {
        return static::getVariantData($variant)->attributes;
    }

    /**
     * Check if variant exists
     */
    public static function hasVariant(string $variant): bool
    {
        return isset(static::getVariantsWithMetadata()[$variant]);
    }

    /**
     * Get variants sorted by display order
     *
     * @return list<array-key>
     */
    public static function getVariantsSorted(): array
    {
        return array_map(
            static fn (VariantData $variant): string => $variant->slug,
            static::getVariantDataList(),
        );
    }

    /**
     * One variant as the browser shows it: label, description, attributes,
     * preview icon and colour scheme, parsed by VariantData.
     */
    public static function getVariantData(string $variant): VariantData
    {
        return VariantData::fromMetadata($variant, static::getVariantMetadata($variant) ?? []);
    }

    /**
     * Every variant, in display order.
     *
     * @return list<VariantData>
     */
    public static function getVariantDataList(): array
    {
        return VariantData::listFromMetadata(static::getVariantsWithMetadata());
    }

    /**
     * Get variant preview icon name
     */
    public static function getVariantPreviewIcon(string $variant): ?string
    {
        return static::getVariantData($variant)->previewIcon;
    }

    /**
     * Get variant color scheme
     */
    public static function getVariantColorScheme(string $variant): ?string
    {
        return static::getVariantData($variant)->colorScheme;
    }

    /**
     * The pack-wide kind from `package.kind`, else {@see IconKind::Icon}.
     */
    public static function getPackageKind(): IconKind
    {
        return PackConfigData::packageKind(self::stringKeyed(static::config())) ?? IconKind::Icon;
    }

    /**
     * The kind of one category: its own `kind`, else the pack's.
     *
     * Parsed by PackConfigData, the same reader the registry uses at
     * registration, so a pack's constants class and the seeder cannot
     * disagree about what a category holds.
     */
    public static function getCategoryKind(string $category): IconKind
    {
        return PackConfigData::categoryKinds(self::stringKeyed(static::config()))[$category]
            ?? static::getPackageKind();
    }

    /**
     * Get package title from package.title
     */
    public static function getTitle(): string
    {
        return self::optionalString(['package', 'title']) ?? static::getPackageName();
    }

    /**
     * Get package description from package.description
     */
    public static function getDescription(): string
    {
        return self::optionalString(['package', 'description']) ?? '';
    }

    /**
     * Get package version from package.version
     */
    public static function getVersion(): string
    {
        return self::optionalString(['package', 'version']) ?? '1.0.0';
    }

    /**
     * Get package license from package.license
     */
    public static function getLicense(): string
    {
        return self::optionalString(['package', 'license']) ?? 'MIT';
    }

    /**
     * Get package type (single, multi, bulk) from package.type
     */
    public static function getType(): string
    {
        return self::optionalString(['package', 'type']) ?? 'single';
    }

    /**
     * Get the UPSTREAM project's home page from metadata.homepage
     *
     * Not this package's own URL. `composer.json`'s `homepage` is this
     * package's landing page and `metadata.repository` is its repository; this
     * is the site of the project whose icons the pack vendors, and it is the
     * only place that fact is recorded in a human-facing form.
     *
     * Returns '' when the pack has no single upstream -- `icon-sets-emoji`
     * vendors two projects across three sets and omits the key deliberately.
     *
     * Example: https://tabler-icons.io/
     */
    public static function getHomepage(): string
    {
        return self::optionalString(['metadata', 'homepage']) ?? '';
    }

    /**
     * Get THIS package's repository URL from metadata.repository
     *
     * Ours, never the upstream's. `latestTag()` resolves it against our own
     * tags, and the browser API groups it with package_name, vendor, version
     * and license -- package metadata, not provenance.
     *
     * Example: https://github.com/ichava/icon-sets-tabler
     */
    public static function getRepository(): string
    {
        return self::optionalString(['metadata', 'repository']) ?? '';
    }

    /**
     * Get THIS package's GitHub repository in vendor/repo format
     *
     * Extracts the vendor/repo portion from a full GitHub URL.
     * Example: https://github.com/ichava/icon-sets-tabler → ichava/icon-sets-tabler
     */
    public static function getGitHubRepo(): string
    {
        $repository = static::getRepository();

        if (empty($repository)) {
            return '';
        }

        // Extract vendor/repo from GitHub URL
        if (preg_match('#github\.com/([^/]+/[^/]+)#', $repository, $matches)) {
            return rtrim($matches[1], '.git');
        }

        // Already in vendor/repo format
        if (preg_match('#^[\w-]+/[\w-]+$#', $repository)) {
            return $repository;
        }

        return '';
    }

    /**
     * Get the files directory path (where SVG files are stored)
     */
    public static function getFilesPath(): string
    {
        return static::getConfigPath() . DIRECTORY_SEPARATOR . 'files';
    }

    /**
     * Get path to a specific category/variant folder
     *
     * @param string|null $subfolder Optional subfolder (category or variant name)
     *
     * @return string Full path to the SVG files directory or subfolder
     */
    public static function getSvgPath(?string $subfolder = null): string
    {
        $basePath = static::getFilesPath();

        if ($subfolder === null) {
            return $basePath;
        }

        return $basePath . DIRECTORY_SEPARATOR . $subfolder;
    }

    /**
     * Get default CSS class for icons
     */
    public static function getDefaultClass(): string
    {
        return self::optionalString(['config', 'defaults', 'class']) ?? '';
    }

    /**
     * Get default stroke width (for outline icons)
     */
    public static function getDefaultStrokeWidth(): int
    {
        $strokeWidth = self::value(['config', 'defaults', 'stroke_width']);

        return is_int($strokeWidth) ? $strokeWidth : 2;
    }

    /**
     * Get default attributes for icons
     *
     * @return array<array-key, mixed>
     */
    public static function getDefaultAttributes(): array
    {
        return self::arrayValue(['config', 'defaults', 'attributes']);
    }

    /**
     * Whether the pack declares an upstream tracking block.
     */
    public static function hasUpstream(): bool
    {
        return ! empty(static::config()['upstream'] ?? null);
    }

    /**
     * Return the full upstream block (or empty array if absent).
     *
     * Shape (see documentation/icon-pack-upstream-tracking.md for the
     * full schema):
     *
     *   - source.type        "npm" | "github" | "github-tag" | "packagist" | "url"
     *   - source.package     npm package id (type=npm) -- e.g. "@twemoji/svg"
     *   - source.owner/repo  GitHub coordinates (type=github | github-tag)
     *   - source.vendor/package  Packagist coordinates (type=packagist)
     *   - source.version_field   dot-path into JSON response (type=url)
     *   - source.release_url_template  templated release URL (type=url)
     *   - current_version    pack-vendored release tag (e.g. "17.0.0")
     *   - version_check_url  URL the checker GETs for the latest version
     *   - license            asset licence (informational)
     *   - cdn                { provider => "https://.../{version}/{name}.svg" }
     *   - update_command     { type, path, args[] } -- how to refresh assets
     *   - additional_sources [{ name, type, ... }] -- secondary trackers
     *
     * @return array<array-key, mixed>
     */
    public static function getUpstream(): array
    {
        return self::arrayValue(['upstream']);
    }

    /**
     * The version the pack was last refreshed from. Returns null when
     * the pack hasn't opted into upstream tracking.
     */
    public static function getUpstreamCurrentVersion(): ?string
    {
        $version = static::getUpstream()['current_version'] ?? null;

        return is_string($version) ? $version : null;
    }

    /**
     * URL the update checker hits to discover the latest upstream
     * release. Typically a registry endpoint (npm/packagist) or a
     * GitHub releases / tags API.
     */
    public static function getUpstreamVersionCheckUrl(): ?string
    {
        $url = static::getUpstream()['version_check_url'] ?? null;

        return is_string($url) ? $url : null;
    }

    /**
     * CDN URL templates as `{ provider => template }`.
     *
     * Each template MAY contain `{version}`, `{codepoint}`, `{name}`,
     * `{variant}`, `{ratio}`, etc. placeholders that callers substitute.
     *
     * @return array<array-key, mixed>
     */
    public static function getUpstreamCdnUrls(): array
    {
        $cdn = static::getUpstream()['cdn'] ?? [];

        return is_array($cdn) ? $cdn : [];
    }

    /**
     * Return the `update_command` block consumed by the maintainer-side
     * sync toolkit (see https://github.com/ichava/icon-sets-sync).
     * Returns null when the pack doesn't advertise a refresh recipe.
     *
     * Recognised shapes (the toolkit dispatches on `type`; see
     * documentation/icon-pack-maintainer-sync.md):
     *
     *   { "type": "npm",            "package": "...",     "source_path": "..." }
     *   { "type": "github-archive", "archive_url": "...", "source_path": "..." }
     *   { "type": "recipe",         "recipe": "emoji-sets" }
     *   { "type": "url",            "version_field": "version" }
     *
     * @return array<string, mixed>|null
     */
    public static function getUpstreamUpdateCommand(): ?array
    {
        $command = static::getUpstream()['update_command'] ?? null;

        if (! is_array($command)) {
            return null;
        }

        $typed = [];

        foreach ($command as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * Secondary trackers a pack wants the checker to poll alongside the
     * primary `source`. Each entry mirrors the `source` shape but adds
     * a `name` field used for event correlation.
     *
     * @return list<array<string, mixed>>
     */
    public static function getUpstreamAdditionalSources(): array
    {
        $sources = static::getUpstream()['additional_sources'] ?? [];

        if (! is_array($sources)) {
            return [];
        }

        $typed = [];

        foreach ($sources as $source) {
            $entry = [];

            foreach (is_array($source) ? $source : [] as $key => $value) {
                $entry[(string) $key] = $value;
            }

            $typed[] = $entry;
        }

        return $typed;
    }

    /**
     * Load and cache config data from config.json for the calling child class.
     *
     * @return array<array-key, mixed>
     */
    final protected static function config(): array
    {
        $class = static::class;

        if (! isset(self::$configs[$class])) {
            $service = resolve(ConfigurationService::class);
            $path = static::getConfigPath();
            self::$configs[$class] = $service->rememberPackageConfig($path);
        }

        return self::$configs[$class];
    }

    /**
     * Get path to config.json directory (must be implemented by child)
     *
     * @return string Absolute path to directory containing config.json
     */
    abstract protected static function getConfigPath(): string;

    /**
     * Walk a key path through the config, answering null for a missing or
     * null node -- the same cases the old `?? default` reads treated as absent.
     *
     * @param list<string> $keys
     */
    private static function value(array $keys): mixed
    {
        $node = static::config();

        foreach ($keys as $key) {
            if (! is_array($node) || ! isset($node[$key])) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @param list<string> $keys
     */
    private static function optionalString(array $keys): ?string
    {
        $value = self::value($keys);

        return is_string($value) ? $value : null;
    }

    /**
     * A key every pack must declare. A missing or non-string value fails
     * loudly, as the unguarded read it replaces did.
     *
     * @param list<string> $keys
     */
    private static function requiredString(array $keys): string
    {
        $value = self::value($keys);

        if (! is_string($value)) {
            throw new UnexpectedValueException(sprintf(
                '%s: config.json must declare "%s" as a string.',
                static::class,
                implode('.', $keys),
            ));
        }

        return $value;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<array-key, mixed>
     */
    private static function arrayValue(array $keys): array
    {
        $value = self::value($keys);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $config): array
    {
        $typed = [];

        foreach ($config as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
