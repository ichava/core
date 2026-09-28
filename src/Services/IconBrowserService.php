<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Icon Browser Service
 *
 * Centralized service for all icon browser operations.
 * Handles icon listing, filtering, tree building, and statistics.
 */
final class IconBrowserService
{
    public function __construct(
        private IconRegistry $registry,
        private IconDiscoveryService $discoveryService,
        private IconCacheService $cacheManager,
        private IchavaLogger $logger,
    ) {}

    /**
     * Get filtered and paginated icons
     */
    public function getIcons(
        array $filters = [],
        int $page = 1,
        int $perPage = 60,
        string $sortBy = 'name',
        string $sortDirection = 'asc',
    ): LengthAwarePaginator {
        // Return empty result if database is empty
        if (! $this->hasIcons()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $page);
        }

        $query = Icon::query();

        /*
         * Search NARROWS the filtered set; it does not replace it.
         *
         * These filters used to sit in an `else` branch behind `if (search)`, under the
         * comment "Apply filters only when not searching". Selecting a package and then
         * typing a query returned matches from every package -- the filter was not
         * outranked by a better match, it was discarded. The same held for category and
         * variant. It was easy to miss because each half works in isolation: filtering
         * alone is correct, and searching alone is correct.
         */
        if (! empty($filters['search'])) {
            $query = Icon::fuzzySearch($filters['search'], 10000);
        }

        if (! empty($filters['packages'])) {
            $query->whereIn('package', $filters['packages']);
        }

        // Categories - filter by terms relationship
        if (! empty($filters['categories'])) {
            $query->whereHas('terms', function ($q) use ($filters) {
                $q->where('type', 'category')
                    ->whereIn('slug', $filters['categories']);
            });
        }

        // Variants - filter by terms relationship
        if (! empty($filters['variants'])) {
            $query->whereHas('terms', function ($q) use ($filters) {
                $q->where('type', 'variant')
                    ->whereIn('slug', $filters['variants']);
            });
        }

        // Apply sorting
        $query->orderBy($sortBy, $sortDirection);

        // Select base columns (relationships loaded separately)
        $query->select([
            'id', 'package', 'name', 'path', 'file_hash',
            'tags', 'keywords', 'attributes', 'metadata',
            'created_at', 'updated_at',
        ]);

        // Load relationships for categories and variants
        $query->with(['terms' => function ($q) {
            $q->select('ichava_icon_terms.id', 'type', 'slug', 'name');
        }]);

        // Paginate with timeout protection
        try {
            return $query->paginate($perPage, ['*'], 'page', $page);
        } catch (IchavaException $e) {
            $this->logger->error('Icon pagination failed: ' . $e->getMessage());

            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $page);
        }
    }

    /**
     * Transform icon model to API response format
     */
    public function transformIcon(Icon $icon): array
    {
        $iconData = [
            'id'          => $icon->id,
            'package'     => $icon->package,
            'name'        => $icon->name,
            'category'    => $icon->primary_category?->slug,
            'variant'     => $icon->primary_variant?->slug,
            'path'        => $icon->icon_path,
            'svg_content' => $icon->svg_content,
            // The SVG endpoint lives in the browser package's (optionally disabled)
            // REST API. Fall back to null when those routes are not registered —
            // Inertia pages already carry `svg_content`, so tiles render anyway.
            'svg_url' => Route::has('ichava.api.icons.svg')
                ? route('ichava.api.icons.svg', ['id' => $icon->id], false)
                : null,
            'viewbox'   => $icon->viewbox,
            'width'     => $icon->width,
            'height'    => $icon->height,
            'icon_path' => $icon->icon_path,
            'file_path' => $icon->path ?? '',
            'set'       => $icon->package,
        ];

        // Generate Blade component syntax server-side
        $iconData['blade_clean'] = $this->generateBladeComponent($icon, true);
        $iconData['blade_generic'] = $this->generateBladeComponent($icon, false);
        $iconData['helper'] = $this->generateHelperCode($icon);

        return $iconData;
    }

    /**
     * Generate Blade component syntax for an icon.
     *
     * The name carries the full root-relative path (variant + nested folders
     * when the icon lives in subdirectories, plain name otherwise), so the
     * pasted snippet renders the exact icon that was copied:
     * `<x-ichava::icon name="ichava/icon-sets-tabler::outline/a-b" />`.
     */
    public function generateBladeComponent(Icon $icon, bool $useCleanSyntax = true): string
    {
        if (! $useCleanSyntax) {
            return "<x-ichava-icon name=\"{$this->shortIconRef($icon)}\" class=\"w-6 h-6\" />";
        }

        return "<x-ichava::icon name=\"{$this->fullIconPath($icon)}\" class=\"w-6 h-6\" />";
    }

    /**
     * Generate helper function code for an icon
     */
    public function generateHelperCode(Icon $icon): string
    {
        return "{{ ichava('{$this->fullIconPath($icon)}')->class('w-6 h-6') }}";
    }

    /**
     * Full renderable path: `package::variant/...folders.../name`.
     *
     * Derived from the stored file path (filesystem truth), not from terms:
     * `files/outline/a-b.svg` → `ichava/icon-sets-tabler::outline/a-b`,
     * `files/a-b.svg` → `ichava/icon-sets-tabler::a-b`. Matches the
     * PathResolver grammar the renderer resolves.
     */
    public function fullIconPath(Icon $icon): string
    {
        $rel = (string) ($icon->path ?? '');
        $rel = preg_replace('#^files/#', '', $rel) ?? '';
        $rel = preg_replace('#\.svg$#i', '', $rel) ?? '';
        $rel = trim($rel, '/');

        if ($rel === '') {
            $rel = $icon->name;
        }

        return $icon->package . '::' . $rel;
    }

    /**
     * Short `set:path` ref used by the generic/Livewire/Alpine snippets.
     * Mirrors the client's iconRef contract (short set + full segments).
     */
    public function shortIconRef(Icon $icon): string
    {
        $short = preg_replace('#^ichava/#', '', $icon->package) ?? $icon->package;
        $short = preg_replace('/-icons$/', '', $short) ?? $short;

        $rel = (string) ($icon->path ?? '');
        $rel = preg_replace('#^files/#', '', $rel) ?? '';
        $rel = preg_replace('#\.svg$#i', '', $rel) ?? '';
        $rel = trim($rel, '/');

        if ($rel === '') {
            $rel = $icon->name;
        }

        return $short . ':' . $rel;
    }

    /**
     * Group icons by a specified field
     *
     * @param string $groupBy (package|category|name)
     */
    public function groupIcons(Collection $icons, string $groupBy = 'package'): array
    {
        $grouped = [];

        foreach ($icons as $icon) {
            $key = match ($groupBy) {
                'package'  => $icon['package'] ?? 'Unknown',
                'category' => $icon['category'] ?? 'Uncategorized',
                default    => $icon['package'] ?? 'Unknown', // Default to package grouping
            };

            if (! isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $icon;
        }

        return $grouped;
    }

    /**
     * Get filter options (packages, categories, variants)
     */
    public function getFilters(): array
    {
        return $this->cacheManager->remember('browser.filters', function () {
            // Check if database has any icons
            if (! $this->hasIcons()) {
                return [
                    'packages'   => [],
                    'categories' => [],
                    'variants'   => [],
                    'empty'      => true,
                ];
            }

            $packages = $this->registry->all();

            // The count is the number of icons SEEDED for the pack, not the
            // number of SVG files the pack ships: a pack that is registered but
            // not yet seeded reads 0 instead of its file count, and one whose
            // files are absent from disk still reports its seeded icons.
            $iconCounts = DB::table('ichava_icons')
                ->selectRaw('package, COUNT(*) as icon_count')
                ->groupBy('package')
                ->pluck('icon_count', 'package')
                ->map(fn ($count) => (int) $count)
                ->all();

            $transformedPackages = collect($packages)->map(function ($pkg, $key) use ($iconCounts) {
                return [
                    'name'        => $key,
                    'label'       => $pkg['name'] ?? $key,
                    'count'       => $iconCounts[$key] ?? 0,
                    'description' => $pkg['description'] ?? '',
                    'vendor'      => $pkg['vendor'] ?? '',
                ];
            })->values()->toArray();

            // Get the morph alias for Icon model (registered as 'icon' in morphMap)
            $iconMorphAlias = (new Icon)->getMorphClass();

            // Get categories from terms table
            $categories = DB::table('ichava_icon_termables')
                ->join('ichava_icon_terms', 'ichava_icon_termables.term_id', '=', 'ichava_icon_terms.id')
                ->where('ichava_icon_terms.type', 'category')
                ->where('ichava_icon_termables.termable_type', $iconMorphAlias)
                ->select('ichava_icon_terms.slug', 'ichava_icon_terms.name')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('ichava_icon_terms.slug', 'ichava_icon_terms.name')
                ->get()
                ->map(function ($category) {
                    return [
                        'name'  => $category->slug,
                        'label' => $category->name,
                        'count' => $category->count,
                    ];
                })->values()->toArray();

            // Get variants from terms table
            $variants = DB::table('ichava_icon_termables')
                ->join('ichava_icon_terms', 'ichava_icon_termables.term_id', '=', 'ichava_icon_terms.id')
                ->where('ichava_icon_terms.type', 'variant')
                ->where('ichava_icon_termables.termable_type', $iconMorphAlias)
                ->select('ichava_icon_terms.slug', 'ichava_icon_terms.name')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('ichava_icon_terms.slug', 'ichava_icon_terms.name')
                ->get()
                ->map(function ($variant) {
                    return [
                        'name'  => $variant->slug,
                        'label' => $variant->name,
                        'count' => $variant->count,
                    ];
                })->values()->toArray();

            return [
                'packages'   => $transformedPackages,
                'categories' => $categories,
                'variants'   => $variants,
                'empty'      => false,
            ];
        });
    }

    /**
     * Get statistics for the browser
     */
    public function getStatistics(): array
    {
        return $this->cacheManager->remember('browser.statistics', function () {
            // Check if database has any icons
            if (! $this->hasIcons()) {
                return [
                    'total' => [
                        'icons'      => 0,
                        'packages'   => $this->registry->count(),
                        'categories' => 0,
                        'variants'   => 0,
                    ],
                    'empty' => true,
                ];
            }

            // Use optimized single query with joins for terms
            $totalIcons = Icon::count();
            $totalPackages = Icon::distinct('package')->count();

            // Count categories from terms
            $totalCategories = DB::table('ichava_icon_terms')
                ->where('type', 'category')
                ->count();

            // Count variants from terms
            $totalVariants = DB::table('ichava_icon_terms')
                ->where('type', 'variant')
                ->count();

            return [
                'total' => [
                    'icons'      => $totalIcons,
                    'packages'   => $totalPackages,
                    'categories' => $totalCategories,
                    'variants'   => $totalVariants,
                ],
                'empty' => false,
            ];
        });
    }

    /**
     * Build the pack → category → variant tree, read from the database.
     *
     * The taxonomy is what the filters already filter on, so the tree and the
     * facets cannot disagree. It used to be built by walking each pack's
     * `base_path`, which produced the *directory* layout rather than the
     * taxonomy: every pack stores its icons under a literal `files/` folder, so
     * the top level read `files` and the real categories sat one level deeper
     * under it. It also counted icons by scanning the disk, so a pack reported
     * twice its icons (the recursive folder count already included the children
     * whose counts were then added on top of it), and a pack whose files were
     * missing from disk disappeared entirely even with its icons seeded.
     *
     * @return list<array{pack: string, label: string, count: int, icon_count: int, cats: list<array<string, mixed>>}>
     */
    public function buildIconTree(): array
    {
        return $this->cacheManager->remember('browser.tree', function (): array {
            if (! $this->hasIcons()) {
                return [];
            }

            $iconMorphAlias = (new Icon)->getMorphClass();

            // One row per (package, term) with the number of icons carrying it.
            $rows = DB::table('ichava_icon_terms')
                ->join('ichava_icon_termables', 'ichava_icon_termables.term_id', '=', 'ichava_icon_terms.id')
                ->join('ichava_icons', function ($join) use ($iconMorphAlias) {
                    $join->on('ichava_icon_termables.termable_id', '=', 'ichava_icons.id')
                        ->where('ichava_icon_termables.termable_type', '=', $iconMorphAlias);
                })
                ->whereIn('ichava_icon_terms.type', ['category', 'variant'])
                ->select([
                    'ichava_icons.package',
                    'ichava_icon_terms.id',
                    'ichava_icon_terms.type',
                    'ichava_icon_terms.slug',
                    'ichava_icon_terms.name',
                ])
                ->selectRaw('COUNT(*) as icon_count')
                ->groupBy(
                    'ichava_icons.package',
                    'ichava_icon_terms.id',
                    'ichava_icon_terms.type',
                    'ichava_icon_terms.slug',
                    'ichava_icon_terms.name',
                )
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $iconTotals = DB::table('ichava_icons')
                ->selectRaw('package, COUNT(*) as icon_count')
                ->groupBy('package')
                ->pluck('icon_count', 'package')
                ->map(fn ($count) => (int) $count)
                ->all();

            // Variants per category: icons carrying BOTH terms. A pack-wide
            // variant count would put every variant under every category, and
            // report icons under a category that none of its icons belong to.
            $variantsByCategory = DB::table('ichava_icon_termables as tc')
                ->join('ichava_icon_terms as c', 'c.id', '=', 'tc.term_id')
                ->join('ichava_icon_termables as tv', function ($join) {
                    $join->on('tv.termable_id', '=', 'tc.termable_id')
                        ->on('tv.termable_type', '=', 'tc.termable_type');
                })
                ->join('ichava_icon_terms as v', 'v.id', '=', 'tv.term_id')
                ->where('tc.termable_type', $iconMorphAlias)
                ->where('c.type', 'category')
                ->where('v.type', 'variant')
                ->select(['tc.term_id as category_id', 'v.slug', 'v.name'])
                ->selectRaw('COUNT(*) as icon_count')
                ->groupBy('tc.term_id', 'v.slug', 'v.name')
                ->get()
                ->groupBy('category_id');

            $labels = $this->getPackageLabels();

            $tree = [];

            foreach ($rows->groupBy('package') as $package => $packageRows) {
                $categories = [];

                foreach ($packageRows->where('type', 'category') as $row) {
                    $variants = collect($variantsByCategory->get($row->id, []))
                        ->where('slug', '!=', $row->slug)
                        ->map(fn ($variant) => [
                            'slug'  => $variant->slug,
                            'name'  => $variant->name,
                            'count' => (int) $variant->icon_count,
                        ])
                        ->sortBy('name')
                        ->values()
                        ->all();

                    $categories[] = array_filter([
                        'name'  => $row->slug,
                        'label' => $row->name,
                        'count' => (int) $row->icon_count,
                        // Only present when the category actually has variants
                        // of its own; a pack whose variant slugs are the same as
                        // its categories (tabler: outline/filled) gains nothing
                        // from a second, identical level.
                        'sub' => $variants === [] ? null : $variants,
                    ], fn ($value) => $value !== null);
                }

                if ($categories === []) {
                    continue;
                }

                usort($categories, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

                $iconCount = $iconTotals[$package] ?? 0;

                $tree[] = [
                    'pack'  => $package,
                    'label' => $labels[$package] ?? $package,
                    // The pack badge in the browser reads `count`, and it has
                    // always shown the pack's ICON count, so it stays the icon
                    // count. `category_count` is the number of facets below it.
                    'count'          => $iconCount,
                    'icon_count'     => $iconCount,
                    'category_count' => count($categories),
                    'cats'           => $categories,
                ];
            }

            usort($tree, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

            return $tree;
        });
    }

    /**
     * Clear all browser-related caches
     */
    public function clearCache(): void
    {
        $this->cacheManager->forget('browser.filters');
        $this->cacheManager->forget('browser.statistics');
        $this->cacheManager->forget('browser.tree');
    }

    /**
     * Display labels for every registered pack, keyed by package name.
     *
     * @return array<string, string>
     */
    private function getPackageLabels(): array
    {
        return collect($this->registry->all())
            ->map(fn (array $package, string $key): string => $package['name'] ?? $key)
            ->all();
    }

    /**
     * Check if the database has any icons.
     *
     * No memoization: a `static` cache here would persist for the lifetime of
     * the PHP process, leaking across requests in Octane / queue workers and
     * causing the API to permanently report "empty" once it's seen an empty
     * DB. The underlying query is a single indexed `EXISTS` so the perf
     * saving from memoization is negligible.
     */
    private function hasIcons(): bool
    {
        return Icon::query()->exists();
    }
}
