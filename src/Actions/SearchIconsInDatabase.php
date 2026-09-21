<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Actions;

use Closure;
use Simtabi\Laranail\Ichava\Models\Icon;
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

        return $this->cache->remember($cacheKey, fn (): array => $this->execute(
            $query,
            $packages,
            $categories,
            $page,
            $perPage,
            $sortBy,
            $sortDirection,
            $packageMetadata,
            $usageSyntax,
        ));
    }

    /**
     * @param list<string> $packages
     * @param list<string> $categories
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

        if ($categories !== []) {
            $builder->whereIn('category', $categories);
        }

        $total = $builder->count();

        $icons = $builder
            ->orderBy($sortBy, $sortDirection)
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $allPackages = $packageMetadata ? $packageMetadata() : [];

        $items = $icons->map(function (Icon $icon) use ($allPackages, $usageSyntax): array {
            $meta = $allPackages[$icon->package] ?? [];

            return [
                'package'      => $icon->package,
                'package_name' => $meta['name'] ?? $icon->package,
                'set'          => $icon->package,
                'name'         => $icon->name,
                'category'     => $icon->category,
                'variant'      => $icon->variant,
                'path'         => $icon->path,
                // The model's own attribute. `getIconPath()` belongs to
                // IconDriverInterface and has never existed on Icon.
                'icon_path' => $icon->icon_path,
                'syntax'    => $usageSyntax
                    ? $usageSyntax($icon->package, $icon->name, $icon->variant)
                    : [],
                'svg_content' => null, // Deferred rendering.
            ];
        })->values()->all();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / max($perPage, 1)),
        ];
    }
}
