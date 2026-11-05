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
 * That is the only shape read. Top-level `variants` / `categories` keys are
 * ignored: no pack ships them, and the earlier top-level fallback was removed
 * when the estate went forward-only (nothing had been released).
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
     * The kind each item declares, keyed by item path (D6 row 2).
     *
     * `items` maps an item path (`illustrations/office`, relative to `files/`,
     * no `.svg`) to an object, so per-item metadata can grow later; only
     * `kind` is read. An entry whose kind names no kind is dropped here and
     * reported by doctor ({@see self::kindIssues()}).
     *
     * @param array<string, mixed> $config Parsed config.json data
     *
     * @return array<string, IconKind>
     */
    public static function items(array $config): array
    {
        $kinds = [];

        foreach (self::section($config, 'items') as $path => $item) {
            $kind = is_string($path) && $path !== '' && is_array($item) ? IconKind::fromConfig($item['kind'] ?? null) : null;

            if ($kind !== null) {
                $kinds[(string) $path] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * The pack's kind rules, in declared order (D6 row 3).
     *
     * `kinds.rules` is an array, not a map, because rule order decides the
     * winner and JSON object order is not guaranteed. A rule with an invalid
     * glob ({@see KindGlob}) or a kind that names no kind is dropped here and
     * reported by doctor.
     *
     * @param array<string, mixed> $config Parsed config.json data
     *
     * @return list<array{match: string, kind: IconKind}>
     */
    public static function kindRules(array $config): array
    {
        $rules = [];

        foreach (self::rawKindRules($config) as $rule) {
            $match = is_array($rule) ? ($rule['match'] ?? null) : null;
            $kind = is_array($rule) ? IconKind::fromConfig($rule['kind'] ?? null) : null;

            if (is_string($match) && KindGlob::isValid($match) && $kind !== null) {
                $rules[] = ['match' => $match, 'kind' => $kind];
            }
        }

        return $rules;
    }

    /**
     * Every kind key the pack got wrong, for doctor.
     *
     * Each entry names its doctor check, the key path under `metadata.data`
     * and the offending value.
     *
     * @param array<string, mixed> $config Parsed config.json data
     *
     * @return list<array{check: string, key: string, value: mixed, index?: int, pattern?: string}>
     */
    public static function kindIssues(array $config): array
    {
        $issues = [];
        $package = $config['package'] ?? null;

        if (is_array($package) && array_key_exists('kind', $package) && IconKind::fromConfig($package['kind']) === null) {
            $issues[] = ['check' => 'kind.invalid_value', 'key' => 'package.kind', 'value' => $package['kind']];
        }

        foreach (self::categories($config) as $slug => $category) {
            if (is_array($category) && array_key_exists('kind', $category) && IconKind::fromConfig($category['kind']) === null) {
                $issues[] = ['check' => 'kind.invalid_value', 'key' => "metadata.data.categories.{$slug}.kind", 'value' => $category['kind']];
            }
        }

        foreach (self::rawKindRules($config) as $index => $rule) {
            $match = is_array($rule) ? ($rule['match'] ?? null) : null;

            if (! is_string($match) || ! KindGlob::isValid($match)) {
                $issues[] = ['check' => 'kind.invalid_glob', 'key' => "metadata.data.kinds.rules.{$index}.match", 'value' => $match, 'index' => (int) $index, 'pattern' => is_string($match) ? $match : ''];

                continue;
            }

            if (IconKind::fromConfig($rule['kind'] ?? null) === null) {
                $issues[] = ['check' => 'kind.invalid_value', 'key' => "metadata.data.kinds.rules.{$index}.kind", 'value' => $rule['kind'] ?? null];
            }
        }

        foreach (self::section($config, 'items') as $path => $item) {
            $value = is_array($item) ? ($item['kind'] ?? null) : $item;

            if (IconKind::fromConfig($value) === null) {
                $issues[] = ['check' => 'kind.invalid_value', 'key' => "metadata.data.items.{$path}.kind", 'value' => $value];
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int|string, mixed>
     */
    private static function rawKindRules(array $config): array
    {
        $kinds = self::section($config, 'kinds');

        return is_array($kinds['rules'] ?? null) ? $kinds['rules'] : [];
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
        $section = is_array($data) ? ($data[$key] ?? null) : null;

        return is_array($section) ? $section : [];
    }
}
