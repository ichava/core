<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Simtabi\Laranail\Ichava\Events\IconCacheEvent;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Support\IconPathStructureDetector;
use Throwable;

/**
 * Watches icon directories for changes and keeps the icon cache + DB in sync.
 * Supports both single-set and multi-set layouts via IconPathStructureDetector;
 * stored paths are always relative for cross-environment portability.
 */
class IconWatcherService
{
    private const FINGERPRINT_CACHE_KEY = 'ichava.directory.fingerprints';

    private const CHANGE_DETECTOR_KEY = 'ichava.directory_fingerprints';

    private const LAST_SCAN_KEY = 'ichava:file_watcher:last_scan';

    private const LOCK_KEY = 'ichava:file_watcher:lock';

    private const LOCK_TTL = 300; // 5 minutes

    public function __construct(
        protected IconRegistry $registry,
        protected IchavaLogger $logger,
    ) {}

    /**
     * Get last scan time
     */
    public static function getLastScanTime(): ?Carbon
    {
        return Cache::get(self::LAST_SCAN_KEY);
    }

    /**
     * Generate fingerprint for a directory
     * Uses: last modified time + file count + total size for fast detection
     */
    public function generateFingerprint(string $path): string
    {
        if (! File::isDirectory($path)) {
            return '';
        }

        $fingerprint = [
            'path' => $path,
            'modified' => $this->getLastModifiedTime($path),
            'count' => $this->countSvgFiles($path),
            'size' => $this->getTotalSize($path),
        ];

        return md5(serialize($fingerprint));
    }

    /**
     * Check if directory has changed since last check
     */
    public function hasChanged(string $path): bool
    {
        try {
            $store = cache()->store('file');
            $fingerprints = $store->get(self::FINGERPRINT_CACHE_KEY, []);
            $currentFingerprint = $this->generateFingerprint($path);
            $cachedFingerprint = $fingerprints[$path] ?? null;

            return $currentFingerprint !== $cachedFingerprint;
        } catch (IchavaException $e) {
            // If cache fails, assume changed to trigger rebuild
            return true;
        }
    }

    /**
     * Update fingerprint cache for a path
     */
    public function updateFingerprint(string $path): void
    {
        try {
            $store = cache()->store('file');
            $fingerprints = $store->get(self::FINGERPRINT_CACHE_KEY, []);
            $fingerprints[$path] = $this->generateFingerprint($path);

            $store->forever(self::FINGERPRINT_CACHE_KEY, $fingerprints);
        } catch (IchavaException $e) {
            // Silently fail if cache is unavailable
        }
    }

    /**
     * Clear fingerprint for a specific path
     */
    public function clearFingerprint(string $path): void
    {
        try {
            $store = cache()->store('file');
            $fingerprints = $store->get(self::FINGERPRINT_CACHE_KEY, []);
            unset($fingerprints[$path]);
            $store->forever(self::FINGERPRINT_CACHE_KEY, $fingerprints);
        } catch (IchavaException $e) {
            // Silently fail if cache is unavailable
        }
    }

    /**
     * Clear all fingerprints
     */
    public function clearAll(): void
    {
        try {
            cache()->store('file')->forget(self::FINGERPRINT_CACHE_KEY);
            cache()->forget(self::CHANGE_DETECTOR_KEY);
        } catch (IchavaException $e) {
            // Silently fail if cache is unavailable
        }
    }

    /**
     * Get all watched directories
     */
    public function getWatchedDirectories(): array
    {
        try {
            return array_keys(cache()->store('file')->get(self::FINGERPRINT_CACHE_KEY, []));
        } catch (IchavaException $e) {
            return [];
        }
    }

    /**
     * Get fingerprint statistics
     */
    public function getStats(): array
    {
        try {
            $fingerprints = cache()->store('file')->get(self::FINGERPRINT_CACHE_KEY, []);

            return [
                'total_watched' => count($fingerprints),
                'directories' => array_keys($fingerprints),
            ];
        } catch (IchavaException $e) {
            return [
                'total_watched' => 0,
                'directories' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Force update all fingerprints for registered packages
     */
    public function updateAll(array $paths): void
    {
        foreach ($paths as $path) {
            if (File::isDirectory($path)) {
                $this->updateFingerprint($path);
            }
        }
    }

    /**
     * Detect changes across all registered packages
     * Returns true if changes detected
     */
    public function detectChanges(): bool
    {
        if (! config('app.debug')) {
            return false; // Only run in dev mode
        }

        $currentFingerprints = $this->generateFingerprints();
        $cachedFingerprints = Cache::get(self::CHANGE_DETECTOR_KEY, []);

        // First run - store fingerprints
        if (empty($cachedFingerprints)) {
            Cache::put(self::CHANGE_DETECTOR_KEY, $currentFingerprints, 3600);

            return false;
        }

        // Compare fingerprints
        $changed = $this->compareFingerprints($cachedFingerprints, $currentFingerprints);

        if (! empty($changed)) {
            // Update cached fingerprints
            Cache::put(self::CHANGE_DETECTOR_KEY, $currentFingerprints, 3600);

            // Fire events for each changed package
            foreach ($changed as $package => $reason) {
                Event::dispatch(IconCacheEvent::changed(
                    package: $package,
                    reason: $reason,
                    metadata: ['auto_detected' => true, 'environment' => 'development'],
                ));
            }

            return true;
        }

        return false;
    }

    /**
     * Force clear fingerprint cache
     */
    public function clearFingerprints(): void
    {
        Cache::forget(self::CHANGE_DETECTOR_KEY);
        $this->clearAll();
    }

    /**
     * Watch for file changes and sync database
     */
    public function watch(): array
    {
        // Prevent concurrent runs
        if (! $this->acquireLock()) {
            $this->logger->debug('👁 File watcher already running, skipping...');

            return ['status' => 'skipped', 'reason' => 'already_running'];
        }

        try {
            $startTime = microtime(true);
            $stats = $this->scanAndSync();
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $stats['duration_ms'] = $duration;
            $stats['timestamp'] = now()->toDateTimeString();

            // Update last scan time
            Cache::put(self::LAST_SCAN_KEY, now(), now()->addDays(7));

            if ($stats['total_changes'] > 0) {
                $this->logger->info('👁 File watcher detected changes', $stats);
            }

            return $stats;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Force scan (bypass lock)
     */
    public function forceScan(): array
    {
        Cache::forget(self::LOCK_KEY);

        return $this->watch();
    }

    /**
     * Generate fingerprints for all icon directories
     */
    protected function generateFingerprints(): array
    {
        $fingerprints = [];
        $packages = $this->registry->all();

        foreach ($packages as $packageKey => $package) {
            $path = $package['path'] ?? null;

            if (! $path || ! File::isDirectory($path)) {
                continue;
            }

            $fingerprints[$packageKey] = $this->generateDetailedFingerprint($path);
        }

        return $fingerprints;
    }

    /**
     * Generate detailed fingerprint for change detection
     */
    protected function generateDetailedFingerprint(string $path): array
    {
        try {
            $files = File::allFiles($path);
            $svgFiles = Arr::where($files, fn ($file) => $file->getExtension() === 'svg');

            $latestMtime = 0;
            $totalSize = 0;

            foreach ($svgFiles as $file) {
                $mtime = $file->getMTime();
                if ($mtime > $latestMtime) {
                    $latestMtime = $mtime;
                }
                $totalSize += $file->getSize();
            }

            return [
                'count' => count($svgFiles),
                'latest_mtime' => $latestMtime,
                'total_size' => $totalSize,
                'path_hash' => md5($path),
            ];
        } catch (Throwable) {
            return [
                'count' => 0,
                'latest_mtime' => 0,
                'total_size' => 0,
                'path_hash' => md5($path),
            ];
        }
    }

    /**
     * Compare fingerprints and return changed packages
     */
    protected function compareFingerprints(array $old, array $new): array
    {
        $changed = [];

        // Check for modified packages
        foreach ($new as $package => $fingerprint) {
            if (! isset($old[$package])) {
                $changed[$package] = 'New package added';

                continue;
            }

            $oldFingerprint = $old[$package];

            // Check file count change
            if ($fingerprint['count'] !== $oldFingerprint['count']) {
                $diff = $fingerprint['count'] - $oldFingerprint['count'];
                $changed[$package] = $diff > 0
                    ? "Added {$diff} icon(s)"
                    : 'Removed '.abs($diff).' icon(s)';

                continue;
            }

            // Check modification time
            if ($fingerprint['latest_mtime'] > $oldFingerprint['latest_mtime']) {
                $changed[$package] = 'Icon files modified';

                continue;
            }

            // Check total size
            if ($fingerprint['total_size'] !== $oldFingerprint['total_size']) {
                $changed[$package] = 'Icon file sizes changed';
            }
        }

        // Check for removed packages
        foreach ($old as $package => $fingerprint) {
            if (! isset($new[$package])) {
                $changed[$package] = 'Package removed';
            }
        }

        return $changed;
    }

    /**
     * Scan all packages and sync changes
     */
    protected function scanAndSync(): array
    {
        $packages = $this->registry->all();

        $stats = [
            'packages_scanned' => 0,
            'new_icons' => 0,
            'updated_icons' => 0,
            'deleted_icons' => 0,
            'total_changes' => 0,
        ];

        foreach ($packages as $package => $metadata) {
            $packageStats = $this->syncPackage($package, $metadata);

            $stats['packages_scanned']++;
            $stats['new_icons'] += $packageStats['new'];
            $stats['updated_icons'] += $packageStats['updated'];
            $stats['deleted_icons'] += $packageStats['deleted'];
        }

        $stats['total_changes'] = $stats['new_icons'] + $stats['updated_icons'] + $stats['deleted_icons'];

        return $stats;
    }

    /**
     * Sync a single package with database
     */
    protected function syncPackage(string $package, array $metadata): array
    {
        $stats = [
            'new' => 0,
            'updated' => 0,
            'deleted' => 0,
        ];

        $basePath = $metadata['base_path'] ?? $metadata['path'] ?? null;

        if (! $basePath || ! File::isDirectory($basePath)) {
            return $stats;
        }

        // Get current icons from database
        $dbIcons = Icon::where('package', $package)
            ->get()
            ->keyBy('path');

        // Scan file system
        $diskIcons = $this->scanDiskIcons($basePath, $package);

        // Process each disk icon
        foreach ($diskIcons as $path => $iconData) {
            if (! $dbIcons->has($path)) {
                // New icon
                Icon::create($iconData);
                $stats['new']++;

                $this->logger->debug('New icon detected', [
                    'package' => $package,
                    'name' => $iconData['name'],
                    'path' => $path,
                ]);
            } elseif ($dbIcons[$path]->file_hash !== $iconData['file_hash']) {
                // Updated icon
                $dbIcons[$path]->update($iconData);
                $stats['updated']++;

                $this->logger->debug('Icon updated', [
                    'package' => $package,
                    'name' => $iconData['name'],
                    'path' => $path,
                ]);
            }
        }

        // Find deleted icons
        $diskPaths = array_keys($diskIcons);
        $deletedIcons = $dbIcons->filter(fn ($icon) => ! in_array($icon->path, $diskPaths));

        if ($deletedIcons->isNotEmpty()) {
            $deletedIcons->each(function ($icon) use (&$stats, $package) {
                $icon->delete();
                $stats['deleted']++;

                $this->logger->debug('Icon deleted', [
                    'package' => $package,
                    'name' => $icon->name,
                    'path' => $icon->path,
                ]);
            });
        }

        return $stats;
    }

    /**
     * Scan disk for icons
     */
    protected function scanDiskIcons(string $basePath, string $package): array
    {
        $icons = [];

        try {
            $files = File::allFiles($basePath);
        } catch (IchavaException $e) {
            $this->logger->error('Failed to scan directory', [
                'package' => $package,
                'path' => $basePath,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        foreach ($files as $file) {
            if ($file->getExtension() !== 'svg') {
                continue;
            }

            try {
                $iconData = $this->extractIconData($file, $package, $basePath);
                $icons[$file->getPathname()] = $iconData;
            } catch (IchavaException $e) {
                $this->logger->warning('Failed to process icon file', [
                    'package' => $package,
                    'file' => $file->getPathname(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $icons;
    }

    /**
     * Extract icon data from file
     */
    /**
     * Extract icon data from file
     *
     * Handles path extraction for:
     * - CORE: svg/{set-name}/files/{category}/icon.svg → {set-name}/files/{category}/icon.svg
     * - STANDARD: svg/files/{category}/icon.svg → files/{category}/icon.svg
     */
    protected function extractIconData($file, string $package, string $basePath): array
    {
        // Get relative path from base_path (preserves set-name/files/ or files/ structure)
        $absolutePath = $file->getPathname();
        $relativePath = str_replace(rtrim($basePath, '/\\').DIRECTORY_SEPARATOR, '', $absolutePath);

        // Extract category from the path (everything except filename)
        $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
        $filename = array_pop($pathParts);
        $category = ! empty($pathParts) ? implode('/', $pathParts) : null;

        $name = $file->getFilenameWithoutExtension();
        $content = File::get($absolutePath);
        $svgMetadata = $this->extractSvgMetadata($content);

        return [
            'package' => $package,
            'name' => $name,
            'path' => $relativePath, // Store relative path (includes set-name/files/ or files/)
            'file_hash' => md5_file($absolutePath),
            'tags' => $this->generateTags($name, $category),
            'keywords' => $this->generateKeywords($name, $category),
            'attributes' => $svgMetadata,
            'metadata' => [
                'file_size' => $file->getSize(),
                'file_modified_at' => Carbon::createFromTimestamp($file->getMTime())->toIso8601String(),
                'category' => $category, // Store in metadata for reference
            ],
            'file_modified_at' => Carbon::createFromTimestamp($file->getMTime()),
            'updated_at' => now(),
        ];
    }

    /**
     * Get last modified time of directory (recursive)
     */
    protected function getLastModifiedTime(string $path): int
    {
        if (! File::isDirectory($path)) {
            return 0;
        }

        $latest = filemtime($path);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && Str::endsWith($file->getFilename(), '.svg')) {
                    $mtime = $file->getMTime();
                    if ($mtime > $latest) {
                        $latest = $mtime;
                    }
                }
            }
        } catch (IchavaException $e) {
            return filemtime($path);
        }

        return $latest;
    }

    /**
     * Count SVG files (fast)
     */
    protected function countSvgFiles(string $path): int
    {
        if (! File::isDirectory($path)) {
            return 0;
        }

        $count = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && Str::endsWith($file->getFilename(), '.svg')) {
                    $count++;
                }
            }
        } catch (IchavaException $e) {
            return 0;
        }

        return $count;
    }

    /**
     * Get total size of SVG files
     */
    protected function getTotalSize(string $path): int
    {
        if (! File::isDirectory($path)) {
            return 0;
        }

        $size = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && Str::endsWith($file->getFilename(), '.svg')) {
                    $size += $file->getSize();
                }
            }
        } catch (IchavaException $e) {
            return 0;
        }

        return $size;
    }

    /**
     * Extract category from path
     */
    protected function extractCategory(string $path): ?string
    {
        $path = trim($path, '/\\');

        if (empty($path)) {
            return null;
        }

        $parts = explode('/', str_replace('\\', '/', $path));

        return end($parts) ?: null;
    }

    /**
     * Extract SVG metadata
     */
    protected function extractSvgMetadata(string $svg): array
    {
        $metadata = [];

        if (preg_match('/viewBox=["\']([^"\']+)["\']/', $svg, $matches)) {
            $metadata['viewbox'] = $matches[1];
        }

        if (preg_match('/width=["\'](\d+)["\']/', $svg, $matches)) {
            $metadata['width'] = (int) $matches[1];
        }

        if (preg_match('/height=["\'](\d+)["\']/', $svg, $matches)) {
            $metadata['height'] = (int) $matches[1];
        }

        return $metadata;
    }

    /**
     * Generate search keywords
     */
    protected function generateKeywords(string $name, ?string $category): array
    {
        $keywords = [];
        $nameParts = preg_split('/[-_]/', $name);
        $keywords = array_merge($keywords, $nameParts);

        if ($category) {
            $keywords[] = $category;
            $categoryParts = preg_split('/[-_]/', $category);
            $keywords = array_merge($keywords, $categoryParts);
        }

        $keywords[] = $name;

        return array_values(array_unique(array_filter($keywords)));
    }

    /**
     * Generate tags
     */
    protected function generateTags(string $name, ?string $category): array
    {
        $tags = [];
        $parts = preg_split('/[-_]/', $name);
        $tags = array_merge($tags, array_filter($parts, fn ($p) => strlen($p) > 2));

        if ($category) {
            $tags[] = $category;
        }

        return array_values(array_unique($tags));
    }

    /**
     * Acquire lock to prevent concurrent runs
     */
    protected function acquireLock(): bool
    {
        return Cache::add(self::LOCK_KEY, true, self::LOCK_TTL);
    }

    /**
     * Release lock
     */
    protected function releaseLock(): void
    {
        Cache::forget(self::LOCK_KEY);
    }
}
