<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Tracks the four-stage initialization lifecycle (uninitialized → migrated →
 * seeded → ready) so listeners can guard operational work behind setup
 * completion. Setup-time events run unconditionally; operational events
 * (cache invalidation etc.) wait until `isReady()` returns true.
 */
class IchavaLifecycleManager
{
    protected const CACHE_KEY = 'ichava:lifecycle:ready';

    protected const CACHE_TTL = 86400; // 24 hours

    /**
     * Constructor with IchavaLogger dependency injection
     */
    public function __construct(
        protected IchavaLogger $logger,
    ) {}

    /**
     * Check if Ichava core setup is complete
     */
    public function isReady(): bool
    {
        // Check cached state first (performance)
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if ($cached === true) {
                // Verify cache is still valid (all stages still pass)
                if ($this->hasMigrations() && $this->hasSeeds() && $this->hasCache()) {
                    return true;
                }
                // Cache is stale, reset it
                $this->reset();
            }
        } catch (Throwable $e) {
            // Cache not available
            return false;
        }

        // Verify all lifecycle stages
        $ready = $this->hasMigrations()
            && $this->hasSeeds()
            && $this->hasCache();

        // Cache the result if ready
        if ($ready) {
            $this->markAsReady();
        }

        return $ready;
    }

    /**
     * Check if migrations have run
     */
    public function hasMigrations(): bool
    {
        try {
            // Check if icons table exists with required columns
            if (! Schema::hasTable('ichava_icons')) {
                return false;
            }

            $requiredColumns = ['id', 'package', 'category', 'name', 'path', 'file_hash', 'svg_content'];
            foreach ($requiredColumns as $column) {
                if (! Schema::hasColumn('ichava_icons', $column)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->debug('⚠️ Migration check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Check if database has been seeded
     */
    public function hasSeeds(): bool
    {
        try {
            if (! $this->hasMigrations()) {
                return false;
            }

            // Check if at least some icons exist
            $count = DB::table('ichava_icons')->count();

            return $count > 0;
        } catch (Throwable $e) {
            $this->logger->debug('⚠️ Seed check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Check if cache is warm
     */
    public function hasCache(): bool
    {
        try {
            // Check if Cache store is available (session not required for Cache)
            Cache::get('ichava:test');

            return true;
        } catch (Throwable $e) {
            $this->logger->debug('⚠️ Cache check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Mark Ichava as ready (all setup complete)
     */
    public function markAsReady(): void
    {
        try {
            Cache::put(self::CACHE_KEY, true, self::CACHE_TTL);
            $this->logger->info('✅ Marked as READY, all lifecycle stages complete');
        } catch (Throwable $e) {
            $this->logger->warning('⚠️ Failed to cache Ichava ready state', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Reset lifecycle state (force re-check)
     */
    public function reset(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
            $this->logger->info('🔄 Lifecycle state reset');
        } catch (Throwable $e) {
            $this->logger->warning('⚠️ Failed to reset Ichava lifecycle state', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get current lifecycle stage
     */
    public function getStage(): string
    {
        if (! $this->hasMigrations()) {
            return 'UNINITIALIZED';
        }

        if (! $this->hasSeeds()) {
            return 'MIGRATED';
        }

        if (! $this->hasCache()) {
            return 'SEEDED';
        }

        return 'READY';
    }

    /**
     * Wait for Ichava to be ready (for tests/commands)
     *
     * @param  int  $maxAttempts  Maximum attempts to check
     * @param  int  $delayMs  Delay between checks in milliseconds
     * @return bool True if ready, false if timeout
     */
    public function waitUntilReady(int $maxAttempts = 30, int $delayMs = 1000): bool
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($this->isReady()) {
                return true;
            }

            usleep($delayMs * 1000); // Convert ms to microseconds
        }

        return false;
    }

    /**
     * Force-mark as ready (for manual intervention)
     */
    public function forceReady(): void
    {
        $this->markAsReady();
        $this->logger->warning('⚠️ Manually marked as READY (forced)');
    }
}
