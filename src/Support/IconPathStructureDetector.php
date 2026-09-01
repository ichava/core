<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Support\Facades\File;

/**
 * Detects whether a package's SVG directory uses the single-set layout
 * (`svg/files/{category}/icon.svg`) or the multi-set layout
 * (`svg/{set-name}/files/{category}/icon.svg`). See README § "Package Layout"
 * for diagrams of each shape.
 */
final class IconPathStructureDetector
{
    /**
     * Structure type constants
     */
    public const SINGLE_SET = 'single-set';

    public const MULTI_SET = 'multi-set';

    /**
     * Detect the icon path structure type
     *
     * STRATEGY:
     * 1. Check if this path is part of a multi-set hierarchy (has siblings with files/)
     * 2. Check for direct files/ directory = SINGLE-SET
     * 3. Check for subdirectories with files/ = MULTI-SET
     *
     * @param  string  $basePath  Base SVG directory path (where config.json is registered)
     * @return string Either 'single-set' or 'multi-set'
     */
    public static function detect(string $basePath): string
    {
        if (! File::isDirectory($basePath)) {
            return self::SINGLE_SET;
        }

        // STRATEGY 1: Check if we're a set directory within a multi-set parent
        // Example: /svg/test-icons should detect that /svg/ has multiple sibling sets
        $parent = dirname($basePath);
        if (File::isDirectory($parent) && basename($parent) !== '.') {
            $siblingSetDirs = self::findSetDirectories($parent);

            // If parent has multiple set directories, WE are part of a multi-set
            if (count($siblingSetDirs) > 1) {
                return self::MULTI_SET;
            }
        }

        // STRATEGY 2: Check for direct files/ directory = SINGLE-SET
        if (File::isDirectory($basePath.'/files')) {
            // But double-check: are there OTHER dirs with files/ at this level?
            $setDirs = self::findSetDirectories($basePath);

            // If we have multiple subdirs with files/, this is actually multi-set
            if (count($setDirs) > 1) {
                return self::MULTI_SET;
            }

            return self::SINGLE_SET;
        }

        // STRATEGY 3: Check for subdirectories with files/ = MULTI-SET
        $setDirectories = self::findSetDirectories($basePath);

        if (count($setDirectories) > 0) {
            return self::MULTI_SET;
        }

        // DEFAULT: Assume single-set
        return self::SINGLE_SET;
    }

    /**
     * Find all set directories (directories containing files/ subdirectory)
     *
     * @param  string  $basePath  Base SVG directory
     * @return array Array of set directory names (e.g., ['test-icons', 'ui-icons'])
     */
    public static function findSetDirectories(string $basePath): array
    {
        if (! File::isDirectory($basePath)) {
            return [];
        }

        $sets = [];
        $directories = File::directories($basePath);

        foreach ($directories as $dir) {
            $dirName = basename($dir);

            // Skip common non-set directories
            if (in_array($dirName, ['vendor', 'node_modules', '.git', 'config'])) {
                continue;
            }

            // Check if this directory has a files/ subdirectory
            if (File::isDirectory($dir.'/files')) {
                $sets[] = $dirName;
            }
        }

        return $sets;
    }

    /**
     * Get all scan paths for a given base path
     *
     * Returns array of paths where SVG files should be scanned:
     * - SINGLE-SET: ['{base}/files']
     * - MULTI-SET (from parent): ['{base}/set-1/files', '{base}/set-2/files', ...]
     * - MULTI-SET (from set itself): ['{base}/files'] (we're already in a specific set)
     *
     * @param  string  $basePath  Base SVG directory (can be parent or specific set)
     * @return array Array of absolute paths to scan
     */
    public static function getScanPaths(string $basePath): array
    {
        if (! File::isDirectory($basePath)) {
            return [];
        }

        $structure = self::detect($basePath);

        // If SINGLE-SET or we're already in a specific set directory, scan its files/
        if ($structure === self::SINGLE_SET || File::isDirectory($basePath.'/files')) {
            return [$basePath.'/files'];
        }

        // Multi-set from parent level: scan all set-name/files/ directories
        $sets = self::findSetDirectories($basePath);
        $paths = [];

        foreach ($sets as $setName) {
            $setPath = $basePath.'/'.$setName.'/files';
            if (File::isDirectory($setPath)) {
                $paths[] = $setPath;
            }
        }

        return $paths;
    }

    /**
     * Get structure information for a package
     *
     * @param  string  $basePath  Base SVG directory
     * @return array Detailed structure information
     */
    public static function getStructureInfo(string $basePath): array
    {
        $type = self::detect($basePath);
        $sets = $type === self::MULTI_SET
            ? self::findSetDirectories($basePath)
            : ['default'];

        return [
            'type' => $type,
            'base_path' => $basePath,
            'sets' => $sets,
            'scan_paths' => self::getScanPaths($basePath),
            'is_multi_set' => $type === self::MULTI_SET,
            'is_single_set' => $type === self::SINGLE_SET,
        ];
    }

    /**
     * Check if a package is multi-set
     *
     * @param  string  $basePath  Base SVG directory
     * @return bool True if multi-set, false if single-set
     */
    public static function isMultiSet(string $basePath): bool
    {
        return self::detect($basePath) === self::MULTI_SET;
    }

    /**
     * Check if a package is single-set
     *
     * @param  string  $basePath  Base SVG directory
     * @return bool True if single-set, false if multi-set
     */
    public static function isSingleSet(string $basePath): bool
    {
        return self::detect($basePath) === self::SINGLE_SET;
    }
}
