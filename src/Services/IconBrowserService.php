<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use stdClass;
use ArrayAccess;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Simtabi\Laranail\Ichava\Models\Icon;
use Illuminate\Database\Query\JoinClause;
use Simtabi\Laranail\Ichava\Enums\IconKind;
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
        private IconCacheService $cacheManager,
        private IchavaLogger $logger,
    ) {}

    /**
     * Get filtered and paginated icons
     *
     * @param array<string, mixed> $filters
     *
     * @return LengthAwarePaginator<int, Icon>
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
        $search = $filters['search'] ?? null;

        if (! empty($search) && is_scalar($search)) {
            // The scope takes the search alone; a second argument (once a limit) was ignored.
            // A scalar is passed as the string the scope's `string` parameter coerced it to.
            $query = Icon::fuzzySearch((string) $search);
        }

        if (! empty($filters['packages'])) {
            $query->whereIn('package', $filters['packages']);
        }

        // Kind: icons or illustrations. A value that names no kind matches
        // nothing, so a bad query parameter cannot widen the result.
        $kind = $filters['kind'] ?? null;

        if ($kind instanceof IconKind || (is_string($kind) && $kind !== '')) {
            $query->ofKind($kind);
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

        // Apply sorting. The query builder accepts "asc" or "desc" in any case and
        // throws this exact exception for anything else.
        $direction = strtolower($sortDirection);

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException('Order direction must be a SortDirection, "asc" or "desc".');
        }

        $query->orderBy($sortBy, $direction);

        // Select base columns (relationships loaded separately)
        $query->select([
            'id', 'package', 'name', 'path', 'file_hash',
            'tags', 'keywords', 'attributes', 'metadata',
            'kind', 'theme_variants',
            'created_at', 'updated_at',
        ]);

        // Load relationships for categories and variants
        $query->with(['terms' => function ($q) {
            $q->select('ichava_icon_terms.id', 'type', 'slug', 'name');
        }]);

        // Paginate with timeout protection
        try {
            return $query->paginate($perPage, ['*'], 'page', $page);
            // @phpstan-ignore catch.neverThrown (kept as written: widening it to database errors would change what callers see)
        } catch (IchavaException $e) {
            $this->logger->error('Icon pagination failed: ' . $e->getMessage());

            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $page);
        }
    }

    /**
     * Transform icon model to API response format
     *
     * @return array<string, mixed>
     */
    public function transformIcon(Icon $icon): array
    {
        $iconData = [
            'id'          => $icon->id,
            'package'     => $icon->package,
            'name'        => $icon->name,
            'kind'        => $icon->kind->value,
            'themes'      => $icon->themes(),
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
     * Both forms carry the full root-relative path, so the pasted snippet renders
     * the exact icon that was copied.
     *
     * - Clean: the pack's own tag when the pack registered one,
     *   `<x-icon-sets-tabler-icon name="a-b" category="outline" />`, with the
     *   folders in `category` because the pack component reads a name containing
     *   a slash as a full path. Falls back to the generic form otherwise.
     * - Generic: `<x-ichava::icon name="ichava/icon-sets-tabler::outline/a-b" />`.
     *   It used to be `<x-ichava-icon name="icon-sets-tabler:outline/a-b" />`, a
     *   tag nothing registers holding a reference the registry refuses.
     */
    public function generateBladeComponent(Icon $icon, bool $useCleanSyntax = true, string $class = 'w-6 h-6'): string
    {
        $path = $this->fullIconPath($icon);
        $tag = $useCleanSyntax ? $this->packComponentTag($icon) : null;

        if ($tag === null) {
            return "<x-ichava::icon name=\"{$path}\" class=\"{$class}\" />";
        }

        $relative = substr($path, strpos($path, '::') + 2);
        $slash = strrpos($relative, '/');
        $category = $slash === false ? '' : ' category="' . substr($relative, 0, $slash) . '"';
        $leaf = $slash === false ? $relative : substr($relative, $slash + 1);

        return "<x-{$tag} name=\"{$leaf}\"{$category} class=\"{$class}\" />";
    }

    /**
     * Generate helper function code for an icon
     */
    public function generateHelperCode(Icon $icon, string $class = 'w-6 h-6'): string
    {
        return "{{ ichava('{$this->fullIconPath($icon)}')->class('{$class}') }}";
    }

    /**
     * The Blade tag a pack registered with `loadBladeComponent()`, read from Blade's
     * live alias map so a pack that registered none never gets an invented tag.
     */
    public function packComponentTag(Icon $icon): ?string
    {
        $slug = strtolower(substr($icon->package, (int) strrpos('/' . $icon->package, '/')));
        $alias = "{$slug}-icon";

        return array_key_exists($alias, Blade::getClassComponentAliases()) ? $alias : null;
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
        $rel = trim(Icon::registryKey((string) ($icon->path ?? '')), '/');

        if ($rel === '') {
            $rel = $icon->name;
        }

        return $icon->package . '::' . $rel;
    }

    /**
     * Group icons by a specified field
     *
     * @param Collection<array-key, mixed> $icons
     * @param string $groupBy (package|category|name)
     *
     * @return array<array-key, list<mixed>>
     */
    public function groupIcons(Collection $icons, string $groupBy = 'package'): array
    {
        $grouped = [];

        foreach ($icons as $icon) {
            $readable = is_array($icon) || $icon instanceof ArrayAccess;

            $key = match ($groupBy) {
                'package'  => $readable ? ($icon['package'] ?? 'Unknown') : 'Unknown',
                'category' => $readable ? ($icon['category'] ?? 'Uncategorized') : 'Uncategorized',
                default    => $readable ? ($icon['package'] ?? 'Unknown') : 'Unknown', // Default to package grouping
            };

            if (! is_int($key) && ! is_string($key)) {
                $key = $groupBy === 'category' ? 'Uncategorized' : 'Unknown';
            }

            if (! isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $icon;
        }

        return $grouped;
    }

    /**
     * Get filter options (packages, categories, variants)
     *
     * @return array<array-key, mixed>
     */
    public function getFilters(): array
    {
        $filters = $this->cacheManager->remember('browser.filters', function () {
            // Check if database has any icons
            if (! $this->hasIcons()) {
                return [
                    'packages'   => [],
                    'categories' => [],
                    'variants'   => [],
                    'kinds'      => $this->kindFacet([]),
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
                ->map(fn (mixed $count): int => is_numeric($count) ? (int) $count : 0)
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
                'kinds'      => $this->kindFacet($this->kindCounts()),
                'empty'      => false,
            ];
        }, expects: 'array');

        return is_array($filters) ? $filters : [];
    }

    /**
     * Get statistics for the browser
     *
     * @return array<array-key, mixed>
     */
    public function getStatistics(): array
    {
        $statistics = $this->cacheManager->remember('browser.statistics', function () {
            // Check if database has any icons
            if (! $this->hasIcons()) {
                return [
                    'total' => [
                        'icons'      => 0,
                        'packages'   => $this->registry->count(),
                        'categories' => 0,
                        'variants'   => 0,
                    ],
                    'by_kind' => array_fill_keys(array_column(IconKind::cases(), 'value'), 0),
                    'empty'   => true,
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
                // Every kind is present, zero included, so a client can tell
                // "no illustrations installed" from "field missing".
                'by_kind' => array_merge(
                    array_fill_keys(array_column(IconKind::cases(), 'value'), 0),
                    $this->kindCounts(),
                ),
                'empty' => false,
            ];
        }, expects: 'array');

        return is_array($statistics) ? $statistics : [];
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
        $tree = $this->cacheManager->remember('browser.tree', function (): array {
            if (! $this->hasIcons()) {
                return [];
            }

            $iconMorphAlias = (new Icon)->getMorphClass();

            // One row per (package, term) with the number of icons carrying it.
            $rows = DB::table('ichava_icon_terms')
                ->join('ichava_icon_termables', 'ichava_icon_termables.term_id', '=', 'ichava_icon_terms.id')
                ->join('ichava_icons', function (JoinClause $join) use ($iconMorphAlias): void {
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
                ->map(fn (mixed $count): int => is_numeric($count) ? (int) $count : 0)
                ->all();

            // Variants per category: icons carrying BOTH terms. A pack-wide
            // variant count would put every variant under every category, and
            // report icons under a category that none of its icons belong to.
            $variantsByCategory = DB::table('ichava_icon_termables as tc')
                ->join('ichava_icon_terms as c', 'c.id', '=', 'tc.term_id')
                ->join('ichava_icon_termables as tv', function (JoinClause $join): void {
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
                    $rowId = $row->id;

                    $variants = collect(is_int($rowId) || is_string($rowId) ? $variantsByCategory->get($rowId, []) : [])

                        ->where('slug', '!=', $row->slug)
                        ->filter(fn (mixed $variant): bool => $variant instanceof stdClass)
                        ->map(fn (stdClass $variant): array => [
                            'slug'  => $variant->slug,
                            'name'  => $variant->name,
                            'count' => is_numeric($variant->icon_count) ? (int) $variant->icon_count : 0,
                        ])

                        ->sortBy('name')
                        ->values()
                        ->all();

                    $categories[] = array_filter([
                        'name'  => $row->slug,
                        'label' => $row->name,
                        'kind'  => $this->registry->kindFor((string) $package, is_string($row->slug) ? $row->slug : null)->value,
                        'count' => is_numeric($row->icon_count) ? (int) $row->icon_count : 0,
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

                usort($categories, fn (array $a, array $b): int => strcasecmp(self::labelOf($a), self::labelOf($b)));

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
                    'kind'           => $this->registry->kindFor((string) $package)->value,
                    'cats'           => $categories,
                ];
            }

            usort($tree, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

            return $tree;
        }, expects: 'array');

        if (! is_array($tree)) {
            return [];
        }

        $nodes = [];

        foreach ($tree as $node) {
            if (self::isTreeNode($node)) {
                $nodes[] = $node;
            }
        }

        return $nodes;
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
     * Whether a cached tree entry has the shape `buildIconTree()` promises.
     *
     * @phpstan-assert-if-true array{pack: string, label: string, count: int, icon_count: int, cats: list<array<string, mixed>>} $node
     */
    private static function isTreeNode(mixed $node): bool
    {
        if (! is_array($node)
            || ! is_string($node['pack'] ?? null)
            || ! is_string($node['label'] ?? null)
            || ! is_int($node['count'] ?? null)
            || ! is_int($node['icon_count'] ?? null)
            || ! is_array($node['cats'] ?? null)
            || ! array_is_list($node['cats'])) {
            return false;
        }

        foreach ($node['cats'] as $cat) {
            if (! is_array($cat)) {
                return false;
            }

            foreach (array_keys($cat) as $key) {
                if (! is_string($key)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A tree node's `label`, or '' when the node has none.
     *
     * @param array<array-key, mixed> $node
     */
    private static function labelOf(array $node): string
    {
        return isset($node['label']) && is_string($node['label']) ? $node['label'] : '';
    }

    /**
     * Seeded icons per kind, keyed by kind value. Kinds with no rows are absent.
     *
     * @return array<string, int>
     */
    private function kindCounts(): array
    {
        $counts = [];

        foreach (DB::table(Icon::TABLE)->selectRaw('kind, COUNT(*) as icon_count')->groupBy('kind')->get() as $row) {
            if (is_string($row->kind ?? null)) {
                $counts[$row->kind] = is_numeric($row->icon_count) ? (int) $row->icon_count : 0;
            }
        }

        return $counts;
    }

    /**
     * The kind facet: every kind, in declaration order, with its count.
     *
     * Every kind is listed even at zero. Whether to show an empty kind is the
     * client's decision (the browser hides Illustrations until a set is
     * installed); the data says what exists either way.
     *
     * @param array<string, int> $counts
     *
     * @return list<array{name: string, label: string, count: int}>
     */
    private function kindFacet(array $counts): array
    {
        return array_map(static fn (IconKind $kind): array => [
            'name'  => $kind->value,
            'label' => $kind->label(),
            'count' => $counts[$kind->value] ?? 0,
        ], IconKind::cases());
    }

    /**
     * Display labels for every registered pack, keyed by package name.
     *
     * @return array<string, string>
     */
    private function getPackageLabels(): array
    {
        return collect($this->registry->all())
            ->map(fn (array $package, string $key): string => isset($package['name']) && is_string($package['name']) ? $package['name'] : $key)

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
