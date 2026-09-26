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
use function Laravel\Prompts\warning;

use Simtabi\Laranail\Ichava\Support\CommandName;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Console\Tools\Widgets\MetricTable;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;
use Simtabi\Laranail\Ichava\Support\Seeder\IconTermsSeeder;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;

/**
 * Unified Icon Database Command
 *
 * Single command for all database operations: migrate, seed, unseed, truncate, stats.
 *
 * @example
 * php artisan ichava::ichava-core.database seed              # Seed all icons and terms
 * php artisan ichava::ichava-core.database seed --sync       # Seed synchronously (no queue)
 * php artisan ichava::ichava-core.database seed --package=X  # Seed specific package
 * php artisan ichava::ichava-core.database seed:icons        # Seed icons only
 * php artisan ichava::ichava-core.database seed:terms        # Seed terms only
 * php artisan ichava::ichava-core.database migrate           # Run Ichava migrations
 * php artisan ichava::ichava-core.database migrate --fresh   # Drop and re-run Ichava tables
 * php artisan ichava::ichava-core.database unseed            # Remove all Ichava data
 * php artisan ichava::ichava-core.database unseed --package=X # Remove specific package data
 * php artisan ichava::ichava-core.database refresh           # Truncate + seed
 * php artisan ichava::ichava-core.database truncate          # Truncate tables
 * php artisan ichava::ichava-core.database stats             # Show statistics
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
final class DatabaseCommand extends BaseCommand
{
    protected $signature = 'ichava::ichava-core.database
                            {action? : Action: seed, seed:icons, seed:terms, migrate, unseed, refresh, truncate, stats}
                            {--package= : Specific package to target}
                            {--fresh : Drop tables before migrate, or truncate before seed}
                            {--sync : Force synchronous seeding (no queue)}
                            {--force : Force operation without confirmation}
                            {--update : Force update all entries even if unchanged}';

    protected $description = 'Manage Ichava icon database (migrate, seed, unseed, stats)';

    protected array $validActions = ['seed', 'seed:icons', 'seed:terms', 'migrate', 'unseed', 'refresh', 'truncate', 'stats'];

    public function __construct(
        protected DatabaseOperationsService $databaseService,
        protected IchavaLogger $logger,
        protected IchavaSeeder $ichavaSeeder,
        protected IconTermsSeeder $termSeeder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = $this->argument('action');

        // If no action provided, prompt user to select
        if (empty($action)) {
            $action = select(
                label: __('ichava/ichava-core::commands.database.select'),
                options: [
                    'seed'       => __('ichava/ichava-core::commands.database.options.seed'),
                    'seed:icons' => __('ichava/ichava-core::commands.database.options.seed_icons'),
                    'seed:terms' => __('ichava/ichava-core::commands.database.options.seed_terms'),
                    'migrate'    => __('ichava/ichava-core::commands.database.options.migrate'),
                    'unseed'     => __('ichava/ichava-core::commands.database.options.unseed'),
                    'refresh'    => __('ichava/ichava-core::commands.database.options.refresh'),
                    'truncate'   => __('ichava/ichava-core::commands.database.options.truncate'),
                    'stats'      => __('ichava/ichava-core::commands.database.options.stats'),
                ],
                default: 'stats',
                hint: __('ichava/ichava-core::commands.database.select_hint'),
            );
        }

        // Migrate action doesn't require tables to exist
        if ($action === 'migrate') {
            return $this->handleMigrate();
        }

        // All other actions require tables
        if (! $this->ensureIchavaTablesExist()) {
            return self::FAILURE;
        }

        return match ($action) {
            'seed'       => $this->handleSeed(),
            'seed:icons' => $this->handleSeedIcons(),
            'seed:terms' => $this->handleSeedTerms(),
            'unseed'     => $this->handleUnseed(),
            'refresh'    => $this->handleRefresh(),
            'truncate'   => $this->handleTruncate(),
            'stats'      => $this->handleStats(),
            default      => $this->handleInvalidAction($action, $this->validActions),
        };
    }

    /**
     * Handle migrate action
     */
    protected function handleMigrate(): int
    {
        $fresh = $this->option('fresh');

        if ($fresh) {
            if (! $this->confirmDestructive(
                __('ichava/ichava-core::commands.database.migrate.fresh_confirm'),
                __('ichava/ichava-core::commands.database.migrate.fresh_hint'),
            )) {
                return $this->cancelled(__('ichava/ichava-core::commands.common.cancelled'));
            }

            intro(__('ichava/ichava-core::commands.database.migrate.fresh_intro'));

            return $this->tryExecute(function () {
                $result = spin(
                    callback: fn () => $this->databaseService->freshMigration(),
                    message: __('ichava/ichava-core::commands.database.migrate.fresh_running'),
                );

                if (! empty($result['dropped_tables'])) {
                    table(
                        headers: [__('ichava/ichava-core::commands.database.migrate.dropped_tables')],
                        rows: array_map(fn ($t) => [$t], $result['dropped_tables']),
                    );
                }

                if ($result['success']) {
                    outro(__('ichava/ichava-core::commands.database.migrate.fresh_done'));

                    return self::SUCCESS;
                } else {
                    $this->failure(__('ichava/ichava-core::commands.database.migrate.failed'));

                    return self::FAILURE;
                }
            }, __('ichava/ichava-core::commands.database.migrate.failed'));
        }

        // Regular migration
        intro(__('ichava/ichava-core::commands.database.migrate.intro'));

        $exitCode = spin(
            callback: fn () => $this->databaseService->runMigrations(),
            message: __('ichava/ichava-core::commands.database.migrate.running'),
        );

        if ($exitCode === 0) {
            outro(__('ichava/ichava-core::commands.database.migrate.done'));

            return self::SUCCESS;
        } else {
            $this->failure(__('ichava/ichava-core::commands.database.migrate.failed'));

            return self::FAILURE;
        }
    }

    /**
     * Seed all (terms + icons)
     */
    protected function handleSeed(): int
    {
        intro(__('ichava/ichava-core::commands.database.seed.intro'));

        $this->startTiming();

        // Handle --fresh flag
        if ($this->option('fresh')) {
            if (! $this->confirmDestructive(
                __('ichava/ichava-core::commands.database.seed.fresh_confirm'),
                __('ichava/ichava-core::commands.database.seed.fresh_hint'),
            )) {
                return $this->cancelled(__('ichava/ichava-core::commands.common.cancelled'));
            }

            $truncateResult = $this->handleTruncate();
            if ($truncateResult !== self::SUCCESS) {
                return $truncateResult;
            }
        }

        // Pass sync mode to seeder
        if ($this->option('sync')) {
            $this->ichavaSeeder->setSyncMode(true);
        }

        // Seed terms first
        $termResult = $this->handleSeedTerms();
        if ($termResult !== self::SUCCESS) {
            return $termResult;
        }

        // Then seed icons
        $iconResult = $this->handleSeedIcons();
        if ($iconResult !== self::SUCCESS) {
            return $iconResult;
        }

        outro(__('ichava/ichava-core::commands.database.seed.done'));
        $this->displayElapsedTime();

        // Show queue instructions if using queue
        if (! $this->option('sync') && config('ichava.ichava-core.database.use_queue', true)) {
            warning(__('ichava/ichava-core::commands.database.seed.queued'));
            note(__('ichava/ichava-core::commands.database.seed.monitor', ['command' => CommandName::of(JobStatusCommand::class)]));
            note(__('ichava/ichava-core::commands.database.seed.view_stats', ['command' => $this->getName()]));
        } else {
            $this->displayDatabaseStats();
        }

        return self::SUCCESS;
    }

    /**
     * Seed icons only
     */
    protected function handleSeedIcons(): int
    {
        $forceUpdate = (bool) $this->option('update');

        info($forceUpdate ? __('ichava/ichava-core::commands.database.seed.icons_force') : __('ichava/ichava-core::commands.database.seed.icons'));

        return $this->tryExecute(function () use ($forceUpdate) {
            if ($this->option('sync')) {
                $this->ichavaSeeder->setSyncMode(true);
            }

            if ($forceUpdate) {
                $this->ichavaSeeder->setForceUpdate(true);
            }

            spin(
                callback: function () {
                    $this->ichavaSeeder->setCommand($this);
                    $this->ichavaSeeder->setContainer(app());
                    $this->ichavaSeeder->run();
                },
                message: __('ichava/ichava-core::commands.database.seed.icons_spinner'),
            );

            $this->logOperation('Icons seeded', [
                'package'      => $this->option('package'),
                'sync'         => $this->option('sync'),
                'force_update' => $forceUpdate,
            ]);

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.database.seed.icons_failed'));
    }

    /**
     * Seed terms only
     */
    protected function handleSeedTerms(): int
    {
        info(__('ichava/ichava-core::commands.database.seed.terms'));

        return $this->tryExecute(function () {
            spin(
                callback: function () {
                    $this->termSeeder->setCommand($this);
                    $this->termSeeder->setContainer(app());
                    $this->termSeeder->run();
                },
                message: __('ichava/ichava-core::commands.database.seed.terms_spinner'),
            );

            $this->logOperation('Terms seeded');

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.database.seed.terms_failed'));
    }

    /**
     * Handle unseed action
     */
    protected function handleUnseed(): int
    {
        $package = $this->option('package');

        if ($package) {
            return $this->unseedPackage($package);
        }

        // If no package specified, ask what to unseed
        if (! $this->option('force')) {
            $choice = select(
                label: __('ichava/ichava-core::commands.database.unseed.select'),
                options: [
                    'all'     => __('ichava/ichava-core::commands.database.unseed.options.all'),
                    'package' => __('ichava/ichava-core::commands.database.unseed.options.package'),
                    'cancel'  => __('ichava/ichava-core::commands.database.unseed.options.cancel'),
                ],
                default: 'cancel',
                hint: __('ichava/ichava-core::commands.database.unseed.select_hint'),
            );

            if ($choice === 'cancel') {
                return $this->cancelled(__('ichava/ichava-core::commands.common.cancelled'));
            }

            if ($choice === 'package') {
                // Get available packages
                $stats = $this->databaseService->getStatistics();
                $packageName = $this->askText(
                    label: __('ichava/ichava-core::commands.database.unseed.package_ask'),
                    placeholder: __('ichava/ichava-core::commands.database.unseed.package_placeholder'),
                    required: true,
                    hint: __('ichava/ichava-core::commands.database.unseed.package_hint'),
                );

                return $this->unseedPackage($packageName);
            }
        }

        return $this->unseedAll();
    }

    /**
     * Unseed a specific package
     */
    protected function unseedPackage(string $packageName): int
    {
        if (! $this->confirmDestructive(
            __('ichava/ichava-core::commands.database.unseed.package_confirm', ['package' => $packageName]),
            __('ichava/ichava-core::commands.database.unseed.package_confirm_hint'),
        )) {
            return $this->cancelled(__('ichava/ichava-core::commands.common.cancelled'));
        }

        intro(__('ichava/ichava-core::commands.database.unseed.package_intro', ['package' => $packageName]));

        return $this->tryExecute(function () use ($packageName) {
            $stats = spin(
                callback: fn () => $this->databaseService->unseedPackage($packageName),
                message: __('ichava/ichava-core::commands.database.unseed.package_removing'),
            );

            MetricTable::make()
                ->headers(value: __('ichava/ichava-core::commands.database.unseed.count'))
                ->metrics([
                    __('ichava/ichava-core::commands.database.unseed.icons_deleted')          => (int) $stats['icons_deleted'],
                    __('ichava/ichava-core::commands.database.unseed.term_relations_deleted') => (int) $stats['term_relations_deleted'],
                    __('ichava/ichava-core::commands.database.unseed.orphaned_terms_deleted') => (int) $stats['orphaned_terms_deleted'],
                ])
                ->render($this->output);

            outro(__('ichava/ichava-core::commands.database.unseed.package_done'));

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.database.unseed.package_failed'));
    }

    /**
     * Unseed all packages
     */
    protected function unseedAll(): int
    {
        if (! $this->confirmDestructive(
            __('ichava/ichava-core::commands.database.unseed.all_confirm'),
            __('ichava/ichava-core::commands.database.unseed.all_confirm_hint'),
        )) {
            return $this->cancelled(__('ichava/ichava-core::commands.common.cancelled'));
        }

        intro(__('ichava/ichava-core::commands.database.unseed.all_intro'));

        return $this->tryExecute(function () {
            $stats = spin(
                callback: fn () => $this->databaseService->unseedAll(),
                message: __('ichava/ichava-core::commands.database.unseed.all_removing'),
            );

            MetricTable::make()
                ->headers(value: __('ichava/ichava-core::commands.database.unseed.count'))
                ->metrics([
                    __('ichava/ichava-core::commands.database.unseed.icons_deleted')          => (int) $stats['icons_deleted'],
                    __('ichava/ichava-core::commands.database.unseed.term_relations_deleted') => (int) $stats['term_relations_deleted'],
                    __('ichava/ichava-core::commands.database.unseed.terms_deleted')          => (int) $stats['terms_deleted'],
                ])
                ->render($this->output);

            outro(__('ichava/ichava-core::commands.database.unseed.all_done'));

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.database.unseed.all_failed'));
    }

    /**
     * Refresh database (truncate + seed)
     */
    protected function handleRefresh(): int
    {
        if (! $this->confirmDestructive(
            __('ichava/ichava-core::commands.database.refresh.confirm'),
            __('ichava/ichava-core::commands.database.refresh.hint'),
        )) {
            return $this->cancelled(__('ichava/ichava-core::commands.common.cancelled'));
        }

        intro(__('ichava/ichava-core::commands.database.refresh.intro'));

        // Truncate
        $truncateResult = $this->handleTruncate();
        if ($truncateResult !== self::SUCCESS) {
            return $truncateResult;
        }

        // Seed
        return $this->handleSeed();
    }

    /**
     * Truncate tables
     */
    protected function handleTruncate(): int
    {
        if (! $this->confirmDestructive(
            __('ichava/ichava-core::commands.database.truncate.confirm'),
            __('ichava/ichava-core::commands.database.truncate.hint'),
        )) {
            return $this->cancelled(__('ichava/ichava-core::commands.common.cancelled'));
        }

        info(__('ichava/ichava-core::commands.database.truncate.intro'));

        return $this->tryExecute(function () {
            $truncated = spin(
                callback: fn () => $this->databaseService->truncateTables(),
                message: __('ichava/ichava-core::commands.database.truncate.running'),
            );

            info(__('ichava/ichava-core::commands.database.truncate.done', ['tables' => implode(', ', $truncated)]));
            $this->logOperation('Tables truncated');

            return self::SUCCESS;
        }, __('ichava/ichava-core::commands.database.truncate.failed'));
    }

    /**
     * Display database statistics
     */
    protected function handleStats(): int
    {
        intro(__('ichava/ichava-core::commands.database.stats.intro'));

        $this->displayDatabaseStats();

        return self::SUCCESS;
    }

    /**
     * Display database statistics
     */
    protected function displayDatabaseStats(): void
    {
        $stats = spin(
            callback: fn () => $this->databaseService->getStatistics(),
            message: __('ichava/ichava-core::commands.database.stats.gathering'),
        );

        $this->statisticsTable($stats)->render($this->output);
    }

    /**
     * Log seeding operation
     */
    protected function logOperation(string $operation, array $context = []): void
    {
        $this->logger->seedingInfo($operation, array_merge($context, [
            'command' => $this->getName(),
        ]));
    }
}
