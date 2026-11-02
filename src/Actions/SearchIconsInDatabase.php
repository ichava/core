<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Actions;

use Closure;
use InvalidArgumentException;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Simtabi\Laranail\Ichava\Services\IconCacheService;

/**
 * Search the icons table, paginate, and shape the result.
 *
 * Extracted because this is where the defects were. The query was inlined here
 * as raw PostgreSQL full-text SQL with no driver branch, so it threw on SQLite,
 * MySQL and MariaDB the moment the table existed -- while `Icon::scopeSearch()`
 * had carried the driver decision and the portable LIKE fallback all along. The
 * transform then called `getIconPath()`, declared on `IconDriverInterface` and
 * never present on the model, so the database path also threw on PostgreSQL as
 * soon as a row matched.
 *
 * Neither was a typo. Both followed from query construction living in two
 * places, only one of which was driver-aware, inside a class already holding
 * six other concerns. One query builder is the fix; this class is where it goes.
 *
 * Enrichment arrives as closures rather than a collaborator, because the
 * package metadata and usage-syntax lookups live on `IconDiscoveryService` and
 * injecting it back would be a cycle. Both are optional: the action returns a
 * correct, unenriched result without them, which is what makes it testable on
 * its own.
 */
final class SearchIconsInDatabase
{
    public function __construct(
        private IconCacheService $cache,
    ) {}

    /**
     * @param list<string> $packages
     * @param list<string> $categories
     * @param (Closure(): array<string, mixed>)|null $packageMetadata
     * @param (Closure(string, string, ?string): array<string, mixed>)|null $usageSyntax
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function __invoke(
        string $query = '',
        array $packages = [],
        array $categories = [],
        int $page = 1,
        int $perPage = 60,
        string $sortBy = 'name',
        string $sortDirection = 'asc',
        ?Closure $packageMetadata = null,
        ?Closure $usageSyntax = null,
    ): array {
        // The generation is part of the key, so a clear retires every variant
        // at once without enumerating the md5 suffixes.
        $cacheKey = 'icons.search.db.' . ClearDiscoveryCaches::generation() . '.' . md5(serialize([
            $query, $packages, $categories, $page, $perPage, $sortBy, $sortDirection,
        ]));

        $build = fn (): array => $this->execute(
            $query,
            $packages,
            $categories,
            $page,
            $perPage,
            $sortBy,
            $sortDirection,
            $packageMetadata,
            $usageSyntax,
        );

        $cached = $this->cache->remember($cacheKey, $build, expects: 'array');

        // The store is untrusted input: `expects` proves an array came back,
        // not that it is a page. Anything else is rebuilt rather than served.
        return self::isPage($cached) ? $cached : $build();
    }

    /**
     * Whether a value has the shape `__invoke()` returns.
     *
     * @phpstan-assert-if-true array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int} $value
     */
    private static function isPage(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (['total', 'page', 'per_page', 'last_page'] as $key) {
            if (! is_int($value[$key] ?? null)) {
                return false;
            }
        }

        $items = $value['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item) || array_filter(array_keys($item), is_int(...)) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The sort direction in the form the query builder accepts.
     *
     * Same rule and message as Laravel's own `orderBy()`, which this used to
     * reach with the raw string.
     *
     * @return 'asc'|'desc'
     */
    private static function direction(string $direction): string
    {
        return match (strtolower($direction)) {
            'asc'   => 'asc',
            'desc'  => 'desc',
            default => throw new InvalidArgumentException('Order direction must be "asc" or "desc".'),
        };
    }

    /**
     * @param list<string> $packages
     * @param list<string> $categories
     * @param (Closure(): array<string, mixed>)|null $packageMetadata
     * @param (Closure(string, string, ?string): array<string, mixed>)|null $usageSyntax
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    private function execute(
        string $query,
        array $packages,
        array $categories,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection,
        ?Closure $packageMetadata,
        ?Closure $usageSyntax,
    ): array {
        $builder = Icon::query();

        // The model owns the query. `scopeSearch()` branches on the driver and
        // falls back to a portable LIKE; rebuilding it here is what broke three
        // drivers last time.
        if ($query !== '') {
            $builder->search($query);
        }

        if ($packages !== []) {
            $builder->whereIn('package', $packages);
        }

        // Categories are terms, not a column: `whereIn('category', ...)` named a column the
        // icons table has never had, so every category filter failed with an SQL error.
        if ($categories !== []) {
            $builder->whereHas('categories', fn ($terms) => $terms->whereIn('slug', $categories));
        }

        $total = $builder->count();

        $icons = $builder
            ->with('terms')
            ->orderBy($sortBy, self::direction($sortDirection))
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $allPackages = $packageMetadata ? $packageMetadata() : [];

        $items = array_values($icons->map(function (Icon $icon) use ($allPackages, $usageSyntax): array {
            $meta = $allPackages[$icon->package] ?? [];
            $packageName = is_array($meta) ? ($meta['name'] ?? null) : null;
            // Read from the eager-loaded terms. `$icon->category` and `$icon->variant` are
            // not attributes, so every result carried null for both.
            $category = $icon->terms->firstWhere('type', IconTerm::TYPE_CATEGORY)?->slug;
            $variant = $icon->terms->firstWhere('type', IconTerm::TYPE_VARIANT)?->slug;

            return [
                'package'      => $icon->package,
                'package_name' => $packageName ?? $icon->package,
                'set'          => $icon->package,
                'name'         => $icon->name,
                'category'     => $category,
                'variant'      => $variant,
                'path'         => $icon->path,
                // The model's own attribute. `getIconPath()` belongs to
                // IconDriverInterface and has never existed on Icon.
                'icon_path' => $icon->icon_path,
                'syntax'    => $usageSyntax
                    ? $usageSyntax($icon->package, $icon->name, $variant)
                    : [],
                'svg_content' => null, // Deferred rendering.
            ];
        })->all());

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max($perPage, 1)),
        ];
    }
}
