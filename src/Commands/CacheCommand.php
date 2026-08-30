<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\CacheOperationsService;

/**
 * Unified Icon Cache Command
 *
 * Single command for all cache operations: clear, rebuild, refresh, generate, manifest.
 *
 * @example
 * php artisan ichava:cache clear              # Clear all caches
 * php artisan ichava:cache clear --package=X  # Clear cache for specific package
 * php artisan ichava:cache rebuild            # Rebuild caches
 * php artisan ichava:cache refresh            # Clear and rebuild
 * php artisan ichava:cache generate           # Generate production cache
 * php artisan ichava:cache manifest           # Generate icon manifest
 * php artisan ichava:cache stats              # Show cache statistics
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
final class CacheCommand extends BaseCommand
{
    protected $signature = 'ichava:cache
                            {action? : Action: clear, rebuild, refresh, generate, manifest, stats}
                            {--package= : Specific package to target}
                            {--path= : Custom path for manifest file}
                            {--force : Force operation without confirmation}';

    protected $description = 'Manage Ichava icon caches (clear, rebuild, manifest, stats)';

    protected array $validActions = ['clear', 'rebuild', 'refresh', 'generate', 'manifest', 'stats'];

    public function __construct(
        protected CacheOperationsService $cacheService,
        protected IchavaLogger $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = $this->argument('action');

        // If no action provided, prompt user to select
        if (empty($action)) {
            $action = select(
                label: 'What cache operation would you like to perform?',
                options: [
                    'clear'    => 'Clear - Remove all cached data',
                    'rebuild'  => 'Rebuild - Regenerate all caches',
                    'refresh'  => 'Refresh - Clear and rebuild caches',
                    'generate' => 'Generate - Create production-optimized cache',
                    'manifest' => 'Manifest - Generate icon manifest file',
                    'stats'    => 'Stats - Show cache statistics',
                ],
                default: 'stats',
                hint: 'Select an action to perform',
            );
        }

        return match ($action) {
            'clear'    => $this->handleClear(),
            'rebuild'  => $this->handleRebuild(),
            'refresh'  => $this->handleRefresh(),
            'generate' => $this->handleGenerate(),
            'manifest' => $this->handleManifest(),
            'stats'    => $this->handleStats(),
            default    => $this->handleInvalidAction($action, $this->validActions),
        };
    }

    /**
     * Clear all caches
     */
    protected function handleClear(): int
    {
        $package = $this->option('package');

        intro('🧹 Clearing icon caches');

        $this->startTiming();

        return $this->tryExecute(function () use ($package) {
            $clearedKeys = spin(
                callback: fn () => $package
                    ? $this->cacheService->clearPackage($package)
                    : $this->cacheService->clearAll(),
                message: $package
                    ? "Clearing cache for package: {$package}..."
                    : 'Clearing all caches...',
            );

            info('Cleared ' . count($clearedKeys) . ' cache key(s)');

            if ($this->isVerbose() && ! empty($clearedKeys)) {
                table(
                    headers: ['Cleared Cache Keys'],
                    rows: array_map(fn ($key) => [$key], $clearedKeys),
                );
            }

            outro('⏱️  Completed in ' . $this->formatMs($this->getElapsedMs()));

            return self::SUCCESS;
        }, 'Failed to clear cache');
    }

    /**
     * Rebuild caches
     */
    protected function handleRebuild(): int
    {
        intro('🔨 Rebuilding icon caches');

        return $this->tryExecute(function () {
            $result = spin(
                callback: fn () => $this->cacheService->rebuild(),
                message: 'Rebuilding caches...',
            );

            table(
                headers: ['Metric', 'Value'],
                rows: [
                    ['Categories', (string) $result['categories']],
                    ['Packages', (string) $result['packages']],
                    ['Total Icons', $this->formatNumber($result['total_icons'])],
                    ['Build Time', $result['build_time_ms'] . 'ms'],
                ],
            );

            outro('✅ Cache rebuilt successfully');

            return self::SUCCESS;
        }, 'Failed to rebuild cache');
    }

    /**
     * Refresh caches (clear + rebuild)
     */
    protected function handleRefresh(): int
    {
        intro('🔄 Refreshing icon caches');

        return $this->tryExecute(function () {
            $result = spin(
                callback: fn () => $this->cacheService->refresh(),
                message: 'Clearing and rebuilding caches...',
            );

            table(
                headers: ['Metric', 'Value'],
                rows: [
                    ['Keys Cleared', (string) $result['cleared_keys']],
                    ['Categories', (string) $result['rebuild_stats']['categories']],
                    ['Packages', (string) $result['rebuild_stats']['packages']],
                    ['Total Icons', $this->formatNumber($result['rebuild_stats']['total_icons'])],
                ],
            );

            outro('✅ Cache refreshed successfully');

            return self::SUCCESS;
        }, 'Failed to refresh cache');
    }

    /**
     * Generate production-optimized cache
     */
    protected function handleGenerate(): int
    {
        intro('⚡ Generating production cache');

        return $this->tryExecute(function () {
            spin(
                callback: fn () => $this->cacheService->generateProductionCache(),
                message: 'Generating optimized production cache...',
            );

            $this->displayCacheStats();

            outro('✅ Production cache generated');

            return self::SUCCESS;
        }, 'Failed to generate cache');
    }

    /**
     * Generate icon manifest for production deployment
     */
    protected function handleManifest(): int
    {
        $path = $this->option('path');

        intro('🎨 Generating Ichava icon manifest');

        // Skip rebuild if a fresh manifest already exists and --force was not passed.
        if (
            ! $this->option('force')
            && $this->cacheService->manifestExists($path)
            && ! $this->cacheService->manifestIsStale($path)
        ) {
            note('🟢 Manifest is fresh; skipping. Use --force to rebuild.');

            return self::SUCCESS;
        }

        if ($this->cacheService->manifestExists($path) && ! $this->option('force')) {
            $overwrite = confirm(
                label: 'Manifest exists and is stale. Overwrite?',
                default: true,
                yes: 'Yes, rebuild',
                no: 'No, cancel',
                hint: 'The existing manifest will be replaced',
            );

            if (! $overwrite) {
                warning('Manifest generation cancelled.');

                return self::FAILURE;
            }
        }

        return $this->tryExecute(function () use ($path) {
            $result = spin(
                callback: fn () => $this->cacheService->generateManifest($path),
                message: 'Generating manifest file...',
            );

            table(
                headers: ['Metric', 'Value'],
                rows: [
                    ['Packages', (string) $result['packages']],
                    ['Total Icons', $this->formatNumber($result['total_icons'])],
                    ['File Size', $this->formatBytes($result['file_size'])],
                    ['Build Time', $result['build_time_ms'] . 'ms'],
                ],
            );

            note("📁 Manifest saved to: {$result['path']}");
            note('💡 Add this command to your deployment process: php artisan ichava:cache manifest --force');

            outro('✅ Manifest generation complete!');

            return self::SUCCESS;
        }, 'Failed to generate manifest');
    }

    /**
     * Display cache statistics
     */
    protected function handleStats(): int
    {
        intro('📊 Ichava Cache Statistics');

        $this->displayCacheStats();

        return self::SUCCESS;
    }

    /**
     * Display cache statistics
     */
    protected function displayCacheStats(): void
    {
        $stats = spin(
            callback: fn () => $this->cacheService->getStatistics(),
            message: 'Gathering cache statistics...',
        );

        table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Cache Driver', $stats['driver']],
                ['Cached Packages', (string) ($stats['stats']['packages'] ?? 0)],
                ['Cached Categories', (string) ($stats['stats']['categories'] ?? 0)],
                ['Total Cache Keys', (string) ($stats['stats']['total_keys'] ?? 0)],
                ['Manifest Exists', $stats['manifest_exists'] ? '✅ Yes' : '❌ No'],
                ['Manifest Stale', $stats['manifest_stale'] ? '⚠️  Yes' : '✅ No'],
            ],
        );
    }
}
