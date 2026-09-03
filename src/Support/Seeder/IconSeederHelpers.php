<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Seeder;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Path/tag/keyword extraction helpers shared by SeedIconsJob.
 * Stored paths are always relative to the package's IconRegistry base_path.
 */
trait IconSeederHelpers
{
    /**
     * Extract relative path from absolute path.
     *
     * Removes the base_path prefix to get a portable relative path.
     * Works for both SINGLE-SET and MULTI-SET structures.
     *
     * @param string $absolutePath Full filesystem path to icon
     * @param string $basePath Base path from IconRegistry
     *
     * @return string Relative path
     */
    protected function extractRelativePath(string $absolutePath, string $basePath): string
    {
        $basePath = rtrim($basePath, '/\\');
        $absolutePath = str_replace('\\', '/', $absolutePath);
        $basePath = str_replace('\\', '/', $basePath);

        $effectiveRoot = rtrim($basePath, '/') . '/';

        return str_replace($effectiveRoot, '', $absolutePath);
    }

    /**
     * Extract category slug from relative path.
     *
     * The category is the first directory AFTER 'files/' in the path.
     *
     * Examples:
     * - files/fontawesome/solid/icon.svg → fontawesome
     * - files/test-icons/icon.svg → test-icons
     *
     * @param string $relativePath Relative path to icon
     *
     * @return string|null Category slug or null if not found
     */
    protected function extractCategorySlug(string $relativePath): ?string
    {
        $parts = array_values(array_filter(explode('/', $relativePath)));

        if (empty($parts)) {
            return null;
        }

        // Find 'files' in the path and get the next part as category
        $filesIndex = array_search('files', $parts);

        if ($filesIndex !== false && isset($parts[$filesIndex + 1])) {
            $categoryPart = $parts[$filesIndex + 1];
            // Don't return the filename itself
            if (pathinfo($categoryPart, PATHINFO_EXTENSION) !== 'svg') {
                return Str::slug($categoryPart);
            }
        }

        // Fallback: use first non-file part
        foreach ($parts as $part) {
            if (pathinfo($part, PATHINFO_EXTENSION) !== 'svg') {
                return Str::slug($part);
            }
        }

        return null;
    }

    /**
     * Extract tags from path and filename.
     *
     * Tags include directory names and the icon name itself.
     *
     * @param string $relativePath Relative path to icon
     * @param string $name Icon filename (without extension)
     *
     * @return array<string> Array of tags
     */
    protected function extractTags(string $relativePath, string $name): array
    {
        $tags = [];

        $parts = array_filter(explode('/', $relativePath));
        foreach ($parts as $part) {
            if ($part !== basename($relativePath)) {
                $tags[] = Str::slug($part);
            }
        }

        $tags[] = Str::slug($name);

        return array_unique(array_filter($tags));
    }

    /**
     * Extract keywords from path and filename.
     *
     * Keywords are individual words extracted from icon name and path.
     * Words shorter than 3 characters are excluded.
     *
     * @param string $relativePath Relative path to icon
     * @param string $name Icon filename (without extension)
     *
     * @return array<string> Array of keywords
     */
    protected function extractKeywords(string $relativePath, string $name): array
    {
        $keywords = [];

        // Extract words from icon name
        $nameWords = preg_split('/[-_\s]+/', $name);
        $keywords = array_merge($keywords, $nameWords);

        // Extract words from path parts
        $pathParts = array_filter(explode('/', $relativePath));
        foreach ($pathParts as $part) {
            $partWords = preg_split('/[-_\s]+/', pathinfo($part, PATHINFO_FILENAME));
            $keywords = array_merge($keywords, $partWords);
        }

        // Normalize: lowercase and filter short words
        $keywords = array_map('strtolower', $keywords);
        $keywords = Arr::where($keywords, fn ($k) => strlen($k) > 2);

        return array_values(array_unique($keywords));
    }
}
