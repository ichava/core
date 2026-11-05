<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Providers;

use Throwable;
use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;
use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Ichava\Ichava;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\Models\Icon;
use Illuminate\Console\Scheduling\Schedule;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Support\Coerce;
use Simtabi\Laranail\Package\Tools\Package;
use Illuminate\Contracts\Container\Container;
use Simtabi\Laranail\Ichava\Data\BulkOptions;
use Simtabi\Laranail\Ichava\Enums\KindSource;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Simtabi\Laranail\Ichava\Support\AuditLogger;
use Simtabi\Laranail\DbTools\Query\PortableQuery;
use Simtabi\Laranail\Ichava\Commands\InfoCommand;
use Simtabi\Laranail\Ichava\Facades\IchavaFacade;
use Simtabi\Laranail\Ichava\Support\IconRenderer;
use Simtabi\Laranail\Ichava\Support\PathResolver;
use Simtabi\Laranail\Ichava\Commands\CacheCommand;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Auth\HostUser;
use Simtabi\Laranail\Ichava\Support\RuntimeLimits;
use Simtabi\Laranail\Ichava\Support\SecurityNonce;
use Simtabi\Laranail\Ichava\Contracts\PresentsUser;
use Simtabi\Laranail\Ichava\Contracts\IconDriverInterface;
use Simtabi\Laranail\Ichava\Contracts\ResolvesUser;
use Simtabi\Laranail\Ichava\Services\IconsManifest;
use Simtabi\Laranail\Ichava\View\ComponentPrefixes;
use Illuminate\Database\Eloquent\Relations\Relation;
use Simtabi\Laranail\Ichava\Commands\InstallCommand;
use Simtabi\Laranail\Ichava\Commands\DatabaseCommand;
use Simtabi\Laranail\Ichava\Support\Auth\IchavaGates;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;
use Simtabi\Laranail\Ichava\Commands\JobStatusCommand;
use Simtabi\Laranail\Ichava\Contracts\PreferenceStore;
use Simtabi\Laranail\Ichava\Services\IconCacheService;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Contracts\ChecksPackUpdates;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;
use Simtabi\Laranail\Ichava\Services\IconWatcherService;
use Simtabi\Laranail\Ichava\Services\InformationService;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;
use Simtabi\Laranail\Ichava\Contracts\DatabaseOperations;
use Simtabi\Laranail\Ichava\Support\IchavaSessionManager;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Simtabi\Laranail\Ichava\Services\ConfigurationService;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;
use Simtabi\Laranail\Ichava\Support\DeferredIconsRegistry;
use Simtabi\Laranail\Ichava\Commands\WatchIconFilesCommand;
use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;
use Simtabi\Laranail\Ichava\Services\IconPreferenceService;
use Simtabi\Laranail\Ichava\Services\IconSetCatalogService;
use Simtabi\Laranail\Ichava\Support\Auth\GuardUserResolver;
use Simtabi\Laranail\Ichava\Services\CacheOperationsService;
use Simtabi\Laranail\Ichava\Services\IchavaLifecycleManager;
use Simtabi\Laranail\Ichava\Commands\CheckIconUpdatesCommand;
use Simtabi\Laranail\Ichava\Commands\CleanupIchavaLogsCommand;
use Simtabi\Laranail\Ichava\Support\Auth\DefaultUserPresenter;
use Simtabi\Laranail\Ichava\Support\OwnerAwarePreferenceStore;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;
use Simtabi\Laranail\Package\Tools\Support\RuntimeConfigurator;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;

/**
 * IchavaServiceProvider - Core Ecosystem Service Provider
 *
 * Bootstraps the entire Ichava icon ecosystem for a Laravel application.
 * Auto-discovered via composer.json, no manual registration needed.
 * Child icon packages extend Providers\IconPackServiceProvider, not this class.
 *
 * Full list of registered services, commands, Blade components, middleware,
 * and log channels: see README.md § Architecture.
 *
 * @see IconPackServiceProvider
 * @see IchavaFacade
 */
final class IchavaServiceProvider extends PackageServiceProvider
{
    /**
     * The vendor-scoped Blade component prefix: `<x-ichava-core::icon>`.
     * The same value as ComponentPrefixes::CANONICAL, which owns it.
     */
    public const string SCOPED_COMPONENT_PREFIX = ComponentPrefixes::CANONICAL;

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
            ->hasAboutSection('Ichava', fn (): array => $this->aboutEcosystem())
            // Core extends PackageServiceProvider directly, so it does NOT
            // inherit the default-on registration PackServiceProvider gives
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
        // No runtime limits here. A library must not change the host's
        // process-wide memory or time limits, and the config has not merged
        // yet in this phase. Ichava's own seed, watch and cache operations
        // run inside RuntimeLimits::scope(), which restores them after (C-01).

        // Register morph map for polymorphic relationships
        Relation::morphMap([
            Icon::MORPH_ALIAS => Icon::class,
        ]);

        // Dynamically register Horizon supervisor for ichava-icons queue
        $this->registerHorizonSupervisor();

        // Register event service provider for icon cache invalidation
        $this->app->register(EventServiceProvider::class);

        // 1. Base services (no dependencies)
        // Built from `optimization.*`; the level and any per-pass override
        // are part of renderFingerprint(), so changing them re-keys caches.
        $this->app->singleton(SvgProcessingService::class, fn (): SvgProcessingService => SvgProcessingService::fromConfig());

        // 2. Services with minimal dependencies
        $this->app->singleton(IchavaLogger::class);
        $this->app->alias(IchavaLogger::class, 'ichava.logger');

        // Security: request-scoped CSP nonce + central audit pipeline.
        SecurityNonce::bind($this->app);
        $this->app->alias(SecurityNonce::class, 'ichava.security.nonce');

        $this->app->singleton(AuditLogger::class);
        $this->app->alias(AuditLogger::class, 'ichava.security.audit');

        // Preferences are written through a contract so the store can be
        // substituted. Without this the service would depend on a `final`
        // class that decides its own availability, which is why preference
        // behaviour could not be tested at all.
        //
        // The store is owner-aware: a signed-in user's preferences go to an
        // OwnerPreferenceStore when one is bound (ichava/icon-browser binds
        // its owner-keyed table), and guests keep the session store. With no
        // owner store bound every call reaches the session store, as before.
        $this->app->singleton(PreferenceStore::class, fn (Container $app): PreferenceStore => new OwnerAwarePreferenceStore(
            $app->make(IchavaSessionManager::class),
            $app->make(HostUser::class),
            $app,
        ));

        // The host's user. Ichava has no auth of its own: the default resolver
        // reads the guard named by `auth.guard`, and a host rebinds either
        // contract, or calls Ichava::resolveUserUsing(), to change that.
        $this->app->singleton(ResolvesUser::class, GuardUserResolver::class);
        $this->app->singleton(PresentsUser::class, DefaultUserPresenter::class);
        $this->app->singleton(HostUser::class);

        $this->app->singleton(PathResolver::class);
        $this->app->singleton(SvgDriver::class);
        $this->app->bind(IconDriverInterface::class, fn (Container $app): IconDriverInterface => $app->make(SvgDriver::class));

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

        // The two I/O services with a second implementation today (the test
        // doubles), behind contracts. Each resolves the concrete singleton, so
        // a binding of the class (as icon-browser's tests make) still wins (C-06).
        $this->app->bind(ChecksPackUpdates::class, fn (Container $app): ChecksPackUpdates => $app->make(IconPackUpdateChecker::class));
        $this->app->bind(DatabaseOperations::class, fn (Container $app): DatabaseOperations => $app->make(DatabaseOperationsService::class));

        // Manifest service
        $this->app->singleton(IconsManifest::class, function (Container $app) {
            $pathResolver = $app->make(PathResolver::class);
            $manifestPath = $pathResolver->getManifestPathFromConfig();

            return new IconsManifest($app->make(Filesystem::class), $manifestPath);
        });

        // Register Ichava facade backing class
        $this->app->singleton(Ichava::class, function (Container $app) {
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
                $app->make(HostUser::class),
                $app->make(InformationService::class),
            );
        });

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
        // Log channels and the Horizon supervisor read config, so they are
        // registered here, once the package config has merged. Services are
        // only bound (not built) before this point, so nothing has logged yet.
        $this->registerLogChannels();

        // Icon::scopeFuzzySearch() builds on laranail/db-tools' portable LIKE
        // macros. Registered here as well as by db-tools' own provider, because a
        // host that turns off package discovery would otherwise lose search with a
        // BadMethodCallException. register() is idempotent.
        PortableQuery::register();

        // No config re-merge here any more. This used to re-merge a nested config block back
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
     * 2. IconRegistry::fromDirectory() , register bundled test-icons set (core test fixtures)
     * 3. registerCustomIconSets()      , user-defined sets from ichava.ichava-core.custom-icons.sets
     * 4. registerCoreIconComponent()   , <x-ichava-core::icon>, and the configured short <x-ichava::icon>
     * 5. registerFileWatcher()         , schedule ichava:watch if database.auto_sync enabled
     * 6. scheduleLogCleanup()          , schedule ichava:cleanup-logs if logging.auto_cleanup enabled
     */
    public function bootingPackage(): void
    {
        // Register core's bundled icon set (test fixtures shipped for the
        // API/test suite; multi-set structure: svg/{set-name}/config.json +
        // files/{set-name}/*.svg). The browser package's `ui-icons` set is
        // registered by IconBrowserServiceProvider when installed.
        //
        // `test.enabled` turns it off and `test.path` points it elsewhere.
        // With it off, `default_set` must name a real pack, or every
        // unqualified icon fails to resolve (the shipped default is `test`).
        $this->registerTestIconSet();

        // Register custom icon sets from config
        $this->registerCustomIconSets();
        $this->registerCoreIconComponent();

        // Register file watcher scheduler (if enabled)
        $this->registerFileWatcher();

        // Schedule automatic log cleanup
        $this->scheduleLogCleanup();

        // The morph map is flat: a provider registered after core can map
        // Icon's alias to its own model, and stored termable rows would then
        // load the wrong class. Checked once every provider has registered.
        $this->app->booted(function (): void {
            $this->assertIconMorphAlias();
        });

        // Default-deny Gates, defined after every provider has booted so a
        // host's own definitions are seen first and never overwritten.
        $this->app->booted(function (): void {
            if ($this->app->bound(GateContract::class)) {
                IchavaGates::define($this->app->make(GateContract::class));
            }
        });
    }

    /**
     * Fail at boot when the live morph map resolves Icon's alias to another class.
     *
     * @throws IchavaException
     */
    protected function assertIconMorphAlias(): void
    {
        $model = Relation::getMorphedModel(Icon::MORPH_ALIAS);

        if ($model !== Icon::class) {
            throw IchavaException::invalidConfiguration(sprintf(
                "the morph alias '%s' resolves to %s, not %s. Another provider has claimed it; map that model under its own alias.",
                Icon::MORPH_ALIAS,
                $model ?? 'nothing',
                Icon::class,
            ));
        }
    }

    /**
     * Register core's bundled test set, unless `test.enabled` is false;
     * `test.path` points it at another directory.
     */
    protected function registerTestIconSet(): void
    {
        if (! config('ichava.ichava-core.test.enabled', true)) {
            return;
        }

        $testPath = config('ichava.ichava-core.test.path');

        // Through the bulk path, so registerMany() runs on every boot: the
        // bundled set sits in the multi-set layout (svg/{set}/config.json).
        // failFast keeps a broken test set loud, as fromDirectory() was.
        $sets = is_string($testPath) && $testPath !== ''
            ? [PackBuilder::fromDirectory($testPath)]
            : PackBuilder::discover($this->package->basePath('resources/assets/svg'), only: ['test-icons']);

        $this->app->make(IconRegistry::class)->registerMany(
            array_map(static fn (PackBuilder $set): PackBuilder => $set->providedBy(self::class), $sets),
            new BulkOptions(name: 'ichava/core', failFast: true),
        );
    }

    /**
     * What `php artisan about` reports for the ecosystem as a whole.
     *
     * Deliberately operational rather than descriptive: name, description,
     * licence and homepage are `composer.json`'s job, and HasAbout says so.
     * What a manifest cannot answer is what is actually installed and booted
     * in this application right now.
     *
     * Resolved lazily -- `about` runs long after boot, so the registry is
     * populated and this costs nothing on a normal request.
     *
     * @return array<string, string>
     */
    protected function aboutEcosystem(): array
    {
        $packages = $this->app->make(IconRegistry::class)->all();

        return [
            'Packages' => (string) count($packages),
            'Icons'    => (string) array_sum(array_map(
                static fn (array $meta): int => Coerce::int($meta['total'] ?? 0),
                $packages,
            )),
            'Cache driver'  => Coerce::string(config('cache.default')),
            'Cache version' => Coerce::string(config('ichava.ichava-core.cache.version', '1')),
            'Queue'         => Coerce::string(config('ichava.ichava-core.database.queue', 'ichava-icons')),
            ...$this->aboutKinds(),
        ];
    }

    /**
     * The kind facts for `about`: seeded icons per kind, the browser's
     * default kind, which resolution row decided the seeded kinds, and how
     * many host overrides are configured.
     *
     * @return array<string, string>
     */
    protected function aboutKinds(): array
    {
        $overrides = config('ichava.ichava-core.kinds.overrides', []);
        $facts = [
            'Icons by kind'  => __('ichava/ichava-core::info.about.database_disabled'),
            'Default kind'   => IconKind::default()->value,
            'Kind sources'   => __('ichava/ichava-core::info.about.database_disabled'),
            'Kind overrides' => (string) (is_array($overrides) ? count($overrides) : 0),
        ];

        if (! config('ichava.ichava-core.database.enabled', true)) {
            return array_map(Coerce::string(...), $facts);
        }

        try {
            $byKind = DB::table(Icon::TABLE)->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind')->all();
            $bySource = DB::table(Icon::TABLE)->selectRaw('kind_source, COUNT(*) as n')->groupBy('kind_source')->pluck('n', 'kind_source')->all();
        } catch (Throwable) {
            return array_map(Coerce::string(...), $facts);
        }

        $facts['Icons by kind'] = implode(' · ', array_map(
            static fn (IconKind $kind): string => $kind->value . ' ' . number_format(Coerce::int($byKind[$kind->value] ?? 0)),
            IconKind::cases(),
        ));

        $sources = [];
        foreach (KindSource::cases() as $source) {
            $count = Coerce::int($bySource[$source->value] ?? 0);

            if ($count > 0) {
                $sources[] = $source->value . ' ' . number_format($count);
            }
        }

        $facts['Kind sources'] = $sources === [] ? __('ichava/ichava-core::info.about.none') : implode(' · ', $sources);

        return array_map(Coerce::string(...), $facts);
    }

    /**
     * Register Ichava's log channels from `ichava.ichava-core.logging.channels`.
     *
     * Each channel is copied into `logging.channels.<name>` verbatim, so a host
     * changes a driver, path, level, retention or permission by publishing the
     * config. This used to call env() here, which returns null under
     * `config:cache` and silently dropped every host override in production.
     */
    protected function registerLogChannels(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        $channels = $config->get('ichava.ichava-core.logging.channels', []);

        foreach (is_array($channels) ? $channels : [] as $name => $channel) {
            if (is_string($name) && is_array($channel)) {
                $config->set("logging.channels.{$name}", $channel);
            }
        }
    }

    /**
     * Schedule the ichava:cleanup-logs command to run daily.
     *
     * Only schedules if ichava.ichava-core.logging.auto_cleanup is true (default).
     * Run time is controlled by ichava.ichava-core.logging.cleanup_time (default '03:00').
     * Runs with withoutOverlapping() to prevent concurrent cleanup jobs.
     * Logs success/failure via IchavaLogger on the 'ichava' channel.
     */
    protected function scheduleLogCleanup(): void
    {
        if (! config('ichava.ichava-core.logging.auto_cleanup', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $cleanupTime = Coerce::string(config('ichava.ichava-core.logging.cleanup_time', '03:00'));

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
     * Only schedules if ichava.ichava-core.database.auto_sync is true (default).
     * The command monitors SVG file changes and syncs them to the database.
     * Runs in the background with withoutOverlapping() using the configured
     * sync interval (ichava.ichava-core.database.sync_interval, default 60 seconds).
     */
    protected function registerFileWatcher(): void
    {
        if (! config('ichava.ichava-core.database.auto_sync', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $interval = Coerce::int(config('ichava.ichava-core.database.sync_interval', 60));

            $schedule->command('ichava::ichava-core.watch')
                ->everyMinute()
                ->when(fn () => config('ichava.ichava-core.database.auto_sync', true))
                ->runInBackground()
                ->withoutOverlapping($interval);
        });
    }

    /**
     * Register core's Blade tags: `<x-ichava-core::icon>` always, and the short
     * `<x-ichava::icon>` while `components.short_prefix` names one, each with
     * the component namespace over the same classes. `components.enabled`
     * false registers neither. ComponentPrefixes validates the short prefix
     * against Blade's tag parser and throws at boot on one it cannot parse.
     *
     * Browser-only demo components (`<x-ichava::ichava-test-icons>`,
     * `<x-ichava::ichava-ui-icons>`) are registered by
     * `IconBrowserServiceProvider` in the `ichava/icon-browser` package.
     */
    protected function registerCoreIconComponent(): void
    {
        $this->app->make(ComponentPrefixes::class)->register();
    }

    /**
     * Register user-defined icon sets from the ichava.ichava-core.custom-icons.sets config.
     *
     * Each entry is a path, or an array of options: `path`, `enabled`,
     * `package`, `bulk`/`only`, and the PackBuilder keys (`prefix`,
     * `default_class`, `blade_component`, ...); IconRegistry::registerFromConfig()
     * documents them. Registration is deferred to app()->booted() so every
     * provider has registered first. Empty or missing config no-ops.
     */
    protected function registerCustomIconSets(): void
    {
        $customIconsConfig = config('ichava.ichava-core.custom-icons.sets', []);

        if (! is_array($customIconsConfig) || blank($customIconsConfig)) {
            return;
        }

        // Each entry is a path string or an array carrying a 'path' key, as
        // registerFromConfig() reads it; anything else is not a set.
        $sets = [];
        foreach ($customIconsConfig as $key => $set) {
            if (is_string($set)) {
                $sets[$key] = $set;
            } elseif (is_array($set)) {
                $sets[$key] = array_filter($set, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }
        $customIconsConfig = $sets;

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

        $environment = $this->app->environment();

        // Read once config has loaded. The supervisor comes from
        // `queue.horizon.supervisor` and the queue from `queue.name`, so
        // `config:cache` keeps every host override (env() here did not).
        $this->app->booted(function () use ($environment) {
            if (! config('ichava.ichava-core.queue.horizon.auto_register', true)) {
                return;
            }

            $supervisor = $this->horizonSupervisorConfig();

            config(['horizon.defaults.supervisor-ichava' => $supervisor]);
            config(["horizon.environments.{$environment}.supervisor-ichava" => [
                'maxProcesses' => Coerce::int($supervisor['maxProcesses'] ?? 3),
            ]]);
        });
    }

    /**
     * The Horizon supervisor Ichava registers: `queue.horizon.supervisor`,
     * listening on `queue.name`.
     *
     * @return array<array-key, mixed>
     */
    protected function horizonSupervisorConfig(): array
    {
        $supervisor = config('ichava.ichava-core.queue.horizon.supervisor', []);
        $supervisor = is_array($supervisor) ? $supervisor : [];
        $supervisor['queue'] = [Coerce::string(config('ichava.ichava-core.queue.name', 'ichava-icons'))];

        return $supervisor;
    }

    /**
     * The PHP runtime settings Ichava's heavy operations run under.
     *
     * Ichava processes large icon sets (100k+ SVGs), which needs a higher
     * memory limit, a longer execution time and Telescope paused. This builds
     * that configurator from the merged config and applies nothing: the
     * operations call RuntimeLimits::scope(), so the host keeps its limits
     * everywhere else.
     */
    protected function configureRuntimeSettings(): RuntimeConfigurator
    {
        return $this->app->make(RuntimeLimits::class)->configurator();
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
        $argv = is_array($argv) ? array_map(Coerce::string(...), $argv) : [];
        $isQueueCommand = collect($argv)->contains(fn (string $arg) => Str::contains($arg, 'queue:'));
        $isIchavaQueue = collect($argv)->contains(fn (string $arg) => Str::contains($arg, 'ichava'));

        return $isQueueCommand && $isIchavaQueue;
    }
}
