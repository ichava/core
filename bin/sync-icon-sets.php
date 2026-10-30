#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sync the static icon-sets catalog from each pack's GitHub repository.
 *
 * For every set pinned in icon-sets.json (key/package/repository) this
 * script resolves the latest release tag, reads the pack's config.json at
 * that tag, counts the committed SVGs (in all, and per kind as icon_counts)
 * via the git-trees API, and writes
 * the snapshot back — so `ichava:install` stays fully local with zero
 * runtime network calls.
 *
 * Usage:
 *   php bin/sync-icon-sets.php
 *
 * Env:
 *   GITHUB_TOKEN  optional, raises the API rate limit (workflow provides it)
 */
const CONFIG_FALLBACK_BRANCH = 'main';
const DEFAULT_CONFIG_PATH = 'resources/assets/svg/config.json';

// Run only when executed as a script, so the functions below can be loaded and tested on their own.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main(dirname(__DIR__) . '/icon-sets.json'));
}

function main(string $manifestPath): int
{
    $raw = @file_get_contents($manifestPath);

    if ($raw === false) {
        fwrite(STDERR, "Cannot read {$manifestPath}\n");

        return 1;
    }

    $manifest = json_decode($raw, true);

    if (! is_array($manifest) || ! is_array($manifest['sets'] ?? null)) {
        fwrite(STDERR, "Malformed catalog at {$manifestPath}\n");

        return 1;
    }

    $configPath = $manifest['config_path'] ?? DEFAULT_CONFIG_PATH;

    foreach ($manifest['sets'] as $index => $set) {
        $key = (string) ($set['key'] ?? $index);
        $repository = (string) ($set['repository'] ?? '');

        if ($repository === '') {
            fwrite(STDERR, "[{$key}] skipped: no repository pinned\n");

            continue;
        }

        $snapshot = snapshot($repository, $configPath, $set);

        if ($snapshot === null) {
            fwrite(STDERR, "[{$key}] kept previous snapshot: sync failed\n");

            continue;
        }

        $manifest['sets'][$index] = array_merge($set, $snapshot);

        printf(
            "[%s] %s | %d icons (%s) | latest %s\n",
            $key,
            $snapshot['title'],
            $snapshot['icon_count'],
            implode('+', $snapshot['variants']),
            $snapshot['latest_version'],
        );
    }

    $manifest['schema_version'] = '3.0';

    // synced_at records when the catalogue last *changed*, not when the script last ran. Stamping it on
    // every run made each daily sync a one-line diff, and so a pull request a day with nothing in it.
    $previous = json_decode($raw, true);

    if (withoutStamp($previous) === withoutStamp($manifest)) {
        echo "Catalogue unchanged; {$manifestPath} left as it is.\n";

        return 0;
    }

    $manifest['synced_at'] = gmdate('Y-m-d\TH:i:s\Z');

    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        fwrite(STDERR, "Cannot encode catalog\n");

        return 1;
    }

    file_put_contents($manifestPath, $encoded . "\n");
    echo "Wrote {$manifestPath}\n";

    return 0;
}

/**
 * The catalogue without its sync timestamp, for comparing two runs.
 *
 * @param array<string, mixed> $manifest
 *
 * @return array<string, mixed>
 */
function withoutStamp(array $manifest): array
{
    unset($manifest['synced_at']);

    return $manifest;
}

/**
 * @param array<string, mixed> $previous previously synced values (kept on partial failure)
 *
 * @return array<string, mixed>|null null when nothing could be resolved
 */
function snapshot(string $repository, string $configPath, array $previous): ?array
{
    $tag = latestTag($repository);

    $refs = array_filter([$tag, CONFIG_FALLBACK_BRANCH]);
    $config = null;

    foreach ($refs as $ref) {
        $config = getJson("https://raw.githubusercontent.com/{$repository}/{$ref}/{$configPath}");

        if ($config !== null) {
            break;
        }
    }

    if ($config === null) {
        return null;
    }

    $variants = $config['metadata']['data']['variants'] ?? null;
    $tree = $tag !== null ? svgPaths($repository, $tag, $configPath) : null;
    $count = $tree !== null ? count($tree) : null;
    $counts = $tree !== null ? countsByKind($tree, $config) : null;

    return [
        'title'          => (string) ($config['package']['title'] ?? $previous['title'] ?? $previous['key']),
        'description'    => $config['package']['description'] ?? ($previous['description'] ?? null),
        'license'        => $config['package']['license'] ?? ($previous['license'] ?? null),
        'icon_count'     => $count ?? ($previous['icon_count'] ?? null),
        'icon_counts'    => $counts ?? ($previous['icon_counts'] ?? null),
        'variants'       => is_array($variants) ? array_keys($variants) : ($previous['variants'] ?? null),
        'latest_version' => $tag !== null ? ltrim($tag, 'vV') : ($previous['latest_version'] ?? null),
    ];
}

function latestTag(string $repository): ?string
{
    $tags = getJson("https://api.github.com/repos/{$repository}/tags?per_page=100");

    return is_array($tags) ? highestVersionTag($tags) : null;
}

/**
 * The highest plain `vX.Y.Z` tag in a tags API response.
 *
 * The API lists every tag, in an order it does not document, including names that are not
 * releases: a Go module alias (`src/v0.1.0`), an archive or work-in-progress tag. Taking the
 * first entry could publish one of those as the pack's latest version. Pre-releases are skipped
 * for the same reason: the catalogue names what a `^` constraint would install.
 *
 * @param array<mixed> $tags the tags API response
 */
function highestVersionTag(array $tags): ?string
{
    $best = null;

    foreach ($tags as $tag) {
        $name = is_array($tag) ? ($tag['name'] ?? null) : null;

        if (! is_string($name) || preg_match('/^v\d+\.\d+\.\d+$/', $name) !== 1) {
            continue;
        }

        if ($best === null || version_compare(substr($name, 1), substr($best, 1), '>')) {
            $best = $name;
        }
    }

    return $best;
}

function iconCount(string $repository, string $tag, string $configPath): ?int
{
    $paths = svgPaths($repository, $tag, $configPath);

    return $paths !== null ? count($paths) : null;
}

/**
 * The committed SVG paths under the pack's `files/` directory at a tag, relative to it.
 *
 * @return list<string>|null null when the tree cannot be read, is truncated, or holds no SVG
 */
function svgPaths(string $repository, string $tag, string $configPath): ?array
{
    $tree = getJson("https://api.github.com/repos/{$repository}/git/trees/{$tag}?recursive=1");

    return is_array($tree) ? svgPathsInTree($tree, $configPath) : null;
}

/**
 * @param array<mixed> $tree a git-trees API response
 *
 * @return list<string>|null
 */
function svgPathsInTree(array $tree, string $configPath): ?array
{
    $filesDir = rtrim(dirname($configPath), '/') . '/files/';

    if (($tree['truncated'] ?? false) === true || ! is_array($tree['tree'] ?? null)) {
        return null;
    }

    $paths = [];

    foreach ($tree['tree'] as $entry) {
        $path = is_array($entry) ? (string) ($entry['path'] ?? '') : '';

        if (is_array($entry) && ($entry['type'] ?? null) === 'blob'
            && str_starts_with($path, $filesDir)
            && str_ends_with($path, '.svg')) {
            $paths[] = substr($path, strlen($filesDir));
        }
    }

    return $paths !== [] ? $paths : null;
}

/**
 * SVG files per kind, by the registry's rule (IconClassifier::categoryOf() + IconRegistry::kindFor()):
 * the category is the first directory under `files/`, and its kind is the category's own `kind`,
 * else the pack's `package.kind`, else `icon`. Counts are files, like `icon_count`, so the two
 * always add up; a dark twin counts as a file.
 *
 * @param list<string> $paths SVG paths relative to `files/`
 * @param array<mixed> $config the pack's config.json
 *
 * @return array{icon: int, illustration: int}
 */
function countsByKind(array $paths, array $config): array
{
    $packKind = kindOf($config['package']['kind'] ?? null) ?? 'icon';
    $categories = $config['metadata']['data']['categories'] ?? [];
    $categories = is_array($categories) ? $categories : [];
    $counts = ['icon' => 0, 'illustration' => 0];

    foreach ($paths as $path) {
        $segments = explode('/', $path);
        $category = count($segments) > 1 ? ($categories[$segments[0]] ?? null) : null;
        $kind = is_array($category) ? (kindOf($category['kind'] ?? null) ?? $packKind) : $packKind;

        $counts[$kind]++;
    }

    return $counts;
}

/**
 * @return 'icon'|'illustration'|null
 */
function kindOf(mixed $value): ?string
{
    return $value === 'icon' || $value === 'illustration' ? $value : null;
}

/**
 * @return array<mixed>|null decoded JSON, null on any failure
 */
function getJson(string $url): ?array
{
    [$status, $body] = get($url);

    if ($status < 200 || $status >= 300 || $body === '') {
        return null;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array{0: int, 1: string} HTTP status + body ('' on transport failure)
 */
function get(string $url): array
{
    $token = getenv('GITHUB_TOKEN') ?: '';

    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: ichava-icon-sets-sync (https://github.com/ichava/core)',
    ];

    if ($token !== '') {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    return [$status, is_string($body) ? $body : ''];
}
