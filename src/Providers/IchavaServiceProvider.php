<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Providers;

use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;
use Simtabi\Laranail\Ichava\Ichava;
use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\Models\Icon;
use Illuminate\Console\Scheduling\Schedule;
use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Simtabi\Laranail\Ichava\Support\AuditLogger;
use Simtabi\Laranail\Ichava\Commands\InfoCommand;
use Simtabi\Laranail\Ichava\Facades\IchavaFacade;
use Simtabi\Laranail\Ichava\Support\IconRenderer;
use Simtabi\Laranail\Ichava\Support\PathResolver;
use Simtabi\Laranail\Ichava\Commands\CacheCommand;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\SecurityNonce;
use Simtabi\Laranail\Ichava\Services\IconsManifest;
use Illuminate\Database\Eloquent\Relations\Relation;
use Simtabi\Laranail\Ichava\Commands\InstallCommand;
use Simtabi\Laranail\Ichava\Support\ServiceProvider;
use Simtabi\Laranail\Ichava\Commands\DatabaseCommand;
use Simtabi\Laranail\Ichava\Commands\JobStatusCommand;
use Simtabi\Laranail\Ichava\Services\IconCacheService;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;
use Simtabi\Laranail\Ichava\Services\IconWatcherService;
use Simtabi\Laranail\Ichava\Services\InformationService;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;
use Simtabi\Laranail\Ichava\Services\ConfigurationService;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;
use Simtabi\Laranail\Ichava\Support\DeferredIconsRegistry;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;
use Simtabi\Laranail\Ichava\Commands\WatchIconFilesCommand;
use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;
use Simtabi\Laranail\Ichava\Services\IconPreferenceService;
use Simtabi\Laranail\Ichava\Services\IconSetCatalogService;
use Simtabi\Laranail\Ichava\Services\CacheOperationsService;
use Simtabi\Laranail\Ichava\Services\IchavaLifecycleManager;
use Simtabi\Laranail\Ichava\Commands\CheckIconUpdatesCommand;
use Simtabi\Laranail\Ichava\Commands\CleanupIchavaLogsCommand;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;
use Simtabi\Laranail\Package\Tools\Support\RuntimeConfigurator;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;

/**
 * IchavaServiceProvider - Core Ecosystem Service Provider
 *
 * Bootstraps the entire Ichava icon ecosystem for a Laravel application.
 * Auto-discovered via composer.json, no manual registration needed.
 * Child icon packages must extend Support\ServiceProvider, not this class.
 *
 * Full list of registered services, commands, Blade components, middleware,
 * and log channels: see README.md § Architecture.
 *
 * @see ServiceProvider
 * @see IchavaFacade
 */
class IchavaServiceProvider extends PackageServiceProvider
{
    /**
     * Declare the Ichava core package metadata, assets, and commands.
     * Called before any bindings are registered.
     */
    public function configurePackage(Package $packager): void
    {
        $packager
            ->setPathFrom(source: $this, levelsUp: 2)
            ->setName('ichava/core', fn (string $package): string => 'ichava-core')
            ->hasConfigFile('ichava-core')
            // Core extends PackageServiceProvider directly, so it does NOT
            // inherit the default-on registration Support\ServiceProvider gives
            // the packs -- it has to ask. That asymmetry has already cost one
            // defect class here; anything added to the packs' default needs a
            // deliberate counterpart on this line.
            ->hasTranslations()
            ->discoversMigrations()
            ->runsMigrations()
            ->hasBladeDirectives([
                'ichava_defs'      => fn () => "<?php echo app('" . DeferredIconsRegistry::class . "')->renderDefinitions(); ?>",
                'ichava_csp_nonce' => fn () => "<?php echo app('" . SecurityNonce::class . "')->attribute(); ?>",
            ])
            ->hasCommands([
                // Unified commands (consolidated from multiple separate commands)
                CacheCommand::class,     // cache, manifest
                DatabaseCommand::class,  // seed, migrate, unseed
                InfoCommand::class,      // info, status
                InstallCommand::class,   // install icon sets

                // Specialized commands (kept separate by design)
                JobStatusCommand::class,
                WatchIconFilesCommand::class,
                CleanupIchavaLogsCommand::class,
                CheckIconUpdatesCommand::class,
            ]);
    }

    /**
     * Register core Ichava services in dependency order. Boot phase performs
     * route/view/middleware/Blade-directive registration. See README §
     * "Architecture" for the full bootstrap sequence and binding table.
     */
    public function registeringPackage(): void
    {
        // Configure runtime settings for heavy operations (seeding, processing)
        $this->configureRuntimeSettings();

        // Register Ichava log channels FIRST - before any services that might log
        $this->registerLogChannels();

        // Register morph map for polymorphic relationships
        Relation::morphMap([
            'icon' => Icon::class,
        ]);

        // Dynamically register Horizon supervisor for ichava-icons queue
        $this->registerHorizonSupervisor();

        // Register event service provider for icon cache invalidation
        $this->app->register(EventServiceProvider::class);

        // 1. Base services (no dependencies)
        $this->app->singleton(SvgProcessingService::class);

        // 2. Services with minimal dependencies
        $this->app->singleton(IchavaLogger::class);
        $this->app->alias(IchavaLogger::class, 'ichava.logger');

        // Security: request-scoped CSP nonce + central audit pipeline.
        SecurityNonce::bind($this->app);
        $this->app->alias(SecurityNonce::class, 'ichava.security.nonce');

        $this->app->singleton(AuditLogger::class);
        $this->app->alias(AuditLogger::class, 'ichava.security.audit');

        $this->app->singleton(PathResolver::class);
        $this->app->singleton(SvgDriver::class);

        // 3. Cache service (depends on IchavaLogger only)
        $this->app->singleton(IconCacheService::class);
        $this->app->alias(IconCacheService::class, 'ichava.cache');

        // 4. IconRegistry (no ConfigurationService dependency anymore!)
        $this->app->singleton(IconRegistry::class);

        // 5. IconWatcherService (depends on IconRegistry)
        $this->app->singleton(IconWatcherService::class);

        // 6. Other services
        $this->app->singleton(IconDiscoveryService::class);
        $this->app->singleton(IconPreferenceService::class);
        $this->app->singleton(IchavaLifecycleManager::class);
        $this->app->singleton(DeferredIconsRegistry::class);

        // 7. ConfigurationService (now just a helper - no one depends on it!)
        $this->app->singleton(ConfigurationService::class);

        // 8. Operations Services (extracted from base commands)
        $this->app->singleton(DatabaseOperationsService::class);
        $this->app->singleton(CacheOperationsService::class);
        $this->app->singleton(InformationService::class);
        $this->app->singleton(IconSetCatalogService::class);
        $this->app->singleton(IchavaSeeder::class);
        $this->app->singleton(IconPackUpdateChecker::class);

        // Manifest service
        $this->app->singleton(IconsManifest::class, function ($app) {
            $pathResolver = $app->make(PathResolver::class);
            $manifestPath = $pathResolver->getManifestPathFromConfig();

            return new IconsManifest($app['files'], $manifestPath);
        });

        // Register Ichava facade backing class
        $this->app->singleton(Ichava::class, function ($app) {
            return new Ichava(
                $app->make(IconRegistry::class),
                $app->make(IconBrowserService::class),
                $app->make(IconCacheService::class),
                $app->make(IconPreferenceService::class),
                $app->make(IconDiscoveryService::class),
                $app->make(IconsManifest::class),
                $app->make(IchavaLogger::class),
                $app->make(DeferredIconsRegistry::class),
                $app->make(IconRenderer::class),
            );
        });

        // Alias for backward compatibility and easy access
        $this->app->alias(Ichava::class, 'ichava');

        // Stateful services (fresh instances)
        $this->app->bind(IconRenderer::class);
    }

    /**
     * Called after package configs are registered.
     *
     * This is the right place for config aliasing since registerPackageConfigs()
     * has already merged configs into Laravel's config repository.
     */
    public function packageRegistered(): void
    {
        // Intentionally empty. This used to re-merge a nested config block back
        // onto the flat 'ichava' key. The nesting came from the config file
        // being named 'ichava' while the package short name is 'core', so
        // package-tools appended the filename to the namespace. The short name
        // is now remapped to 'ichava-core' via the setName() transformer and the
        // file is 'config/ichava-core.php', which makes the key exactly
        // 'ichava.ichava-core', and the workaround is unnecessary. It never
        // worked anyway: it looked for 'ichava.ichava' when the real key was
        // 'ichava.core.ichava'.
    }

    /**
     * Boot the Ichava ecosystem once all providers are registered.
     *
     * Execution sequence:
     * 1. Blade::componentNamespace()    , 'ichava' component namespace (the entire HTTP layer, routes, middleware, controllers, layouts, lives in IchavaBrowserServiceProvider in ichava/browser)
     * 2. IconRegistry::fromDirectory() , register bundled test-icons set (core test fixtures)
     * 3. registerCustomIconSets()      , user-defined sets from ichava.custom-icons.sets
     * 4. registerCoreIconComponent()   , register the generic <x-ichava::icon> Blade component
     * 5. registerFileWatcher()         , schedule ichava:watch if database.auto_sync enabled
     * 6. scheduleLogCleanup()          , schedule ichava:cleanup-logs if logging.auto_cleanup enabled
     */
    public function bootingPackage(): void
    {
        // Register class-based components under the 'ichava' namespace so
        // packages registering their own short-name components (e.g. browser's
        // <x-ichava::ichava-test-icons>) can do so via Blade::component(...).
        // The HTTP layer (routes, middleware, controllers, requests, resources)
        // is owned entirely by IchavaBrowserServiceProvider in ichava/browser ,
        // core ships zero HTTP surface.
        // DEFERRED, not overlooked: `ichava` is a bare generic slug in a flat
        // map, the same class of claim `ichava/browser` corrected for view
        // hints. It is deliberate here -- packs register their own short-name
        // components under one shared ecosystem prefix -- but the standard's
        // reasoning about silent replacement applies to it unchanged.
        //
        // Left in place because `<x-ichava::icon>` is the ecosystem's
        // documented public API. Renaming it to `ichava-core::` is a breaking
        // change for every consumer and belongs in its own release with a
        // migration note, not folded into unrelated work. Revisit at 1.0.
        //
        // 112 lines across the eight package repos, measured 2026-09-21:
        //
        //   for p in core browser icon-sets-*; do
        //     git -C "$p" grep -I -c -F 'x-ichava::' origin/main \
        //       -- 'src/*' 'resources/*' 'docs/*'
        //   done | awk -F: '{s+=$NF} END{print s}'
        //
        // Do not cite that number without re-running the command. An earlier
        // revision of this comment read "~85 ... plus 24 in
        // `ichava/documentation`", which was true when measured and was made
        // wrong by the docs decentralisation: those pages moved into the
        // packages, so the per-package count rose and `ichava/documentation`
        // now holds **zero** -- it would send a reader to the one place with
        // nothing left to find.
        //
        // Three flat maps carry the bare `ichava` key, not one, so Decision B
        // is wider than this call site:
        //
        //   1. this class-component namespace;
        //   2. the class-component aliases -- core's `ichava::icon` in
        //      registerCoreIconComponent() below, plus five in browser;
        //   3. browser's `Blade::anonymousComponentPath(..., 'ichava')`, which
        //      Laravel stores as addNamespace(hash('xxh128', $prefix), $path)
        //      -- so the slug still determines a key in the *view-hint* map
        //      that ichava/browser#33 otherwise cleared of it.
        //
        // The fourth, the view hints themselves, is the one already corrected.
        Blade::componentNamespace('Simtabi\\Laranail\\Ichava\\View\\Components', 'ichava');

        // Register core's bundled icon set (test fixtures shipped for the
        // API/test suite; multi-set structure: svg/{set-name}/config.json +
        // files/{set-name}/*.svg). The browser package's `ui-icons` set is
        // registered by IchavaBrowserServiceProvider when installed.
        $registry = $this->app->make(IconRegistry::class);
        $registry->fromDirectory(
            $this->package->basePath('resources/assets/svg/test-icons'),
            self::class,
        );

        // Register custom icon sets from config
        $this->registerCustomIconSets();
        $this->registerCoreIconComponent();

        // Register file watcher scheduler (if enabled)
        $this->registerFileWatcher();

        // Schedule automatic log cleanup
        $this->scheduleLogCleanup();
    }

    /**
     * Register Ichava log channels dynamically
     *
     * Creates dedicated daily log files prefixed with 'ichava':
     * - ichava-2025-12-06.log (general)
     * - ichava-icons-2025-12-06.log (icon seeding)
     * - ichava-queue-2025-12-06.log (queue jobs)
     */
    protected function registerLogChannels(): void
    {
        $retentionDays = (int) env('ICHAVA_LOG_RETENTION_DAYS', 7);

        // General ichava logs (info level to capture registration, warnings, errors)
        $this->app['config']->set('logging.channels.ichava', [
            'driver'     => 'daily',
            'path'       => storage_path('logs/ichava.log'),
            'level'      => env('ICHAVA_LOG_LEVEL', 'info'),
            'days'       => $retentionDays,
            'permission' => 0644,
        ]);

        // Icon seeding logs
        $this->app['config']->set('logging.channels.ichava-icons', [
            'driver'     => 'daily',
            'path'       => storage_path('logs/ichava-icons.log'),
            'level'      => env('ICHAVA_SEEDING_LOG_LEVEL', 'info'),
            'days'       => $retentionDays,
            'permission' => 0644,
        ]);

        // Queue job logs
        $this->app['config']->set('logging.channels.ichava-queue', [
            'driver'     => 'daily',
            'path'       => storage_path('logs/ichava-queue.log'),
            'level'      => env('ICHAVA_QUEUE_LOG_LEVEL', 'info'),
            'days'       => $retentionDays,
            'permission' => 0644,
        ]);

        // Security audit logs (separate channel so SIEM forwarders can scope
        // their tail without seeing operational noise from ichava.log).
        $this->app['config']->set('logging.channels.ichava-audit', [
            'driver'     => 'daily',
            'path'       => storage_path('logs/ichava-audit.log'),
            'level'      => env('ICHAVA_AUDIT_LOG_LEVEL', 'info'),
            'days'       => (int) env('ICHAVA_AUDIT_LOG_RETENTION_DAYS', 90),
            'permission' => 0640,
        ]);
    }

    /**
     * Schedule the ichava:cleanup-logs command to run daily.
     *
     * Only schedules if ichava.logging.auto_cleanup is true (default).
     * Run time is controlled by ichava.logging.cleanup_time (default '03:00').
     * Runs with withoutOverlapping() to prevent concurrent cleanup jobs.
     * Logs success/failure via IchavaLogger on the 'ichava' channel.
     */
    protected function scheduleLogCleanup(): void
    {
        if (! config('ichava.ichava-core.logging.auto_cleanup', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function ($schedule) {
            $cleanupTime = config('ichava.ichava-core.logging.cleanup_time', '03:00');

            $schedule->command('ichava::ichava-core.cleanup-logs')
                ->daily()
                ->at($cleanupTime)
                ->name('ichava-log-cleanup')
                ->withoutOverlapping()
                ->onSuccess(function () {
                    $this->app->make(IchavaLogger::class)->info('Ichava log cleanup completed successfully');
                })
                ->onFailure(function () {
                    $this->app->make(IchavaLogger::class)->error('Ichava log cleanup failed');
                });
        });
    }

    /**
     * Schedule the ichava:watch command to run every minute.
     *
     * Only schedules if ichava.database.auto_sync is true (default).
     * The command monitors SVG file changes and syncs them to the database.
     * Runs in the background with withoutOverlapping() using the configured
     * sync interval (ichava.database.sync_interval, default 60 seconds).
     */
    protected function registerFileWatcher(): void
    {
        if (! config('ichava.ichava-core.database.auto_sync', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function ($schedule) {
            $interval = config('ichava.ichava-core.database.sync_interval', 60);

            $schedule->command('ichava::ichava-core.watch')
                ->everyMinute()
                ->when(fn () => config('ichava.ichava-core.database.auto_sync', true))
                ->runInBackground()
                ->withoutOverlapping($interval);
        });
    }

    /**
     * Register core's generic developer-facing Blade icon component.
     *
     * - `<x-ichava::icon name="vendor/package::path" />`  → IconComponent
     *
     * Browser-only demo components (`<x-ichava::ichava-test-icons>`,
     * `<x-ichava::ichava-ui-icons>`) are registered by
     * `IchavaBrowserServiceProvider` in the `ichava/browser` package.
     */
    protected function registerCoreIconComponent(): void
    {
        // `ichava::` here is the deferred bare slug -- see the DEFERRED note in
        // bootingPackage(). This alias is the one the deferral is actually
        // about, so do not "tidy" it in isolation.
        Blade::component('ichava::icon', IconComponent::class);
    }

    /**
     * Register user-defined icon sets from the ichava.custom-icons.sets config.
     *
     * Each entry in the array may be a plain path string or an associative array
     * with a 'path' key. Registration is deferred to app()->booted() to ensure
     * all service providers have finished registering before icons are loaded.
     * Empty or missing config silently no-ops.
     */
    protected function registerCustomIconSets(): void
    {
        $customIconsConfig = config('ichava.ichava-core.custom-icons.sets', []);

        if (blank($customIconsConfig)) {
            return;
        }

        $this->app->booted(function () use ($customIconsConfig) {
            $manager = $this->app->make(IconRegistry::class);
            $manager->registerFromConfig($customIconsConfig);
        });
    }

    /**
     * Dynamically register Horizon supervisor for Ichava queue
     *
     * This method checks if Laravel Horizon is installed and, if so,
     * dynamically adds a supervisor for the ichava-icons queue.
     * This keeps Ichava self-contained and portable.
     */
    protected function registerHorizonSupervisor(): void
    {
        // Skip if Horizon is not installed
        if (! class_exists(Horizon::class)) {
            return;
        }

        // Get the current environment
        $environment = $this->app->environment();

        // Build supervisor configuration with sensible defaults
        // These can be overridden via environment variables
        $supervisorConfig = [
            'connection'          => env('ICHAVA_HORIZON_CONNECTION', 'redis'),
            'queue'               => [env('ICHAVA_QUEUE_NAME', 'ichava-icons')],
            'balance'             => env('ICHAVA_HORIZON_BALANCE', 'simple'),
            'autoScalingStrategy' => 'time',
            'maxProcesses'        => (int) env('ICHAVA_HORIZON_MAX_PROCESSES', 3),
            'maxTime'             => 0,
            'maxJobs'             => 0,
            'memory'              => (int) env('ICHAVA_HORIZON_MEMORY', 256),
            'tries'               => (int) env('ICHAVA_QUEUE_RETRIES', 3),
            'timeout'             => (int) env('ICHAVA_QUEUE_TIMEOUT', 600),
            'nice'                => 0,
        ];

        // Merge into Horizon's defaults and environment config
        $this->app->booted(function () use ($supervisorConfig, $environment) {
            // Skip if auto-registration is disabled (check after config is loaded)
            if (! config('ichava.ichava-core.queue.horizon.auto_register', true)) {
                return;
            }

            // Add to defaults
            config(['horizon.defaults.supervisor-ichava' => $supervisorConfig]);

            // Add to current environment with appropriate maxProcesses
            $envMaxProcesses = $environment === 'production' ? 5 : 3;
            config(["horizon.environments.{$environment}.supervisor-ichava" => [
                'maxProcesses' => (int) env('ICHAVA_HORIZON_MAX_PROCESSES', $envMaxProcesses),
            ]]);
        });
    }

    /**
     * Configure PHP runtime settings for heavy Ichava operations.
     *
     * Ichava processes large icon sets (100k+ SVGs) which requires:
     * - Higher memory limits for bulk operations
     * - Extended execution time for seeding/processing
     * - Disabled Telescope during queue jobs to prevent memory leaks
     *
     * Settings are configurable via ichava.php config file.
     */
    protected function configureRuntimeSettings(): void
    {
        // Get configured values with sensible defaults
        $memoryLimit = config('ichava.ichava-core.runtime.memory_limit', '1G');
        $maxExecutionTime = (int) config('ichava.ichava-core.runtime.max_execution_time', 0);
        $disableTelescopeInQueue = config('ichava.ichava-core.runtime.disable_telescope_in_queue', true);

        // Build runtime configurator with settings from config
        $configurator = RuntimeConfigurator::make()
            ->memory($memoryLimit)
            ->timeout($maxExecutionTime);

        // Disable Telescope during queue processing to prevent memory exhaustion
        if ($disableTelescopeInQueue && $this->isIchavaQueueCommand()) {
            $configurator->disableTelescope();
        }

        // Apply all settings
        $configurator->apply();
    }

    /**
     * Check if we're running an Ichava queue command.
     */
    protected function isIchavaQueueCommand(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];
        $isQueueCommand = collect($argv)->contains(fn ($arg) => Str::contains($arg, 'queue:'));
        $isIchavaQueue = collect($argv)->contains(fn ($arg) => Str::contains($arg, 'ichava'));

        return $isQueueCommand && $isIchavaQueue;
    }
}
