#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sync the static icon-sets catalog from each pack's GitHub repository.
 *
 * For every set pinned in icon-sets.json (key/package/repository) this
 * script resolves the latest release tag, reads the pack's config.json at
 * that tag, counts the committed SVGs via the git-trees API, and writes
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

$manifestPath = dirname(__DIR__) . '/icon-sets.json';

$exit = main($manifestPath);
exit($exit);

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
    $count = $tag !== null ? iconCount($repository, $tag, $configPath) : null;

    return [
        'title'          => (string) ($config['package']['title'] ?? $previous['title'] ?? $previous['key']),
        'description'    => $config['package']['description'] ?? ($previous['description'] ?? null),
        'license'        => $config['package']['license'] ?? ($previous['license'] ?? null),
        'icon_count'     => $count ?? ($previous['icon_count'] ?? null),
        'variants'       => is_array($variants) ? array_keys($variants) : ($previous['variants'] ?? null),
        'latest_version' => $tag !== null ? ltrim($tag, 'vV') : ($previous['latest_version'] ?? null),
    ];
}

function latestTag(string $repository): ?string
{
    $tags = getJson("https://api.github.com/repos/{$repository}/tags?per_page=1");
    $name = is_array($tags) ? ($tags[0]['name'] ?? null) : null;

    return is_string($name) && $name !== '' ? $name : null;
}

function iconCount(string $repository, string $tag, string $configPath): ?int
{
    $filesDir = rtrim(dirname($configPath), '/') . '/files/';
    $tree = getJson("https://api.github.com/repos/{$repository}/git/trees/{$tag}?recursive=1");

    if (! is_array($tree) || ($tree['truncated'] ?? false) === true || ! is_array($tree['tree'] ?? null)) {
        return null;
    }

    $count = 0;

    foreach ($tree['tree'] as $entry) {
        $path = (string) ($entry['path'] ?? '');

        if (($entry['type'] ?? null) === 'blob'
            && str_starts_with($path, $filesDir)
            && str_ends_with($path, '.svg')) {
            $count++;
        }
    }

    return $count > 0 ? $count : null;
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
