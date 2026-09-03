<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Listeners;

use Exception;
use Throwable;
use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Events\IconCacheEvent;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconCacheService;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;
use Simtabi\Laranail\Ichava\Services\IchavaLifecycleManager;

/**
 * Invalidates all icon-related caches when an IconCacheEvent fires.
 *
 * Guarded by IchavaLifecycleManager, only runs after core setup completes.
 */
final class InvalidateIconCache
{
    public function __construct(
        private readonly IconCacheService $cacheService,
        private readonly IconDiscoveryService $discoveryService,
        private readonly IchavaLifecycleManager $lifecycle,
        private readonly IchavaLogger $logger,
    ) {}

    /**
     * Handle the IconCacheEvent when icons are changed
     */
    public function handle(IconCacheEvent $event): void
    {
        // Only handle 'changed' events
        if (! $event->isChanged()) {
            return;
        }

        // LIFECYCLE GUARD: Operational event - only run if system ready
        if (! $this->lifecycle->hasCache()) {
            $this->logger->debug('Skipping cache invalidation - cache not available', [
                'stage' => $this->lifecycle->getStage(),
            ]);

            return;
        }

        $clearedKeys = [];
        $reason = $event->reason ?? 'Icons changed';

        try {
            // Clear all icon-related caches
            $clearedKeys = $this->clearAllIconCaches($event->package);

            // Log the invalidation
            $this->logger->info('Icon cache invalidated', [
                'package'      => $event->package,
                'reason'       => $reason,
                'cleared_keys' => count($clearedKeys),
                'metadata'     => $event->metadata,
            ]);

            // Dispatch invalidation event
            IconCacheEvent::invalidated($reason, $clearedKeys);

        } catch (Throwable $e) {
            $this->logger->error('Failed to invalidate icon cache', [
                'error'   => $e->getMessage(),
                'package' => $event->package,
                'reason'  => $reason,
            ]);
        }
    }

    /**
     * Clear all icon-related caches (including Redis)
     */
    private function clearAllIconCaches(?string $package = null): array
    {
        $clearedKeys = [];

        // Clear Icon Model Redis caches
        $iconCacheKeys = [
            'icons.counts.packages',
            'icons.counts.categories.all',
        ];

        foreach ($iconCacheKeys as $key) {
            $this->cacheService->forget($key);
            $clearedKeys[] = $key;
        }

        // If specific package, clear its category cache
        if ($package) {
            $packageKeys = [
                "ichava.icons.{$package}",
                "ichava.package.{$package}",
                "icons.counts.categories.{$package}",
            ];

            foreach ($packageKeys as $key) {
                Cache::forget($key);
                $this->cacheService->forget($key);
                $clearedKeys[] = $key;
            }
        }

        // Clear search caches (pattern-based for Redis)
        // Note: In production, consider using Redis SCAN for pattern deletion
        try {
            // Flush all ichava-prefixed cache keys
            $this->cacheService->flushPrefix();
            $clearedKeys[] = 'ichava.* (all prefixed keys)';
        } catch (Exception $e) {
            $this->logger->warning('Failed to flush cache by prefix', ['error' => $e->getMessage()]);
        }

        // Always clear global caches that depend on icon data
        $globalKeys = [
            'ichava.categories.v2',
            'ichava.packages',
            'ichava.statistics',
            'ichava.discovery.categories',
        ];

        foreach ($globalKeys as $key) {
            Cache::forget($key);
            $clearedKeys[] = $key;
        }

        // Use cache service to clear all ichava caches
        $this->cacheService->clearAll();

        // Clear discovery service cache
        $this->discoveryService->clearCache();

        return $clearedKeys;
    }
}
