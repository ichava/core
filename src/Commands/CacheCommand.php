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

use Simtabi\Laranail\Console\Tools\Support\Status;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Console\Tools\Support\FileSize;
use Simtabi\Laranail\Console\Tools\Support\TimeFormat;
use Simtabi\Laranail\Console\Tools\Widgets\MetricTable;
use Simtabi\Laranail\Console\Tools\Widgets\StatusBadge;
use Simtabi\Laranail\Ichava\Services\CacheOperationsService;

/**
 * Unified Icon Cache Command
 *
 * Single command for all cache operations: clear, rebuild, refresh, generate, manifest.
 *
 * @example
 * php artisan ichava::ichava-core.cache clear              # Clear all caches
 * php artisan ichava::ichava-core.cache clear --package=X  # Clear cache for specific package
 * php artisan ichava::ichava-core.cache rebuild            # Rebuild caches
 * php artisan ichava::ichava-core.cache refresh            # Clear and rebuild
 * php artisan ichava::ichava-core.cache generate           # Generate production cache
 * php artisan ichava::ichava-core.cache manifest           # Generate icon manifest
 * php artisan ichava::ichava-core.cache stats              # Show cache statistics
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
final class CacheCommand extends BaseCommand
{
    protected $signature = 'ichava::ichava-core.cache
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
                label: __('ichava/ichava-core::commands.cache.select'),
                options: [
                    'clear'    => __('ichava/ichava-core::commands.cache.options.clear'),
                    'rebuild'  => __('ichava/ichava-core::commands.cache.options.rebuild'),
                    'refresh'  => __('ichava/ichava-core::commands.cache.options.refresh'),
                    'generate' => __('ichava/ichava-core::commands.cache.options.generate'),
                    'manifest' => __('ichava/ichava-core::commands.cache.options.manifest'),
                    'stats'    => __('ichava/ichava-core::commands.cache.options.stats'),
                ],
                default: 'stats',
                hint: __('ichava/ichava-core::commands.cache.select_hint'),
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

        intro(__('ichava/ichava-core::commands.cache.clear.intro'));

        $this->startTiming();

        return $this->tryExecute(function () use ($package) {
            $clearedKeys = spin(
                callback: fn () => $package
                    ? $this->cacheService->clearPackage($package)
                    : $this->cacheService->clearAll(),
                message: $package
                    ? __('ichava/ichava-core::commands.cache.clear.clearing_package', ['package' => $package])
                    : __('ichava/ichava-core::commands.cache.clear.clearing_all'),
            );

            info(__('ichava/ichava-core::commands.cache.clear.cleared', ['count' => count($clearedKeys)]));

            if ($this->isVerbose() && ! empty($clearedKeys)) {
                table(
                    headers: [__('ichava/ichava-core::commands.cache.clear.cleared_keys')],
                    rows: array_map(fn ($key) => [$key], $clearedKeys),
                );
            }

            outro(__('ichava/ichava-core::commands.common.completed_in', ['time' => TimeFormat::fromMillis($this->getElapsedMs())]));

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.cache.clear.failed'));
    }

    /**
     * Rebuild caches
     */
    protected function handleRebuild(): int
    {
        intro(__('ichava/ichava-core::commands.cache.rebuild.intro'));

        return $this->tryExecute(function () {
            $result = spin(
                callback: fn () => $this->cacheService->rebuild(),
                message: __('ichava/ichava-core::commands.cache.rebuild.rebuilding'),
            );

            MetricTable::make()
                ->metrics([
                    __('ichava/ichava-core::commands.cache.metric.categories')  => (int) $result['categories'],
                    __('ichava/ichava-core::commands.cache.metric.packages')    => (int) $result['packages'],
                    __('ichava/ichava-core::commands.cache.metric.total_icons') => (int) $result['total_icons'],
                    __('ichava/ichava-core::commands.cache.metric.build_time')  => TimeFormat::fromMillis((float) $result['build_time_ms']),
                ])
                ->render($this->output);

            outro(__('ichava/ichava-core::commands.cache.rebuild.done'));

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.cache.rebuild.failed'));
    }

    /**
     * Refresh caches (clear + rebuild)
     */
    protected function handleRefresh(): int
    {
        intro(__('ichava/ichava-core::commands.cache.refresh.intro'));

        return $this->tryExecute(function () {
            $result = spin(
                callback: fn () => $this->cacheService->refresh(),
                message: __('ichava/ichava-core::commands.cache.refresh.refreshing'),
            );

            MetricTable::make()
                ->metrics([
                    __('ichava/ichava-core::commands.cache.metric.keys_cleared') => (int) $result['cleared_keys'],
                    __('ichava/ichava-core::commands.cache.metric.categories')   => (int) $result['rebuild_stats']['categories'],
                    __('ichava/ichava-core::commands.cache.metric.packages')     => (int) $result['rebuild_stats']['packages'],
                    __('ichava/ichava-core::commands.cache.metric.total_icons')  => (int) $result['rebuild_stats']['total_icons'],
                ])
                ->render($this->output);

            outro(__('ichava/ichava-core::commands.cache.refresh.done'));

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.cache.refresh.failed'));
    }

    /**
     * Generate production-optimized cache
     */
    protected function handleGenerate(): int
    {
        intro(__('ichava/ichava-core::commands.cache.generate.intro'));

        return $this->tryExecute(function () {
            spin(
                callback: fn () => $this->cacheService->generateProductionCache($this->option('path')),
                message: __('ichava/ichava-core::commands.cache.generate.generating'),
            );

            $this->displayCacheStats();

            outro(__('ichava/ichava-core::commands.cache.generate.done'));

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.cache.generate.failed'));
    }

    /**
     * Generate icon manifest for production deployment
     */
    protected function handleManifest(): int
    {
        $path = $this->option('path');

        intro(__('ichava/ichava-core::commands.cache.manifest.intro'));

        // Skip rebuild if a fresh manifest already exists and --force was not passed.
        if (
            ! $this->option('force')
            && $this->cacheService->manifestExists($path)
            && ! $this->cacheService->manifestIsStale($path)
        ) {
            note(__('ichava/ichava-core::commands.cache.manifest.fresh'));

            return self::SUCCESS;
        }

        if ($this->cacheService->manifestExists($path) && ! $this->option('force')) {
            $overwrite = confirm(
                label: __('ichava/ichava-core::commands.cache.manifest.overwrite'),
                default: true,
                yes: __('ichava/ichava-core::commands.cache.manifest.overwrite_yes'),
                no: __('ichava/ichava-core::commands.cache.manifest.overwrite_no'),
                hint: __('ichava/ichava-core::commands.cache.manifest.overwrite_hint'),
            );

            if (! $overwrite) {
                warning(__('ichava/ichava-core::commands.cache.manifest.cancelled'));

                return self::FAILURE;
            }
        }

        return $this->tryExecute(function () use ($path) {
            $result = spin(
                callback: fn () => $this->cacheService->generateManifest($path),
                message: __('ichava/ichava-core::commands.cache.manifest.generating'),
            );

            MetricTable::make()
                ->metrics([
                    __('ichava/ichava-core::commands.cache.metric.packages')    => (int) $result['packages'],
                    __('ichava/ichava-core::commands.cache.metric.total_icons') => (int) $result['total_icons'],
                    __('ichava/ichava-core::commands.cache.metric.file_size')   => FileSize::format((int) $result['file_size']),
                    __('ichava/ichava-core::commands.cache.metric.build_time')  => TimeFormat::fromMillis((float) $result['build_time_ms']),
                ])
                ->render($this->output);

            note(__('ichava/ichava-core::commands.cache.manifest.saved', ['path' => $result['path']]));
            note(__('ichava/ichava-core::commands.cache.manifest.deploy_tip', ['command' => $this->getName()]));

            outro(__('ichava/ichava-core::commands.cache.manifest.done'));

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.cache.manifest.failed'));
    }

    /**
     * Display cache statistics
     */
    protected function handleStats(): int
    {
        intro(__('ichava/ichava-core::commands.cache.stats.intro'));

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
            message: __('ichava/ichava-core::commands.cache.stats.gathering'),
        );

        $yes = __('ichava/ichava-core::commands.common.yes');
        $no = __('ichava/ichava-core::commands.common.no');

        MetricTable::make()
            ->metrics([
                __('ichava/ichava-core::commands.cache.metric.driver')            => (string) $stats['driver'],
                __('ichava/ichava-core::commands.cache.metric.cached_packages')   => (int) ($stats['stats']['packages'] ?? 0),
                __('ichava/ichava-core::commands.cache.metric.cached_categories') => (int) ($stats['stats']['categories'] ?? 0),
                __('ichava/ichava-core::commands.cache.metric.total_keys')        => (int) ($stats['stats']['total_keys'] ?? 0),
                __('ichava/ichava-core::commands.cache.metric.manifest_exists')   => StatusBadge::of((bool) $stats['manifest_exists'])
                    ->label($stats['manifest_exists'] ? $yes : $no)
                    ->render(),
                // A stale manifest is a warning, not a failure: it still serves.
                __('ichava/ichava-core::commands.cache.metric.manifest_stale') => StatusBadge::of($stats['manifest_stale'] ? Status::Warning : Status::Success)
                    ->label($stats['manifest_stale'] ? $yes : $no)
                    ->render(),
            ])
            ->render($this->output);
    }
}
