<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

/**
 * Reads the variants and categories a pack declares in its config.json.
 *
 * The documented shape, which every shipped pack and the scaffolder's
 * config.json stub use, and which JsonConfigConstants reads, is:
 *
 *     { "metadata": { "data": { "variants": {...}, "categories": {...} } } }
 *
 * The top-level `variants` / `categories` keys are read as a fallback, because
 * that is the only shape IconRegistry and ConfigurationService looked at before
 * this class existed, and a pack written against it must keep working.
 *
 * The documented shape wins whenever it holds a non-empty array.
 */
final class PackConfigData
{
    /**
     * The variants map (or list) from parsed config.json data.
     *
     * @param array<string, mixed> $config Parsed config.json data
     *
     * @return array<int|string, mixed>
     */
    public static function variants(array $config): array
    {
        return self::section($config, 'variants');
    }

    /**
     * The categories map (or list) from parsed config.json data.
     *
     * @param array<string, mixed> $config Parsed config.json data
     *
     * @return array<int|string, mixed>
     */
    public static function categories(array $config): array
    {
        return self::section($config, 'categories');
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int|string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $metadata = $config['metadata'] ?? null;
        $data = is_array($metadata) ? ($metadata['data'] ?? null) : null;
        $documented = is_array($data) ? ($data[$key] ?? null) : null;

        if (is_array($documented) && $documented !== []) {
            return $documented;
        }

        $legacy = $config[$key] ?? null;

        return is_array($legacy) ? $legacy : [];
    }
}
