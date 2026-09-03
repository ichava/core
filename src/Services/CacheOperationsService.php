<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Exception;
use RuntimeException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Ichava\Events\IconCacheEvent;

/**
 * CacheOperationsService
 *
 * Centralized service for all Ichava cache operations including:
 * - Cache clearing (all or by pattern)
 * - Cache warming/rebuilding
 * - Manifest generation
 * - Statistics
 *
 * Extracted from BaseCacheCommand for maximum reusability.
 */
class CacheOperationsService
{
    /**
     * Cache key patterns used by Ichava
     */
    protected const CACHE_PATTERNS = [
        'ichava.*',
        'icons.*',
        'packages.*',
    ];

    public function __construct(
        protected IconCacheService $cacheService,
        protected IconDiscoveryService $discoveryService,
        protected IconRegistry $registry,
        protected IconsManifest $manifest,
        protected Filesystem $filesystem,
        protected IchavaLogger $logger,
    ) {}

    /**
     * Clear all Ichava caches
     */
    public function clearAll(): array
    {
        $clearedKeys = [];

        foreach (self::CACHE_PATTERNS as $pattern) {
            try {
                $keys = $this->cacheService->forgetPattern($pattern);
                $clearedKeys = array_merge($clearedKeys, $keys);
            } catch (Exception $e) {
                $this->logger->warning("Failed to clear cache pattern: {$pattern}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Dispatch cache invalidated event
        Event::dispatch(IconCacheEvent::invalidated(
            reason: 'Manual clear (all)',
            clearedKeys: $clearedKeys,
        ));

        $this->logger->info('🧹 Cleared all Ichava caches', [
            'keys_cleared' => count($clearedKeys),
        ]);

        return $clearedKeys;
    }

    /**
     * Clear cache for a specific package
     */
    public function clearPackage(string $packageName): array
    {
        $clearedKeys = $this->cacheService->forgetPattern("ichava.{$packageName}.*");

        Event::dispatch(IconCacheEvent::invalidated(
            reason: "Manual clear for {$packageName}",
            clearedKeys: $clearedKeys,
        ));

        $this->logger->info("Cleared cache for package: {$packageName}", [
            'keys_cleared' => count($clearedKeys),
        ]);

        return $clearedKeys;
    }

    /**
     * Rebuild all caches by warming them up
     */
    public function rebuild(): array
    {
        $startTime = microtime(true);

        $this->logger->info('💾 Rebuilding Ichava caches');

        // Warm up caches by calling discovery services
        $categories = $this->discoveryService->getAllCategories();
        $packages = $this->discoveryService->getPackages();
        $stats = $this->discoveryService->getStatistics();

        $duration = (microtime(true) - $startTime) * 1000;

        // Dispatch cache rebuilt event
        Event::dispatch(IconCacheEvent::rebuilt(
            iconCount: $stats['total_icons'] ?? 0,
            categoryCount: count($categories),
            packageCount: count($packages),
            buildTimeMs: $duration,
        ));

        $result = [
            'categories'    => count($categories),
            'packages'      => count($packages),
            'total_icons'   => $stats['total_icons'] ?? 0,
            'build_time_ms' => round($duration, 2),
        ];

        $this->logger->info('✅ Cache rebuild complete', $result);

        return $result;
    }

    /**
     * Refresh caches (clear + rebuild)
     */
    public function refresh(): array
    {
        $clearedKeys = $this->clearAll();
        $rebuildStats = $this->rebuild();

        return [
            'cleared_keys'  => count($clearedKeys),
            'rebuild_stats' => $rebuildStats,
        ];
    }

    /**
     * Generate production-optimized cache
     */
    public function generateProductionCache(): array
    {
        $this->logger->info('💾 Generating production cache');

        $this->cacheService->generateProductionCache();

        return $this->getStatistics();
    }

    /**
     * Generate icon manifest for production deployment
     */
    public function generateManifest(?string $path = null): array
    {
        $startTime = microtime(true);

        $manifest = $this->manifestFor($path);
        $manifestPath = $manifest->getPath();

        $this->logger->info('💾 Generating icon manifest', ['path' => $manifestPath]);

        if (empty($this->registry->all())) {
            throw new RuntimeException('No icon packages registered. Cannot generate manifest.');
        }

        if (! $manifest->write($this->registry)) {
            throw new RuntimeException("Failed to write manifest to: {$manifestPath}");
        }

        $stats = $manifest->getStats() ?? [];
        $duration = (microtime(true) - $startTime) * 1000;

        $result = [
            'path'          => $manifestPath,
            'packages'      => $stats['total_sets'] ?? 0,
            'total_icons'   => $stats['total_icons'] ?? 0,
            'file_size'     => $manifest->getSize(),
            'build_time_ms' => round($duration, 2),
        ];

        $this->logger->info('✅ Manifest generated', $result);

        return $result;
    }

    /**
     * Check if manifest exists
     */
    public function manifestExists(?string $path = null): bool
    {
        return $this->manifestFor($path)->exists();
    }

    /**
     * Check if manifest is missing or older than the given age.
     */
    public function manifestIsStale(?string $path = null, int $maxAge = 3600): bool
    {
        return $this->manifestFor($path)->isStale($maxAge);
    }

    /**
     * Get manifest data
     */
    public function getManifest(?string $path = null): ?array
    {
        return $this->manifestFor($path)->load();
    }

    /**
     * Get cache statistics
     */
    public function getStatistics(): array
    {
        return [
            'driver'          => config('cache.default'),
            'stats'           => $this->cacheService->getStats(),
            'manifest_exists' => $this->manifestExists(),
            'manifest_stale'  => $this->manifestIsStale(),
        ];
    }

    /**
     * Resolve the IconsManifest to operate on. Returns the singleton when no
     * override is supplied, otherwise a one-off instance bound to $path so the
     * caller can target a custom location (CLI --path option).
     */
    protected function manifestFor(?string $path): IconsManifest
    {
        if ($path === null || $path === '') {
            return $this->manifest;
        }

        return new IconsManifest($this->filesystem, $path);
    }
}
