<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Enums\KindSource;
use Simtabi\Laranail\Ichava\Data\KindResolution;
use Simtabi\Laranail\Ichava\Services\IconRegistry;

/**
 * The one place an item's kind is decided (D6, contract §1.3).
 *
 * Rows, in order; the first that yields a valid kind wins:
 *
 * 1. host override, `ichava.ichava-core.kinds.overrides` -- exact keys first,
 *    then glob keys in declared order;
 * 2. item, the pack's `items.<item path>.kind`;
 * 3. rule, the first of the pack's `kinds.rules[]` whose `match` matches;
 * 4. category, the pack's `categories.<slug>.kind`;
 * 5. pack, `package.kind`;
 * 6. default, `icon`.
 *
 * Pure and in memory: it reads what the registry stored at registration and
 * the host config, never the disk, so the seeder, the watcher, the renderer
 * and doctor all get the same answer for the same item.
 */
final readonly class KindResolver
{
    public function __construct(
        private IconRegistry $registry,
    ) {}

    /**
     * An item path from a stored path: the part after `files/`, forward
     * slashes, `.svg` stripped, on-disk case.
     *
     * `files/illustrations/office.svg` → `illustrations/office`.
     */
    public static function itemPath(string $storedPath): string
    {
        $path = ltrim(str_replace('\\', '/', $storedPath), '/');
        $segments = explode('/', $path);
        $files = array_search('files', $segments, true);

        if ($files !== false && isset($segments[$files + 1])) {
            $path = implode('/', array_slice($segments, $files + 1));
        }

        return preg_replace('/\.svg\z/i', '', $path) ?? $path;
    }

    /**
     * The category an item files under: its first folder, slugged, or null
     * for an item at the root of the pack. The same rule as
     * {@see IconClassifier::categoryOf()} applied to the item path.
     */
    public static function categoryOf(string $itemPath): ?string
    {
        return str_contains($itemPath, '/') ? Str::slug(Str::before($itemPath, '/')) : null;
    }

    public function resolve(string $package, string $itemPath): KindResolution
    {
        foreach ($this->overrides() as $override) {
            if ($override['kind'] !== null
                && ($override['package'] === '*' || $override['package'] === $package)
                && KindGlob::matches($override['pattern'], $itemPath)) {
                return new KindResolution($override['kind'], KindSource::Host, $override['key']);
            }
        }

        $metadata = $this->registry->all()[$package] ?? [];

        $items = is_array($metadata['item_kinds'] ?? null) ? $metadata['item_kinds'] : [];
        $kind = IconKind::fromConfig($items[$itemPath] ?? null);

        if ($kind !== null) {
            return new KindResolution($kind, KindSource::Item);
        }

        foreach (is_array($metadata['kind_rules'] ?? null) ? $metadata['kind_rules'] : [] as $rule) {
            $match = is_array($rule) ? ($rule['match'] ?? null) : null;
            $kind = is_array($rule) ? IconKind::fromConfig($rule['kind'] ?? null) : null;

            if (is_string($match) && $kind !== null && KindGlob::matches($match, $itemPath)) {
                return new KindResolution($kind, KindSource::Rule, $match);
            }
        }

        $category = self::categoryOf($itemPath);
        $categories = is_array($metadata['category_kinds'] ?? null) ? $metadata['category_kinds'] : [];
        $kind = $category === null ? null : IconKind::fromConfig($categories[$category] ?? null);

        if ($kind !== null) {
            return new KindResolution($kind, KindSource::Category, $category);
        }

        // A pack registered from its config.json records what it declared; one
        // registered by hand only has the effective `kind`.
        $declared = array_key_exists('declared_kind', $metadata) ? $metadata['declared_kind'] : ($metadata['kind'] ?? null);
        $kind = IconKind::fromConfig($declared);

        return $kind !== null
            ? new KindResolution($kind, KindSource::Pack)
            : new KindResolution(IconKind::Icon, KindSource::Default);
    }

    /**
     * The host's valid override keys, in the order they are checked: exact
     * keys (a named package and a pattern with no wildcard) first, then glob
     * keys in declared order. `kind` is null when the value names no kind;
     * such an override is skipped, and doctor reports it.
     *
     * @return list<array{key: string, package: string, pattern: string, kind: IconKind|null, value: mixed}>
     */
    public function overrides(): array
    {
        $exact = [];
        $globs = [];

        foreach ($this->declaredOverrides() as $key => $value) {
            $parsed = self::parseOverrideKey((string) $key);

            if ($parsed === null) {
                continue;
            }

            $entry = ['key' => (string) $key, ...$parsed, 'kind' => IconKind::fromConfig($value), 'value' => $value];

            if ($parsed['package'] !== '*' && KindGlob::isLiteral($parsed['pattern'])) {
                $exact[] = $entry;
            } else {
                $globs[] = $entry;
            }
        }

        return [...$exact, ...$globs];
    }

    /**
     * Override keys that cannot be read: no `::`, an empty package, or an
     * invalid pattern. Ignored by resolution; doctor reports them.
     *
     * @return list<string>
     */
    public function invalidOverrideKeys(): array
    {
        $invalid = [];

        foreach (array_keys($this->declaredOverrides()) as $key) {
            if (self::parseOverrideKey((string) $key) === null) {
                $invalid[] = (string) $key;
            }
        }

        return $invalid;
    }

    /**
     * @return array{package: string, pattern: string}|null
     */
    private static function parseOverrideKey(string $key): ?array
    {
        if (! str_contains($key, '::')) {
            return null;
        }

        [$package, $pattern] = explode('::', $key, 2);

        if ($package === '' || ! KindGlob::isValid($pattern)) {
            return null;
        }

        return ['package' => $package, 'pattern' => $pattern];
    }

    /** @return array<array-key, mixed> */
    private function declaredOverrides(): array
    {
        $overrides = config('ichava.ichava-core.kinds.overrides', []);

        return is_array($overrides) ? $overrides : [];
    }
}
