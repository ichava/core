<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Simtabi\Laranail\Ichava\Enums\IconKind;

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
     * The pack-wide kind from `package.kind`, or null when the pack names none.
     *
     * @param array<string, mixed> $config Parsed config.json data
     */
    public static function packageKind(array $config): ?IconKind
    {
        $package = $config['package'] ?? null;

        return IconKind::fromConfig(is_array($package) ? ($package['kind'] ?? null) : null);
    }

    /**
     * The kind each category declares, keyed by category slug.
     *
     * Only categories that name a valid kind appear; the rest inherit the
     * pack's kind, so they are left out rather than copied in.
     *
     * @param array<string, mixed> $config Parsed config.json data
     *
     * @return array<string, IconKind>
     */
    public static function categoryKinds(array $config): array
    {
        $kinds = [];

        foreach (self::categories($config) as $slug => $category) {
            if (! is_string($slug) || ! is_array($category)) {
                continue;
            }

            $kind = IconKind::fromConfig($category['kind'] ?? null);

            if ($kind !== null) {
                $kinds[$slug] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * Theme twins each category declares, keyed by category slug.
     *
     * A category that ships `scene.svg` and `scene-dark.svg` declares
     * `"theme_variants": { "dark": "-dark" }`: the dark file is then a variant
     * of the light one rather than an item of its own. The value is the
     * filename suffix, before `.svg`.
     *
     * @param array<string, mixed> $config Parsed config.json data
     *
     * @return array<string, array<string, string>> slug => theme => suffix
     */
    public static function themeVariants(array $config): array
    {
        $variants = [];

        foreach (self::categories($config) as $slug => $category) {
            if (! is_string($slug) || ! is_array($category) || ! is_array($category['theme_variants'] ?? null)) {
                continue;
            }

            foreach ($category['theme_variants'] as $theme => $suffix) {
                if (is_string($theme) && $theme !== '' && is_string($suffix) && $suffix !== '') {
                    $variants[$slug][$theme] = $suffix;
                }
            }
        }

        return $variants;
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
