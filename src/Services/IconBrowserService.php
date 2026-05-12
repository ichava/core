<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Support\Helpers;

/**
 * Icon Browser Service
 *
 * Centralized service for all icon browser operations.
 * Handles icon listing, filtering, tree building, and statistics.
 */
final class IconBrowserService
{
    public function __construct(
        protected IconRegistry $registry,
        protected IconDiscoveryService $discoveryService,
        protected IconCacheService $cacheManager,
        protected IchavaLogger $logger
    ) {}

    /**
     * Get filtered and paginated icons
     */
    public function getIcons(
        array $filters = [],
        int $page = 1,
        int $perPage = 60,
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator {
        // Return empty result if database is empty
        if (! $this->hasIcons()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $page);
        }

        $query = Icon::query();

        // Handle search
        if (! empty($filters['search'])) {
            // Use fuzzySearch for better performance with large datasets
            $query = Icon::fuzzySearch($filters['search'], 10000);
        } else {
            // Apply filters only when not searching
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
            $this->logger->error('Icon pagination failed: '.$e->getMessage());

            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, $page);
        }
    }

    /**
     * Transform icon model to API response format
     */
    public function transformIcon(Icon $icon): array
    {
        $iconData = [
            'id' => $icon->id,
            'package' => $icon->package,
            'name' => $icon->name,
            'category' => $icon->primary_category?->slug,
            'variant' => $icon->primary_variant?->slug,
            'path' => $icon->icon_path,
            'svg_content' => $icon->svg_content,
            'svg_url' => route('ichava.api.icons.svg', ['id' => $icon->id], false),
            'viewbox' => $icon->viewbox,
            'width' => $icon->width,
            'height' => $icon->height,
            'icon_path' => $icon->icon_path,
            'file_path' => $icon->path ?? '',
            'set' => $icon->package,
        ];

        // Generate Blade component syntax server-side
        $iconData['blade_clean'] = $this->generateBladeComponent($icon, true);
        $iconData['blade_generic'] = $this->generateBladeComponent($icon, false);
        $iconData['helper'] = $this->generateHelperCode($icon);

        return $iconData;
    }

    /**
     * Generate Blade component syntax for an icon
     */
    public function generateBladeComponent(Icon $icon, bool $useCleanSyntax = true): string
    {
        $packageName = $icon->package;
        $iconPath = $icon->icon_path;
        $category = $icon->primary_category?->slug ?? '';

        // If not using clean syntax, always return generic component
        if (! $useCleanSyntax) {
            return "<x-ichava::icon name=\"{$iconPath}\" class=\"w-6 h-6\" />";
        }

        // Parse package name
        if (! Str::contains($packageName, '/')) {
            // No vendor prefix, use generic
            return "<x-ichava::icon name=\"{$iconPath}\" class=\"w-6 h-6\" />";
        }

        $vendor = Str::before($packageName, '/');
        $packagePart = Str::after($packageName, '/');

        // Generate unified syntax: <x-ichava::icon name="vendor/package::path/icon" />
        // This works for all packages (official ichava and third-party)
        return "<x-ichava::icon name=\"{$iconPath}\" class=\"w-6 h-6\" />";
    }

    /**
     * Generate helper function code for an icon
     */
    public function generateHelperCode(Icon $icon): string
    {
        $iconPath = $icon->icon_path;

        return "{{ ichava('{$iconPath}')->class('w-6 h-6') }}";
    }

    /**
     * Group icons by a specified field
     *
     * @param  string  $groupBy  (package|category|name)
     */
    public function groupIcons(Collection $icons, string $groupBy = 'package'): array
    {
        $grouped = [];

        foreach ($icons as $icon) {
            $key = match ($groupBy) {
                'package' => $icon['package'] ?? 'Unknown',
                'category' => $icon['category'] ?? 'Uncategorized',
                default => $icon['package'] ?? 'Unknown', // Default to package grouping
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
                    'packages' => [],
                    'categories' => [],
                    'variants' => [],
                    'empty' => true,
                ];
            }

            $packages = $this->registry->all();

            $transformedPackages = collect($packages)->map(function ($pkg, $key) {
                return [
                    'name' => $key,
                    'label' => $pkg['browser_metadata']['name'] ?? $key,
                    'count' => $pkg['total'] ?? 0,
                    'description' => $pkg['browser_metadata']['description'] ?? '',
                    'vendor' => $pkg['browser_metadata']['vendor'] ?? '',
                ];
            })->values();

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
                        'name' => $category->slug,
                        'label' => $category->name,
                        'count' => $category->count,
                    ];
                })->values();

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
                        'name' => $variant->slug,
                        'label' => $variant->name,
                        'count' => $variant->count,
                    ];
                })->values();

            return [
                'packages' => $transformedPackages,
                'categories' => $categories,
                'variants' => $variants,
                'empty' => false,
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
                        'icons' => 0,
                        'packages' => $this->registry->count(),
                        'categories' => 0,
                        'variants' => 0,
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
                    'icons' => $totalIcons,
                    'packages' => $totalPackages,
                    'categories' => $totalCategories,
                    'variants' => $totalVariants,
                ],
                'empty' => false,
            ];
        });
    }

    /**
     * Build hierarchical icon tree structure
     */
    public function buildIconTree(): array
    {
        return $this->cacheManager->remember('browser.tree', function () {
            // Check if database has any icons
            if (! $this->hasIcons()) {
                return [
                    'tree' => [],
                    'empty' => true,
                ];
            }

            $packages = $this->registry->all();
            $tree = [];

            // Batch-load category counts from terms relationship
            // Use morph alias (registered as 'icon' in morphMap)
            $iconMorphAlias = (new Icon)->getMorphClass();

            $categoryCounts = DB::table('ichava_icon_termables')
                ->join('ichava_icon_terms', 'ichava_icon_termables.term_id', '=', 'ichava_icon_terms.id')
                ->join('ichava_icons', function ($join) use ($iconMorphAlias) {
                    $join->on('ichava_icon_termables.termable_id', '=', 'ichava_icons.id')
                        ->where('ichava_icon_termables.termable_type', '=', $iconMorphAlias);
                })
                ->where('ichava_icon_terms.type', 'category')
                ->selectRaw('ichava_icons.package, ichava_icon_terms.slug as category, COUNT(*) as count')
                ->groupBy('ichava_icons.package', 'ichava_icon_terms.slug')
                ->get()
                ->groupBy('package')
                ->map(function ($packageCategories) {
                    return $packageCategories->pluck('count', 'category')->toArray();
                })
                ->toArray();

            foreach ($packages as $packageKey => $packageData) {
                $config = $this->getPackageConfig($packageKey, $packageData);
                $basePath = $packageData['base_path'] ?? null;

                if (! $basePath || ! File::exists($basePath)) {
                    continue;
                }

                $packageCounts = $categoryCounts[$packageKey] ?? [];

                // Scan for categories/folders
                $children = $this->scanFolderTree($basePath, $packageKey, $packageCounts);

                // Only include packages that have categories/folders
                if (empty($children)) {
                    continue;
                }

                $tree[] = [
                    'id' => $packageKey,
                    'type' => 'package',
                    'name' => $config['name'],
                    'title' => $config['title'],
                    'description' => $config['description'],
                    'icon_count' => $packageData['total'] ?? 0,
                    'expanded' => false,
                    'children' => $children,
                ];
            }

            // Return just the tree array for backwards compatibility
            // The empty check is implicit (empty array)
            return $tree;
        });
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
    protected function hasIcons(): bool
    {
        return Icon::query()->exists();
    }

    /**
     * Get package configuration
     */
    protected function getPackageConfig(string $packageKey, array $packageData): array
    {
        $configPath = $packageData['config_path'] ?? null;
        $basePath = $packageData['base_path'] ?? null;

        if ($configPath && File::exists($configPath)) {
            try {
                // Load config.json using centralized helper
                $config = Helpers::loadConfigJson($basePath, false);

                return [
                    'name' => $config['package']['name'] ?? $packageKey,
                    'title' => $config['package']['title'] ?? $packageKey,
                    'description' => $config['package']['description'] ?? '',
                ];
            } catch (IchavaException $e) {
                // Fall through to defaults
                $this->logger->debug('Failed to load package config', [
                    'package' => $packageKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'name' => $packageKey,
            'title' => $packageData['browser_metadata']['name'] ?? $packageKey,
            'description' => $packageData['browser_metadata']['description'] ?? '',
        ];
    }

    /**
     * Recursively scan folder tree and build hierarchy
     */
    protected function scanFolderTree(
        string $basePath,
        string $package,
        array $categoryCounts,
        int $maxDepth = 3,
        int $currentDepth = 0
    ): array {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $tree = [];
        $directories = File::directories($basePath);

        foreach ($directories as $dir) {
            $folderName = basename($dir);

            // Skip common non-icon directories
            if (in_array($folderName, ['config', 'lang', 'vendor', 'node_modules', '.git'])) {
                continue;
            }

            // FIRST: Recursively scan children (before checking icon count)
            // This ensures we don't skip parent folders that contain sub-folders with icons
            $children = $this->scanFolderTree($dir, $package, $categoryCounts, $maxDepth, $currentDepth + 1);

            // Use pre-loaded count from database, fallback to filesystem
            $iconCount = $categoryCounts[$folderName] ?? null;

            // If no database count, check filesystem for SVG files (recursive)
            if ($iconCount === null) {
                $iconCount = $this->countSvgFilesRecursive($dir);
            }

            // Calculate total icon count including children
            $childrenIconCount = array_sum(array_column($children, 'icon_count'));
            $totalIconCount = $iconCount + $childrenIconCount;

            // Skip folders with no icons AND no children with icons
            if ($totalIconCount === 0 && empty($children)) {
                continue;
            }

            $tree[] = [
                'id' => "{$package}::{$folderName}",
                'type' => 'folder',
                'name' => $folderName,
                'label' => ucwords(str_replace(['-', '_'], ' ', $folderName)),
                'path' => $dir,
                'icon_count' => $totalIconCount,
                'package' => $package,
                'depth' => $currentDepth,
                'expanded' => false,
                'checked' => false,
                'children' => $children,
            ];
        }

        return $tree;
    }

    /**
     * Count SVG files in a directory (non-recursive, direct files only)
     */
    protected function countSvgFilesInDirectory(string $directory): int
    {
        if (! File::isDirectory($directory)) {
            return 0;
        }

        $svgFiles = File::glob($directory.'/*.svg');

        return count($svgFiles);
    }

    /**
     * Count SVG files recursively in a directory and all subdirectories
     */
    protected function countSvgFilesRecursive(string $directory): int
    {
        if (! File::isDirectory($directory)) {
            return 0;
        }

        $count = 0;

        // Count SVGs in this directory
        $svgFiles = File::glob($directory.'/*.svg');
        $count += count($svgFiles);

        // Recursively count in subdirectories
        $subdirs = File::directories($directory);
        foreach ($subdirs as $subdir) {
            $count += $this->countSvgFilesRecursive($subdir);
        }

        return $count;
    }

    /**
     * Count icons in a specific folder (using terms)
     */
    protected function countIconsInFolder(string $package, string $folder): int
    {
        // Use morph alias (registered as 'icon' in morphMap)
        $iconMorphAlias = (new Icon)->getMorphClass();

        return DB::table('ichava_icon_termables')
            ->join('ichava_icon_terms', 'ichava_icon_termables.term_id', '=', 'ichava_icon_terms.id')
            ->join('ichava_icons', function ($join) use ($iconMorphAlias) {
                $join->on('ichava_icon_termables.termable_id', '=', 'ichava_icons.id')
                    ->where('ichava_icon_termables.termable_type', '=', $iconMorphAlias);
            })
            ->where('ichava_icons.package', $package)
            ->where('ichava_icon_terms.slug', $folder)
            ->where('ichava_icon_terms.type', 'category')
            ->count();
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
}
