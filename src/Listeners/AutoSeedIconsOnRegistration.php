<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Listeners;

use Exception;
use Throwable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;
use Simtabi\Laranail\Ichava\Events\IconRegistrationEvent;
use Simtabi\Laranail\Ichava\Services\IchavaLifecycleManager;

/**
 * Seeds icons when a new package is registered.
 *
 * Disabled by default, set `ichava.database.auto_seed = true` to enable.
 * Only seeds when the package has zero icons in the database, preventing
 * duplicate work. The recommended approach is `php artisan ichava:database seed`.
 */
class AutoSeedIconsOnRegistration
{
    public function __construct(
        protected IchavaLifecycleManager $lifecycle,
        protected IchavaLogger $logger,
        protected IchavaSeeder $seeder,
        protected IconRegistry $registry,
    ) {}

    /**
     * Handle the event
     */
    public function handle(IconRegistrationEvent $event): void
    {
        // Only handle 'registered' events (successful registration)
        if (! $event->isRegistered()) {
            return;
        }

        // Skip auto-seeding during migration/seeding commands (use explicit commands instead)
        if ($this->isRunningDatabaseCommand()) {
            $this->logger->debug('⏭️ Skipping auto-seed during database command');

            return;
        }

        // Check if migrations exist before attempting to seed
        if (! $this->lifecycle->hasMigrations()) {
            $this->logMigrationsWarningOnce($event->name);

            return;
        }

        // Check if auto-seeding is enabled (disabled by default)
        if (! config('ichava.core.database.auto_seed', false)) {
            return; // Silent return - this is the default state
        }

        // Check if database seeding is enabled
        if (! config('ichava.core.database.enabled', true)) {
            $this->logger->debug('ℹ️ Database is disabled in config');

            return;
        }

        $packageName = $event->metadata['package_name'] ?? $event->name;

        // Skip if package already has icons in database (prevents duplicate seeding)
        if ($this->packageHasIcons($packageName)) {
            $this->logger->debug("Package already seeded, skipping: {$packageName}");

            return;
        }

        // Skip if seeding is already in progress for this package (race condition prevention)
        if ($this->isSeedingInProgress($packageName)) {
            $this->logger->debug("Seeding already in progress for: {$packageName}");

            return;
        }

        $useQueue = config('ichava.core.database.use_queue', true);
        $chunkSize = (int) config('ichava.core.database.batch_size', IchavaSeeder::DEFAULT_CHUNK_SIZE);

        $this->logger->info("Auto-seeding package: {$packageName}", [
            'method'     => $useQueue ? 'queue' : 'sync',
            'chunk_size' => $chunkSize,
        ]);

        try {
            // Mark seeding as in progress
            $this->markSeedingInProgress($packageName);

            // Get SVG path from registry
            $svgPath = $this->getSvgPath($packageName);

            if (! $svgPath) {
                $this->logger->warning("Could not determine SVG path for package: {$packageName}");
                $this->clearSeedingInProgress($packageName);

                return;
            }

            // Seed both terms AND icons using seedPackage()
            $result = $this->seeder->seedPackage($packageName, $svgPath, $useQueue, $chunkSize);

            if ($result['error']) {
                $this->logger->warning("Auto-seeding failed for: {$packageName}", ['error' => $result['error']]);
                $this->clearSeedingInProgress($packageName);
            } else {
                $logContext = [
                    'terms_seeded' => $result['terms'],
                    'icons_seeded' => $result['icons'],
                ];

                if ($result['batch_id']) {
                    $logContext['batch_id'] = $result['batch_id'];
                    $this->logger->info("Auto-seeding dispatched for: {$packageName}", $logContext);
                    // Keep lock until jobs complete (cleared by job callbacks or TTL)
                } else {
                    $logContext['processed'] = $result['processed'];
                    $this->logger->info("Auto-seeded package: {$packageName}", $logContext);
                    $this->clearSeedingInProgress($packageName);
                }
            }
        } catch (Throwable $e) {
            // Log error but don't throw - seeding failure shouldn't break registration
            $this->logger->error("Failed to auto-seed package: {$packageName}", $e, [
                'package' => $packageName,
            ]);
            $this->clearSeedingInProgress($packageName);
        }
    }

    /**
     * Determine whether the listener should be queued
     */
    public function shouldQueue(): bool
    {
        return false;
    }

    /**
     * Get SVG path for a package
     */
    protected function getSvgPath(string $packageName): ?string
    {
        try {
            $iconSet = $this->registry->set($packageName);

            return $iconSet->basePath();
        } catch (Exception $e) {
            // Try fallback from registry data
            $packages = $this->registry->all();

            return $packages[$packageName]['base_path'] ?? null;
        }
    }

    /**
     * Check if package already has icons in database
     */
    protected function packageHasIcons(string $packageName): bool
    {
        try {
            return Icon::where('package', $packageName)->exists();
        } catch (Throwable $e) {
            // If we can't check, assume not seeded
            return false;
        }
    }

    /**
     * Check if seeding is already in progress for this package
     */
    protected function isSeedingInProgress(string $packageName): bool
    {
        $cacheKey = "ichava:seeding:{$packageName}";

        try {
            return Cache::has($cacheKey);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Mark seeding as in progress.
     *
     * Uses a 5-minute cache TTL as a hard ceiling on the orphan window: if a
     * worker is hard-killed between mark and clear, the marker self-expires
     * within 5 minutes. We also register a PHP shutdown hook so a graceful
     * worker exit (SIGTERM, php-fpm reload) clears the marker immediately,
     * not after the full TTL.
     */
    protected function markSeedingInProgress(string $packageName): void
    {
        $cacheKey = "ichava:seeding:{$packageName}";

        try {
            Cache::put($cacheKey, true, 300);
            register_shutdown_function(static function () use ($cacheKey): void {
                try {
                    Cache::forget($cacheKey);
                } catch (Throwable) {
                    // Ignore - cache may be unavailable at shutdown
                }
            });
        } catch (Throwable) {
            // Continue anyway
        }
    }

    /**
     * Clear seeding in progress flag
     */
    protected function clearSeedingInProgress(string $packageName): void
    {
        $cacheKey = "ichava:seeding:{$packageName}";

        try {
            Cache::forget($cacheKey);
        } catch (Throwable) {
            // Ignore
        }
    }

    /**
     * Log migrations warning once per TTL to prevent spam
     */
    protected function logMigrationsWarningOnce(string $packageName): void
    {
        $ttl = (int) config('ichava.core.logging.deduplication_ttl', 300);
        $cacheKey = "ichava:migration-warning:{$packageName}";
        $shouldLog = true;

        if ($ttl > 0) {
            try {
                if (Cache::has($cacheKey)) {
                    $shouldLog = false;
                } else {
                    Cache::put($cacheKey, true, $ttl);
                }
            } catch (Throwable) {
                // Cache unavailable, log anyway
            }
        }

        if ($shouldLog) {
            $this->logger->info('⏭️ Skipping auto-seed - migrations not run yet', [
                'package' => $packageName,
                'stage'   => $this->lifecycle->getStage(),
                'tip'     => 'Run: php artisan migrate',
            ]);
        }
    }

    /**
     * Check if a database-related command is currently running
     */
    protected function isRunningDatabaseCommand(): bool
    {
        if (! app()->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];
        $command = implode(' ', $argv);

        // Check for database commands where explicit seeding is expected
        return Str::contains($command, 'migrate')
            || Str::contains($command, 'db:seed')
            || Str::contains($command, 'db:wipe')
            || Str::contains($command, 'ichava:database')
            || Str::contains($command, 'schema:dump');
    }
}
