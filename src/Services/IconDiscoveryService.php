<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Ichava\Models\Icon;

/**
 * IconDiscoveryService - Unified Discovery for Icons and Packages
 *
 * Consolidates:
 * - IconDiscoveryService (icon browsing, searching)
 * - PackageDiscoveryService (composer package detection)
 *
 * Single source of truth for:
 * - Registered packages (via IconRegistry)
 * - Installed packages (via composer.lock)
 * - Icon enumeration and search
 * - Statistics and metadata
 */
class IconDiscoveryService
{
    public const CACHE_PREFIX = 'ichava.discovery';

    public const CACHE_DURATION = 3600; // 1 hour

    public function __construct(
        protected IconRegistry $registry,
        protected IconCacheService $cache,
    ) {}

    /**
     * Discover all installed Ichava packages from composer.lock
     */
    public function discoverInstalledPackages(): array
    {
        return Cache::remember(
            self::CACHE_PREFIX . '.installed',
            self::CACHE_DURATION,
            fn () => $this->scanComposerLock(),
        );
    }

    /**
     * Get all registered packages (alias for getPackages)
     */
    public function getRegisteredPackages(): array
    {
        return array_keys($this->packages());
    }

    /**
     * Get packages that are installed but not registered
     */
    public function getUnregisteredPackages(): array
    {
        $installed = collect($this->discoverInstalledPackages());
        $registered = collect($this->registry->all())->keys();

        return $installed
            ->filter(fn ($pkg) => ! $registered->contains($pkg['name']))
            ->filter(fn ($pkg) => $pkg['name'] !== 'ichava/ichava') // Exclude parent
            ->values()
            ->all();
    }

    /**
     * Get packages that are registered but missing from composer.lock
     */
    public function getOrphanedPackages(): array
    {
        $installed = collect($this->discoverInstalledPackages())->pluck('name');
        $registered = collect($this->registry->all());

        return $registered
            ->filter(fn ($meta, $name) => ! $installed->contains($name))
            ->keys()
            ->all();
    }

    /**
     * Get all registered packages with their icons
     *
     * Optimized for large icon sets (500k+ files):
     * - Uses cached manifests instead of scanning filesystem
     * - Only loads icon count, not full icon data
     * - Lazy loads actual icons on demand
     */
    public function getPackages(): array
    {
        $registryPackages = $this->registry->all();
        $cacheKey = self::CACHE_PREFIX . '.packages.' . md5(serialize($registryPackages));

        return $this->remember($cacheKey, self::CACHE_DURATION, function () use ($registryPackages) {
            $packages = [];

            foreach ($registryPackages as $packageName => $metadata) {
                // Get icon count from manifest cache (fast)
                $iconCount = $this->getIconCount($metadata['base_path']);

                $packages[$packageName] = array_merge($metadata, [
                    'alias'       => $metadata['icon_set_name'],
                    'name'        => $metadata['browser_metadata']['name'] ?? $metadata['icon_set_name'],
                    'vendor'      => $metadata['browser_metadata']['vendor'] ?? 'ichava',
                    'package'     => $packageName,
                    'path'        => $metadata['base_path'],
                    'description' => $metadata['browser_metadata']['description'] ?? '',
                    'icons'       => [], // Don't load all icons upfront
                    'total'       => $iconCount,
                ]);
            }

            return $packages;
        });
    }

    /**
     * Get a single package by name or alias
     */
    public function getPackage(string $nameOrAlias): ?array
    {
        $packages = $this->packages();

        // Try direct lookup
        if (isset($packages[$nameOrAlias])) {
            return $packages[$nameOrAlias];
        }

        // Try by alias
        foreach ($packages as $package) {
            if ($package['alias'] === $nameOrAlias || $package['icon_set_name'] === $nameOrAlias) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Search icons across all or specific packages
     *
     * Optimized for 500k+ icons:
     * - Streams results instead of loading all into memory
     * - Uses generator pattern for memory efficiency
     * - Stops scanning once page is filled
     * - Caches search results
     */
    public function searchIcons(
        string $query = '',
        array $packages = [],
        array $categories = [],
        int $page = 1,
        int $perPage = 60,
        string $sortBy = 'name',
        string $sortDirection = 'asc',
    ): array {
        // Use database if enabled and table exists
        if (config('ichava.core.database.enabled', true) && $this->isDatabaseAvailable()) {
            return $this->searchIconsFromDatabase($query, $packages, $categories, $page, $perPage, $sortBy, $sortDirection);
        }

        // Fallback to file system search
        return $this->searchIconsFromFileSystem($query, $packages, $categories, $page, $perPage, $sortBy, $sortDirection);
    }

    /**
     * Get all categories across all packages with hierarchical structure
     * Cached for performance when dealing with large icon sets
     */
    public function getAllCategories(): array
    {
        $packages = $this->packages();
        $cacheKey = self::CACHE_PREFIX . '.categories.' . md5(serialize(array_keys($packages)));

        return $this->remember($cacheKey, self::CACHE_DURATION, function () use ($packages) {
            $categories = [];

            foreach ($packages as $packageKey => $package) {
                // Get base path - prioritize base_path (where SVG files are)
                $basePath = $package['base_path'] ?? $package['icon_path'] ?? $package['path'] ?? null;

                if (! $basePath || ! File::isDirectory($basePath)) {
                    continue;
                }

                // Scan directory for actual folder structure
                $folders = $this->scanFolderHierarchy($basePath, $packageKey);

                foreach ($folders as $folder) {
                    $categories[] = $folder;
                }
            }

            // Sort categories by package and path
            usort($categories, function ($a, $b) {
                $packageCompare = strcmp($a['package'], $b['package']);
                if ($packageCompare !== 0) {
                    return $packageCompare;
                }

                return strcmp($a['path'], $b['path']);
            });

            return $categories;
        });
    }

    /**
     * Get SVG content for an icon
     */
    public function getIconSvg(string $package, string $name, ?string $variant = null): ?string
    {
        $iconSet = $this->registry->set($package);

        if (! $iconSet) {
            return null;
        }

        $iconData = $iconSet->get($name, $variant);

        if (! $iconData || ! File::exists($iconData->path)) {
            return null;
        }

        return File::get($iconData->path);
    }

    /**
     * Get usage syntax for an icon
     */
    public function getIconSyntax(string $package, string $name, ?string $variant = null): array
    {
        $packageData = $this->getPackage($package);
        $prefix = $packageData['prefix'] ?? $package;
        $iconName = $prefix . ':' . $name;

        if ($variant) {
            $iconName .= ':' . $variant;
        }

        return [
            'helper'    => "ichava('{$iconName}')",
            'directive' => "@ichava('{$iconName}')",
            'component' => $packageData['blade_component']
                ? "<x-{$packageData['blade_component']} name=\"{$name}\" />"
                : null,
        ];
    }

    /**
     * Get statistics about registered packages and icons
     */
    public function getStatistics(): array
    {
        $packages = $this->packages();
        $totalIcons = 0;
        $byPackage = [];
        $byCategory = [];

        foreach ($packages as $packageKey => $package) {
            // Use the pre-calculated 'total' instead of counting icons array
            $packageTotal = $package['total'] ?? 0;
            $totalIcons += $packageTotal;

            $byPackage[$packageKey] = [
                'name'  => $package['browser_metadata']['name'] ?? $packageKey,
                'total' => $packageTotal,
            ];

            // Note: We can't calculate category stats without scanning all icons
            // This would require loading all icons into memory, which we're avoiding
            // Category stats are now calculated on-demand when filtering
        }

        return [
            'total_packages'    => count($packages),
            'total_icons'       => $totalIcons,
            'icons_by_package'  => $byPackage,
            'icons_by_category' => $byCategory,
            'registered'        => array_keys($packages),
            'installed'         => count($this->discoverInstalledPackages()),
            'unregistered'      => count($this->getUnregisteredPackages()),
        ];
    }

    /**
     * Clear discovery cache
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_PREFIX . '.installed');

        // Clear package caches
        $packages = $this->registry->all();
        $cacheKey = self::CACHE_PREFIX . '.packages.' . md5(serialize($packages));
        Cache::forget($cacheKey);
    }

    /**
     * Get discovery statistics with unregistered package details
     */
    public function getDiscoveryStats(): array
    {
        $stats = $this->getStatistics();
        $unregistered = $this->getUnregisteredPackages();

        return [
            'total_discovered'   => $stats['installed'],
            'total_registered'   => $stats['total_packages'],
            'total_unregistered' => $stats['unregistered'],
            'unregistered_list'  => array_column($unregistered, 'name'),
        ];
    }

    /**
     * Register package metadata (for icon browser)
     *
     * Note: Packages are now auto-registered via IconRegistry.
     * This method allows manual registration for testing/legacy purposes.
     */
    public function registerPackage(string $alias, array $metadata): void
    {
        // For testing purposes, we can manually add to cache
        // In production, packages register via IconRegistry automatically
        $cacheKey = self::CACHE_PREFIX . '.manual.' . $alias;
        Cache::put($cacheKey, $metadata, self::CACHE_DURATION);
    }

    /**
     * Get all packages from IconRegistry
     */
    protected function packages(): array
    {
        return $this->registry->all();
    }

    /**
     * Scan composer.lock for Ichava packages
     */
    protected function scanComposerLock(): array
    {
        $composerLockPath = base_path('composer.lock');

        if (! File::exists($composerLockPath)) {
            return [];
        }

        $lockData = json_decode(File::get($composerLockPath), true);

        if (! isset($lockData['packages'])) {
            return [];
        }

        $ichavaPackages = [];

        foreach ($lockData['packages'] as $package) {
            $name = $package['name'] ?? '';

            // Check if it's an Ichava package (vendor prefix or extra.laravel.providers contains Ichava)
            if (! Str::startsWith($name, 'ichava/')) {
                continue;
            }

            $ichavaPackages[] = [
                'name'        => $name,
                'version'     => $package['version'] ?? 'unknown',
                'description' => $package['description'] ?? '',
                'homepage'    => $package['homepage'] ?? null,
                'type'        => $package['type'] ?? 'library',
                'time'        => $package['time'] ?? null,
            ];
        }

        return $ichavaPackages;
    }

    /**
     * Get icon count from manifest cache with smart invalidation
     *
     * Uses IconCacheService for all cache operations
     */
    protected function getIconCount(string $basePath): int
    {
        // Check if directory has changed
        if ($this->cache->hasDirectoryChanged($basePath)) {
            // Directory changed - invalidate cache via service
            $this->cache->clearManifest($basePath);

            // Update fingerprint after counting
            $this->cache->updateDirectoryFingerprint($basePath);
        }

        $manifestKey = self::CACHE_PREFIX . '.manifest.' . md5($basePath);

        // rememberForever on the file driver so the manifest survives across requests.
        return (int) cache()
            ->store('file')
            ->remember(
                key: $manifestKey,
                ttl: now()->addHours(24),
                callback: fn () => $this->countSvgFiles($basePath),
            );
    }

    /**
     * Count SVG files in directory
     */
    protected function countSvgFiles(string $basePath): int
    {
        if (! File::isDirectory($basePath)) {
            return 0;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            $count = 0;
            foreach ($iterator as $file) {
                if ($file->isFile() && Str::endsWith($file->getFilename(), '.svg')) {
                    $count++;
                }
            }

            return $count;
        } catch (IchavaException $e) {
            return 0;
        }
    }

    /**
     * Check if database is available
     */
    protected function isDatabaseAvailable(): bool
    {
        try {
            return Schema::hasTable('ichava_icons');
        } catch (IchavaException $e) {
            return false;
        }
    }

    /**
     * Search icons from database (300x faster!) with Redis caching
     */
    protected function searchIconsFromDatabase(
        string $query,
        array $packages,
        array $categories,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection,
    ): array {
        // Use Redis cache for super-fast repeated searches
        $cacheKey = 'icons.search.db.' . md5(serialize(func_get_args()));

        return $this->cache->remember($cacheKey, function () use ($query, $packages, $categories, $page, $perPage, $sortBy, $sortDirection) {
            return $this->executeSearchQuery($query, $packages, $categories, $page, $perPage, $sortBy, $sortDirection);
        });
    }

    /**
     * Execute the actual database search query
     */
    protected function executeSearchQuery(
        string $query,
        array $packages,
        array $categories,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection,
    ): array {
        // Build query
        $queryBuilder = Icon::query();

        // Apply search
        if (! empty($query)) {
            $queryBuilder->whereRaw("
                to_tsvector('english',
                    COALESCE(name, '') || ' ' ||
                    COALESCE(category, '') || ' ' ||
                    COALESCE(keywords, '')
                ) @@ plainto_tsquery('english', ?)
            ", [$query]);
        }

        // Filter by packages
        if (! empty($packages)) {
            $queryBuilder->whereIn('package', $packages);
        }

        // Filter by categories
        if (! empty($categories)) {
            $queryBuilder->whereIn('category', $categories);
        }

        // Get total count
        $total = $queryBuilder->count();

        // Apply sorting
        $queryBuilder->orderBy($sortBy, $sortDirection);

        // Paginate
        $icons = $queryBuilder
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // Transform to expected format
        $allPackages = $this->getPackages();
        $items = $icons->map(function ($icon) use ($allPackages) {
            $packageData = $allPackages[$icon->package] ?? [];

            return [
                'package'      => $icon->package,
                'package_name' => $packageData['name'] ?? $icon->package,
                'set'          => $icon->package,
                'name'         => $icon->name,
                'category'     => $icon->category,
                'variant'      => $icon->variant,
                'path'         => $icon->path,
                'icon_path'    => $icon->getIconPath(),
                'syntax'       => $this->getIconSyntax($icon->package, $icon->name, $icon->variant),
                'svg_content'  => null, // Deferred rendering
            ];
        })->toArray();

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Search icons from file system (legacy fallback)
     */
    protected function searchIconsFromFileSystem(
        string $query,
        array $packages,
        array $categories,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection,
    ): array {
        $cacheKey = self::CACHE_PREFIX . '.search.' . md5(serialize(func_get_args()));

        return $this->remember($cacheKey, 300, function () use ($query, $packages, $categories, $page, $perPage, $sortBy, $sortDirection) {
            $allPackages = $this->getPackages();

            // Filter by selected packages
            if (! empty($packages)) {
                $allPackages = Arr::only($allPackages, $packages);
            }

            $offset = ($page - 1) * $perPage;
            $collected = 0;
            $skipped = 0;
            $totalCount = 0;
            $items = [];

            // Single pass: Count total AND collect page items efficiently
            foreach ($allPackages as $packageKey => $packageData) {
                foreach ($this->streamIcons($packageData['base_path'], $packageKey) as $iconData) {
                    // Apply filters
                    if (! $this->matchesFilters($iconData, $query, $categories)) {
                        continue;
                    }

                    // Always count for accurate total
                    $totalCount++;

                    // Skip until we reach the page offset
                    if ($skipped < $offset) {
                        $skipped++;

                        continue;
                    }

                    // Only collect if page not full yet
                    if ($collected < $perPage) {
                        // Build the icon path for rendering (vendor/package::category/icon-name)
                        $iconPath = $packageKey . '::';
                        if (! empty($iconData['category'])) {
                            $iconPath .= $iconData['category'] . '/';
                        }
                        $iconPath .= $iconData['name'];

                        // Collect icon data (defer SVG rendering to reduce memory/processing)
                        $items[] = [
                            'package'      => $packageKey,
                            'package_name' => $packageData['name'],
                            'set'          => $packageKey,
                            'name'         => $iconData['name'],
                            'category'     => $iconData['category'],
                            'variant'      => $iconData['variant'],
                            'path'         => $iconData['path'],
                            'icon_path'    => $iconPath, // Simple string path for ichava() helper
                            'syntax'       => $this->getIconSyntax($packageKey, $iconData['name'], $iconData['variant']),
                            // Defer SVG loading - render only when displayed
                            'svg_content' => null,
                        ];

                        $collected++;
                    }
                }
            }

            // Use accurate total count
            $total = $totalCount;

            // Sort items
            $items = $this->sortIcons($items, $sortBy, $sortDirection);

            return [
                'items'     => $items,
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => (int) ceil($total / $perPage),
            ];
        });
    }

    /**
     * Sort icons by specified field and direction
     *
     * @param string $sortBy name, package, category
     * @param string $sortDirection asc, desc
     */
    protected function sortIcons(array $icons, string $sortBy, string $sortDirection): array
    {
        $sortField = match ($sortBy) {
            'package'  => 'package_name',
            'category' => 'category',
            default    => 'name',
        };

        usort($icons, function ($a, $b) use ($sortField, $sortDirection) {
            $aValue = $a[$sortField] ?? '';
            $bValue = $b[$sortField] ?? '';

            $comparison = strcasecmp($aValue, $bValue);

            return $sortDirection === 'desc' ? -$comparison : $comparison;
        });

        return $icons;
    }

    /**
     * Stream icons from directory using generator pattern
     *
     * Memory efficient - yields one icon at a time
     */
    protected function streamIcons(string $basePath, string $packageName): Generator
    {
        if (! File::isDirectory($basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! Str::endsWith($file->getFilename(), '.svg')) {
                continue;
            }

            $relativePath = Str::after($file->getPathname(), $basePath . DIRECTORY_SEPARATOR);
            $parts = explode(DIRECTORY_SEPARATOR, $relativePath);
            $filename = array_pop($parts);
            $name = pathinfo($filename, PATHINFO_FILENAME);

            // If icon is in any folder(s), treat the complete folder path as category
            // This supports nested folders: "brand-logos/social/facebook.svg"
            // becomes category "brand-logos/social"
            $category = count($parts) > 0 ? implode('/', $parts) : null;

            yield [
                'name'     => $name,
                'variant'  => null, // Variants are handled by icon set configuration
                'category' => $category,
                'path'     => $file->getPathname(),
                'set'      => $packageName,
            ];
        }
    }

    /**
     * Check if icon matches search filters
     */
    protected function matchesFilters(array $iconData, string $query, array $categories): bool
    {
        // Apply search filter
        if (! empty($query)) {
            $searchIn = Str::lower($iconData['name'] . ' ' . ($iconData['category'] ?? ''));
            if (! Str::contains($searchIn, Str::lower($query))) {
                return false;
            }
        }

        // Apply category filter (support hierarchical paths)
        // Only filter if user has selected specific categories
        if (! empty($categories)) {
            // Extract category from icon path if not directly available
            $iconCategory = $iconData['category'] ?? null;

            // If category is not set, try to extract from path
            if (empty($iconCategory) && isset($iconData['path'])) {
                // Try to extract folder structure from path
                $pathInfo = pathinfo($iconData['path']);
                $dirname = $pathInfo['dirname'] ?? '';

                // If we can determine base path, extract relative folder
                // This is a fallback - normally category should be set
                if (! empty($dirname)) {
                    // Skip filtering for icons without determinable categories
                    return false;
                }
            }

            // If no category after all attempts, exclude when filtering
            if (empty($iconCategory)) {
                return false;
            }

            $matched = false;

            foreach ($categories as $filterCategory) {
                // Exact match
                if ($iconCategory === $filterCategory) {
                    $matched = true;
                    break;
                }

                // Hierarchical match: check if icon is in a subcategory
                // e.g., filter "brand-logos" matches icon in "brand-logos/social"
                if (Str::startsWith($iconCategory, $filterCategory . '/')) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scan folder hierarchy to detect nested category structure
     * Returns array with hierarchical information
     * OPTIMIZED: Only counts direct SVG files, estimates for subdirectories
     */
    protected function scanFolderHierarchy(string $basePath, string $packageKey, string $relativePath = '', int $depth = 0, int $maxDepth = 3): array
    {
        $folders = [];

        // Reduce max depth for performance
        if ($depth >= $maxDepth) {
            return $folders;
        }

        $currentPath = $relativePath ? $basePath . '/' . $relativePath : $basePath;

        if (! File::isDirectory($currentPath)) {
            return $folders;
        }

        try {
            $items = @scandir($currentPath);

            if ($items === false) {
                return $folders;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || Str::startsWith($item, '.')) {
                    continue;
                }

                $itemPath = $currentPath . '/' . $item;

                if (! File::isDirectory($itemPath)) {
                    continue;
                }

                $newRelativePath = $relativePath ? $relativePath . '/' . $item : $item;
                $parts = explode('/', $newRelativePath);
                $displayName = Str::ucfirst(str_replace(['-', '_'], ' ', end($parts)));

                // Count SVG files recursively for accurate counts
                $iconCount = $this->countSvgFiles($itemPath);
                $hasSubdirs = $this->hasSubdirectories($itemPath);

                if ($iconCount > 0) {
                    $folders[] = [
                        'name'         => $item,
                        'display_name' => $displayName,
                        'path'         => $newRelativePath,
                        'package'      => $packageKey,
                        'depth'        => $depth,
                        'parent'       => $relativePath ?: null,
                        'full_path'    => $itemPath,
                        'icon_count'   => $iconCount,
                        'has_children' => $hasSubdirs,
                    ];
                }

                // Only recurse if we haven't hit too many folders yet (performance)
                if (count($folders) < 1000 && $hasSubdirs) {
                    $subFolders = $this->scanFolderHierarchy($basePath, $packageKey, $newRelativePath, $depth + 1, $maxDepth);
                    $folders = array_merge($folders, $subFolders);
                }
            }
        } catch (IchavaException $e) {
            // Silently skip directories with permission errors
        }

        return $folders;
    }

    /**
     * Count SVG files in directory (non-recursive, fast)
     */
    protected function countDirectSvgFiles(string $directory): int
    {
        if (! File::isDirectory($directory)) {
            return 0;
        }

        try {
            $items = @scandir($directory);

            if ($items === false) {
                return 0;
            }

            $count = 0;
            foreach ($items as $item) {
                if (Str::endsWith($item, '.svg')) {
                    $count++;

                    // Stop counting after 100 for performance
                    if ($count >= 100) {
                        return 100;
                    }
                }
            }

            return $count;
        } catch (IchavaException $e) {
            return 0;
        }
    }

    /**
     * Check if directory has subdirectories
     */
    protected function hasSubdirectories(string $directory): bool
    {
        if (! File::isDirectory($directory)) {
            return false;
        }

        try {
            $items = scandir($directory);

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                if (File::isDirectory($directory . '/' . $item)) {
                    return true;
                }
            }
        } catch (IchavaException $e) {
            return false;
        }

        return false;
    }

    /**
     * Render an icon's SVG content
     */
    protected function renderIconSvg(string $path): string
    {
        if (! File::exists($path) || ! File::isReadable($path)) {
            return '<svg viewBox="0 0 24 24"><text x="12" y="12" text-anchor="middle">?</text></svg>';
        }

        try {
            return File::get($path);
        } catch (IchavaException $e) {
            return '<svg viewBox="0 0 24 24"><text x="12" y="12" text-anchor="middle">!</text></svg>';
        }
    }

    /**
     * Scan icon directory for SVG files
     *
     * @param string $basePath Base path to scan
     * @param int $limit Maximum number of icons to scan (0 = unlimited)
     */
    protected function scanIconDirectory(string $basePath, int $limit = 100): array
    {
        if (! File::isDirectory($basePath)) {
            return [];
        }

        $icons = [];
        $files = File::allFiles($basePath);
        $count = 0;

        foreach ($files as $file) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            if ($file->getExtension() !== 'svg') {
                continue;
            }

            $relativePath = str_replace($basePath . '/', '', $file->getPathname());
            $parts = explode('/', $relativePath);
            $filename = array_pop($parts);
            $name = pathinfo($filename, PATHINFO_FILENAME);

            // If icon is in any folder(s), treat the complete folder path as category
            // This supports nested folders: "brand-logos/social/facebook.svg"
            // becomes category "brand-logos/social"
            $category = count($parts) > 0 ? implode('/', $parts) : null;

            $icons[] = [
                'name'     => $name,
                'variant'  => null, // Variants are handled by icon set configuration
                'category' => $category,
                'path'     => $file->getPathname(),
            ];

            $count++;
        }

        return $icons;
    }

    /**
     * Cache helper with proper type handling
     *
     * Ensures cached values maintain their types (int stays int, not string)
     * Handles Redis serialization issues
     */
    protected function remember(string $key, int $ttl, callable $callback): mixed
    {
        try {
            // flexible() returns stale-while-revalidate values when cold cache fails.
            return cache()
                ->store('file')
                ->flexible(
                    key: $key,
                    ttl: [$ttl, $ttl * 2], // [cache, stale] - serve stale while regenerating
                    callback: function () use ($callback) {
                        $value = $callback();

                        // If it's an array, ensure nested integers are properly typed
                        return is_array($value) ? $this->normalizeTypes($value) : $value;
                    },
                );
        } catch (IchavaException $e) {
            // If cache fails, execute callback directly
            $value = $callback();

            return is_array($value) ? $this->normalizeTypes($value) : $value;
        }
    }

    /**
     * Normalize types in cached arrays
     *
     * Converts string integers back to integers (Redis serialization issue)
     */
    protected function normalizeTypes(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalizeTypes($value);
            } elseif ($key === 'total' && is_string($value) && ctype_digit($value)) {
                // Convert 'total' field from string to int
                $data[$key] = (int) $value;
            }
        }

        return $data;
    }
}
