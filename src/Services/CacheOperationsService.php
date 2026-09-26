<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use RuntimeException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Ichava\Events\IconCacheEvent;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Actions\ClearDiscoveryCaches;

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
    public function __construct(
        protected IconCacheService $cacheService,
        protected IconDiscoveryService $discoveryService,
        protected IconRegistry $registry,
        protected IconsManifest $manifest,
        protected Filesystem $filesystem,
        protected IchavaLogger $logger,
    ) {}

    /**
     * Clear every cache Ichava can enumerate, and name what was cleared.
     *
     * This used to call `IconCacheService::forgetPattern()`, which has never
     * existed -- a Laravel cache store cannot enumerate keys by pattern -- so
     * `cache clear`, `clear --package` and `refresh` failed on every run. What
     * can be cleared is what has an owner that knows its keys:
     *
     * - the discovery caches, retired at once by ClearDiscoveryCaches'
     *   generation counter (the md5-suffixed keys cannot be listed);
     * - each registered pack's SVG-count cache, keyed by its base path;
     * - the directory-watcher fingerprints.
     *
     * Rendered SVG content is cached under `ichava.core.cache.version` and is
     * deliberately not flushed here: IconCacheService::flush() empties the
     * host's whole store. Bump the version to abandon those entries.
     *
     * @return list<string> the cache groups cleared, for display
     */
    public function clearAll(): array
    {
        $clearedKeys = [ClearDiscoveryCaches::PREFIX . '.*'];
        $this->discoveryService->clearCache();

        foreach (array_keys($this->registry->all()) as $packageName) {
            $clearedKeys = [...$clearedKeys, ...$this->clearPackageManifest($packageName)];
        }

        if ($this->cacheService->clearWatcher()) {
            $clearedKeys[] = 'ichava.directory.fingerprints';
        }

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
     * Clear the caches that hold a registered pack's data.
     *
     * Discovery results are cached registry-wide rather than per pack, so they
     * are retired whole; the pack's own SVG-count cache is cleared by key.
     *
     * @return list<string> the cache groups cleared, for display
     *
     * @throws IchavaException when the package is not registered
     */
    public function clearPackage(string $packageName): array
    {
        $packageKeys = $this->clearPackageManifest($packageName);

        $this->discoveryService->clearCache();
        $clearedKeys = [ClearDiscoveryCaches::PREFIX . '.*', ...$packageKeys];

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
     * Prepare the caches a production deployment reads: warm the discovery
     * caches, then write the icon manifest.
     *
     * This used to delegate to `IconCacheService::generateProductionCache()`,
     * which has never existed. There is no separate "production" cache to
     * build; what a deployment benefits from is exactly `rebuild` plus
     * `manifest`, so that is what this does.
     *
     * @return array{rebuild: array<string, mixed>, manifest: array<string, mixed>}
     */
    public function generateProductionCache(?string $manifestPath = null): array
    {
        $this->logger->info('💾 Generating production cache');

        return [
            'rebuild'  => $this->rebuild(),
            'manifest' => $this->generateManifest($manifestPath),
        ];
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
     * Clear one registered pack's SVG-count cache.
     *
     * @return list<string>
     *
     * @throws IchavaException when the package is not registered
     */
    protected function clearPackageManifest(string $packageName): array
    {
        $metadata = $this->registry->get($packageName);
        $basePath = $metadata['base_path'] ?? $metadata['path'] ?? null;

        if (! is_string($basePath) || $basePath === '') {
            return [];
        }

        $this->cacheService->clearManifest($basePath);

        return [IconDiscoveryService::CACHE_PREFIX . '.manifest.' . md5($basePath)];
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
