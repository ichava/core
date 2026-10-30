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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Data\VariantData;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
        private DatabaseOperationsService $database,
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

        $query = $this->filteredQuery($filters);

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

        // Load relationships for categories and variants. `parent_id` tells a
        // nested category from a top-level one, which is the icon's `sub`.
        $query->with(['terms' => function ($q) {
            $q->select('ichava_icon_terms.id', 'type', 'slug', 'name', 'parent_id');
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
            'sub'         => $icon->primary_subcategory?->slug,
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
     * Get filter options (packages, categories, variants, kinds), with counts.
     *
     * Without filters the answer is corpus-wide and cached. With filters each
     * facet is counted over the icons the OTHER filters select (a facet does
     * not narrow itself), so ticking a category still shows its siblings'
     * counts while the variant and kind counts follow the selection.
     *
     * @param array<string, mixed> $filters The filters getIcons() takes
     *
     * @return array<array-key, mixed>
     */
    public function getFilters(array $filters = []): array
    {
        if (self::hasFilters($filters)) {
            return $this->hasIcons() ? $this->buildFilters($filters) : $this->emptyFilters();
        }

        $cached = $this->cacheManager->remember(
            'browser.filters',
            fn (): array => $this->hasIcons() ? $this->buildFilters([]) : $this->emptyFilters(),
            expects: 'array',
        );

        return is_array($cached) ? $cached : [];
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
     * @param array<string, mixed> $filters The filters getIcons() takes; none builds the cached corpus-wide tree
     *
     * @return list<array{pack: string, label: string, count: int, icon_count: int, cats: list<array<string, mixed>>}>
     */
    public function buildIconTree(array $filters = []): array
    {
        // A filtered tree is built live: the selection is per request, and a
        // cache key per selection would only fill the store.
        $tree = self::hasFilters($filters)
            ? ($this->hasIcons() ? $this->buildTree($this->filteredIds($filters)) : [])
            : $this->cacheManager->remember(
                'browser.tree',
                fn (): array => $this->hasIcons() ? $this->buildTree(null) : [],
                expects: 'array',
            );

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
     * The one filter path: every listing, facet count and tree count narrows
     * the icon set through here, so the grid, the counts beside it and the
     * tree can never disagree.
     *
     * Search NARROWS the filtered set; it does not replace it. The filters
     * used to sit in an `else` branch behind `if (search)`, so selecting a
     * package and then typing returned matches from every package.
     *
     * Filters: `search` (+ `language`, PostgreSQL only), `packages`, `kind`,
     * `categories`, `subs` (child categories, or variants within a category),
     * `variants`, `tags`, `keywords`.
     *
     * @param array<string, mixed> $filters
     *
     * @return Builder<Icon>
     */
    public function filteredQuery(array $filters): Builder
    {
        $query = Icon::query();
        $search = $filters['search'] ?? null;

        if (! empty($search) && is_scalar($search)) {
            $language = $filters['language'] ?? null;

            // A chosen language means full-text search, which only PostgreSQL
            // has; everywhere else, and without one, the portable LIKE path.
            $query = is_string($language) && $language !== '' && Helpers::dbDriverIsPgSql()
                ? Icon::search((string) $search, $language)
                : Icon::fuzzySearch((string) $search);
        }

        $packages = self::stringList($filters['packages'] ?? null);

        if ($packages !== []) {
            $query->whereIn('package', $packages);
        }

        // Kind: icons or illustrations. A value that names no kind matches
        // nothing, so a bad query parameter cannot widen the result.
        $kind = $filters['kind'] ?? null;

        if ($kind instanceof IconKind || (is_string($kind) && $kind !== '')) {
            $query->ofKind($kind);
        }

        $categories = self::stringList($filters['categories'] ?? null);

        if ($categories !== []) {
            $query->whereHas('terms', fn (Builder $q) => $q->where('type', IconTerm::TYPE_CATEGORY)->whereIn('slug', $categories));
        }

        // Subcategories: a nested category (a term with a parent) or a variant
        // within the selected category -- the tree shows both under `sub`.
        $subs = self::stringList($filters['subs'] ?? null);

        if ($subs !== []) {
            $query->whereHas('terms', fn (Builder $q) => $q->whereIn('slug', $subs)->where(
                fn (Builder $w) => $w->where(fn (Builder $c) => $c->where('type', IconTerm::TYPE_CATEGORY)->whereNotNull('parent_id'))
                    ->orWhere('type', IconTerm::TYPE_VARIANT),
            ));
        }

        $variants = self::stringList($filters['variants'] ?? null);

        if ($variants !== []) {
            $query->whereHas('terms', fn (Builder $q) => $q->where('type', IconTerm::TYPE_VARIANT)->whereIn('slug', $variants));
        }

        $tags = self::stringList($filters['tags'] ?? null);

        if ($tags !== []) {
            $query->whereHasTag($tags);
        }

        $keywords = self::stringList($filters['keywords'] ?? null);

        if ($keywords !== []) {
            $query->whereHasKeyword($keywords);
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($item)) {
                $map[(string) $key] = $item;
            }
        }

        return $map;
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
     * Whether any filter is set, so callers can skip the scoped query (and
     * keep the cached corpus-wide answer) when nothing narrows it.
     *
     * @param array<string, mixed> $filters
     */
    private static function hasFilters(array $filters): bool
    {
        foreach (['search', 'packages', 'kind', 'categories', 'subs', 'variants', 'tags', 'keywords'] as $key) {
            $value = $filters[$key] ?? null;

            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return is_array($value) ? array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== '')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFilters(): array
    {
        return [
            'packages'            => [],
            'categories'          => [],
            'variants'            => [],
            'variants_by_package' => [],
            'kinds'               => $this->kindFacet([]),
            'empty'               => true,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function buildFilters(array $filters): array
    {
        $scoped = self::hasFilters($filters);
        $packages = $this->registry->all();
        $default = $this->registry->getDefault();

        // The count is the number of icons SEEDED for the pack, not the
        // number of SVG files the pack ships: a pack that is registered but
        // not yet seeded reads 0 instead of its file count, and one whose
        // files are absent from disk still reports its seeded icons.
        $iconCounts = $scoped
            ? $this->packageCounts($this->filteredIds(array_diff_key($filters, ['packages' => true])))
            : $this->database->getPackageStatistics();

        $transformedPackages = collect($packages)->map(fn (array $pkg, string $key): array => [
            'name'        => $key,
            'label'       => $pkg['name'] ?? $key,
            'count'       => $iconCounts[$key] ?? 0,
            'description' => $pkg['description'] ?? '',
            'vendor'      => $pkg['vendor'] ?? '',
            'kind'        => $pkg['kind'] ?? IconKind::Icon->value,
            // The pack the browser opens on: the registry's default set.
            'default' => $key === $default,
        ])->values()->toArray();

        return [
            'packages'            => $transformedPackages,
            'categories'          => $this->termFacet(IconTerm::TYPE_CATEGORY, $scoped ? $this->filteredIds(array_diff_key($filters, ['categories' => true, 'subs' => true])) : null),
            'variants'            => $this->termFacet(IconTerm::TYPE_VARIANT, $scoped ? $this->filteredIds(array_diff_key($filters, ['variants' => true])) : null),
            'variants_by_package' => $this->variantsByPackage($packages),
            'kinds'               => $this->kindFacet($this->kindCounts($scoped ? $this->filteredIds(array_diff_key($filters, ['kind' => true])) : null)),
            'empty'               => false,
        ];
    }

    /**
     * Icons per pack, optionally over a selection.
     *
     * @return array<string, int>
     */
    private function packageCounts(?QueryBuilder $ids = null): array
    {
        $query = DB::table(Icon::TABLE)->selectRaw('package, COUNT(*) as icon_count')->groupBy('package');

        if ($ids !== null) {
            $query->whereIn(Icon::TABLE . '.id', $ids);
        }

        $counts = [];

        foreach ($query->get() as $row) {
            if (is_string($row->package ?? null)) {
                $counts[$row->package] = is_numeric($row->icon_count ?? null) ? (int) $row->icon_count : 0;
            }
        }

        return $counts;
    }

    /**
     * Categories or variants with the number of icons carrying each.
     *
     * @return array<int, array{name: mixed, label: mixed, count: int}>
     */
    private function termFacet(string $type, ?QueryBuilder $ids): array
    {
        $query = DB::table('ichava_icon_termables')
            ->join('ichava_icon_terms', 'ichava_icon_termables.term_id', '=', 'ichava_icon_terms.id')
            ->where('ichava_icon_terms.type', $type)
            ->where('ichava_icon_termables.termable_type', (new Icon)->getMorphClass())
            ->select('ichava_icon_terms.slug', 'ichava_icon_terms.name')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('ichava_icon_terms.slug', 'ichava_icon_terms.name');

        if ($ids !== null) {
            $query->whereIn('ichava_icon_termables.termable_id', $ids);
        }

        return $query->get()
            ->map(fn (stdClass $term): array => [
                'name'  => $term->slug,
                'label' => $term->name,
                'count' => is_numeric($term->count) ? (int) $term->count : 0,
            ])->values()->all();
    }

    /**
     * Each pack's variants as the variant picker shows them, in display
     * order, with the pack's translated label and description winning over
     * config.json's English.
     *
     * @param array<string, array<string, mixed>> $packages
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function variantsByPackage(array $packages): array
    {
        $byPackage = [];

        foreach ($packages as $name => $package) {
            $metadata = is_array($package['variant_metadata'] ?? null) ? $package['variant_metadata'] : [];

            if ($metadata === []) {
                continue;
            }

            $labels = is_array($package['labels'] ?? null) ? $package['labels'] : [];

            $byPackage[$name] = array_map(
                static fn (VariantData $variant): array => $variant->toArray(),
                VariantData::listFromMetadata(
                    $metadata,
                    self::stringMap($labels['variants'] ?? null),
                    self::stringMap($labels['variant_descriptions'] ?? null),
                ),
            );
        }

        return $byPackage;
    }

    /**
     * The tree over the icons `$ids` selects, or over every icon when null.
     *
     * @return list<array<string, mixed>>
     */
    private function buildTree(?QueryBuilder $ids): array
    {
        $iconMorphAlias = (new Icon)->getMorphClass();

        // One row per (package, term) with the number of icons carrying it.
        $rows = DB::table('ichava_icon_terms')
            ->join('ichava_icon_termables', 'ichava_icon_termables.term_id', '=', 'ichava_icon_terms.id')
            ->join('ichava_icons', function (JoinClause $join) use ($iconMorphAlias): void {
                $join->on('ichava_icon_termables.termable_id', '=', 'ichava_icons.id')
                    ->where('ichava_icon_termables.termable_type', '=', $iconMorphAlias);
            })
            ->whereIn('ichava_icon_terms.type', ['category', 'variant'])
            ->when($ids !== null, fn ($q) => $q->whereIn('ichava_icons.id', $ids))
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

        $iconTotals = $ids === null ? $this->database->getPackageStatistics() : $this->packageCounts($ids);

        // A category with nested categories also reports the icons of
        // its whole subtree. Corpus-wide only: getTotalIconCount() does
        // not take a selection, and few categories nest, so it is one
        // query per parent category rather than per node.
        $subtreeTotals = $ids === null
            ? IconTerm::query()->whereIn('id', $rows->where('type', 'category')->pluck('id'))->has('children')->get()
                ->mapWithKeys(fn (IconTerm $term): array => [$term->id => $term->getTotalIconCount()])
                ->all()
            : [];

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
            ->when($ids !== null, fn ($q) => $q->whereIn('tc.termable_id', $ids))
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
                    'total' => is_int($rowId) || is_string($rowId) ? ($subtreeTotals[$rowId] ?? null) : null,
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
    }

    /**
     * The ids of the icons a filter set selects, as a subquery.
     *
     * @param array<string, mixed> $filters
     */
    private function filteredIds(array $filters): QueryBuilder
    {
        return $this->filteredQuery($filters)->select(Icon::TABLE . '.id')->toBase();
    }

    /**
     * Seeded icons per kind, keyed by kind value. Kinds with no rows are absent.
     *
     * @return array<string, int>
     */
    private function kindCounts(?QueryBuilder $ids = null): array
    {
        $counts = [];
        $query = DB::table(Icon::TABLE)->selectRaw('kind, COUNT(*) as icon_count')->groupBy('kind');

        if ($ids !== null) {
            $query->whereIn(Icon::TABLE . '.id', $ids);
        }

        foreach ($query->get() as $row) {
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
