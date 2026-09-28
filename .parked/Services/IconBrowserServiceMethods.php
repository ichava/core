<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Parked\Services;

/**
 * Members with no caller, moved here verbatim from `src/Services/IconBrowserService.php` on 2026-09-28.
 *
 * NOT autoloaded and NOT shipped (`/.parked export-ignore`). Kept so a member
 * can be restored from a file rather than from history, if a caller ever
 * appears. See `.parked/README.md` for the measurement behind each entry.
 */
trait IconBrowserServiceMethods
{
    /**
     * Get package configuration
     */
    private function getPackageConfig(string $packageKey, array $packageData): array
    {
        $configPath = $packageData['config_path'] ?? null;
        $basePath = $packageData['base_path'] ?? null;

        if ($configPath && File::exists($configPath)) {
            try {
                // Load config.json using centralized helper
                $config = Helpers::loadConfigJson($basePath, false);

                return [
                    'name'        => $config['package']['name'] ?? $packageKey,
                    'title'       => $config['package']['title'] ?? $packageKey,
                    'description' => $config['package']['description'] ?? '',
                ];
            } catch (IchavaException $e) {
                // Fall through to defaults
                $this->logger->debug('Failed to load package config', [
                    'package' => $packageKey,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return [
            'name'        => $packageKey,
            'title'       => $packageData['name'] ?? $packageKey,
            'description' => $packageData['description'] ?? '',
        ];
    }

    /**
     * Recursively scan folder tree and build hierarchy
     */
    private function scanFolderTree(
        string $basePath,
        string $package,
        array $categoryCounts,
        int $maxDepth = 3,
        int $currentDepth = 0,
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
                'id'    => "{$package}::{$folderName}",
                'type'  => 'folder',
                'name'  => $folderName,
                'label' => ucwords(str_replace(['-', '_'], ' ', $folderName)),
                // No 'path' key: $dir is the folder's ABSOLUTE server filesystem
                // path, and this array is served verbatim as the GET /icons/tree
                // response body. It was never read back by any consumer -- the
                // client only ever needs id/name/label/icon_count/children -- so
                // shipping it was a pure server-filesystem-layout disclosure with
                // no functional upside. Found while adding the React client's
                // getTree() normalizer.
                'icon_count' => $totalIconCount,
                'package'    => $package,
                'depth'      => $currentDepth,
                'expanded'   => false,
                'checked'    => false,
                'children'   => $children,
            ];
        }

        return $tree;
    }

    /**
     * Count SVG files in a directory (non-recursive, direct files only)
     */
    private function countSvgFilesInDirectory(string $directory): int
    {
        if (! File::isDirectory($directory)) {
            return 0;
        }

        $svgFiles = File::glob($directory . '/*.svg');

        return count($svgFiles);
    }

    /**
     * Count SVG files recursively in a directory and all subdirectories
     */
    private function countSvgFilesRecursive(string $directory): int
    {
        if (! File::isDirectory($directory)) {
            return 0;
        }

        $count = 0;

        // Count SVGs in this directory
        $svgFiles = File::glob($directory . '/*.svg');
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
    private function countIconsInFolder(string $package, string $folder): int
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
}
