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
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;
use Simtabi\Laranail\Ichava\Support\Seeder\IconTermsSeeder;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;

/**
 * Unified Icon Database Command
 *
 * Single command for all database operations: migrate, seed, unseed, truncate, stats.
 *
 * @example
 * php artisan ichava:database seed              # Seed all icons and terms
 * php artisan ichava:database seed --sync       # Seed synchronously (no queue)
 * php artisan ichava:database seed --package=X  # Seed specific package
 * php artisan ichava:database seed:icons        # Seed icons only
 * php artisan ichava:database seed:terms        # Seed terms only
 * php artisan ichava:database migrate           # Run Ichava migrations
 * php artisan ichava:database migrate --fresh   # Drop and re-run Ichava tables
 * php artisan ichava:database unseed            # Remove all Ichava data
 * php artisan ichava:database unseed --package=X # Remove specific package data
 * php artisan ichava:database refresh           # Truncate + seed
 * php artisan ichava:database truncate          # Truncate tables
 * php artisan ichava:database stats             # Show statistics
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
final class DatabaseCommand extends BaseCommand
{
    protected $signature = 'ichava:database
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
                label: 'What database operation would you like to perform?',
                options: [
                    'seed'       => 'Seed - Populate database with icons and terms',
                    'seed:icons' => 'Seed Icons - Seed icons only',
                    'seed:terms' => 'Seed Terms - Seed terms only',
                    'migrate'    => 'Migrate - Run Ichava migrations',
                    'unseed'     => 'Unseed - Remove icon data from database',
                    'refresh'    => 'Refresh - Truncate and re-seed',
                    'truncate'   => 'Truncate - Clear all tables',
                    'stats'      => 'Stats - Show database statistics',
                ],
                default: 'stats',
                hint: 'Select an action to perform',
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
            $confirmed = confirm(
                label: 'This will DROP all Ichava tables and re-run migrations. Continue?',
                default: false,
                yes: 'Yes, drop and recreate',
                no: 'No, cancel',
                hint: '⚠️ All existing Ichava data will be permanently deleted!',
            );

            if (! $confirmed && ! $this->option('force')) {
                warning('Operation cancelled.');

                return self::SUCCESS;
            }

            intro('🔄 Running fresh Ichava migration');

            return $this->tryExecute(function () {
                $result = spin(
                    callback: fn () => $this->databaseService->freshMigration(),
                    message: 'Dropping and recreating tables...',
                );

                if (! empty($result['dropped_tables'])) {
                    table(
                        headers: ['Dropped Tables'],
                        rows: array_map(fn ($t) => [$t], $result['dropped_tables']),
                    );
                }

                if ($result['success']) {
                    outro('✅ Fresh migration completed successfully');

                    return self::SUCCESS;
                } else {
                    $this->failure('Migration failed');

                    return self::FAILURE;
                }
            }, 'Migration failed');
        }

        // Regular migration
        intro('🔄 Running Ichava migrations');

        $exitCode = spin(
            callback: fn () => $this->databaseService->runMigrations(),
            message: 'Running migrations...',
        );

        if ($exitCode === 0) {
            outro('✅ Migrations completed successfully');

            return self::SUCCESS;
        } else {
            $this->failure('Migration failed');

            return self::FAILURE;
        }
    }

    /**
     * Seed all (terms + icons)
     */
    protected function handleSeed(): int
    {
        intro('🌱 Seeding Ichava database');

        $this->startTiming();

        // Handle --fresh flag
        if ($this->option('fresh')) {
            $confirmed = confirm(
                label: 'This will delete all existing data before seeding. Continue?',
                default: false,
                yes: 'Yes, clear and seed',
                no: 'No, cancel',
                hint: '⚠️ Existing icons and terms will be deleted!',
            );

            if (! $confirmed && ! $this->option('force')) {
                warning('Operation cancelled.');

                return self::SUCCESS;
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

        outro('✅ Database seeded successfully');
        $this->displayElapsedTime();

        // Show queue instructions if using queue
        if (! $this->option('sync') && config('ichava.database.use_queue', true)) {
            warning('Icon seeding jobs are queued. Stats will be accurate after jobs complete.');
            note('Monitor jobs: php artisan ichava:job-status');
            note('View stats: php artisan ichava:database stats');
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

        info('📦 Seeding icons...' . ($forceUpdate ? ' (force update mode)' : ''));

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
                message: 'Seeding icons...',
            );

            $this->logOperation('Icons seeded', [
                'package'      => $this->option('package'),
                'sync'         => $this->option('sync'),
                'force_update' => $forceUpdate,
            ]);

            return self::SUCCESS;
        }, 'Failed to seed icons');
    }

    /**
     * Seed terms only
     */
    protected function handleSeedTerms(): int
    {
        info('🏷️  Seeding terms...');

        return $this->tryExecute(function () {
            spin(
                callback: function () {
                    $this->termSeeder->setCommand($this);
                    $this->termSeeder->setContainer(app());
                    $this->termSeeder->run();
                },
                message: 'Seeding terms...',
            );

            $this->logOperation('Terms seeded');

            return self::SUCCESS;
        }, 'Failed to seed terms');
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
                label: 'What would you like to unseed?',
                options: [
                    'all'     => 'All packages - Remove all Ichava data',
                    'package' => 'Specific package - Choose a package to unseed',
                    'cancel'  => 'Cancel - Do nothing',
                ],
                default: 'cancel',
                hint: 'Select what to unseed',
            );

            if ($choice === 'cancel') {
                warning('Operation cancelled.');

                return self::SUCCESS;
            }

            if ($choice === 'package') {
                // Get available packages
                $stats = $this->databaseService->getStatistics();
                $packageName = $this->askText(
                    label: 'Enter the package name to unseed',
                    placeholder: 'e.g., ichava/icons-bundle',
                    required: true,
                    hint: 'Enter the full package name (vendor/package)',
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
        $confirmed = confirm(
            label: "This will remove all data for package '{$packageName}'. Continue?",
            default: false,
            yes: 'Yes, unseed package',
            no: 'No, cancel',
            hint: '⚠️ Icons and term relationships for this package will be deleted!',
        );

        if (! $confirmed && ! $this->option('force')) {
            warning('Operation cancelled.');

            return self::SUCCESS;
        }

        intro("🗑️  Unseeding package: {$packageName}");

        return $this->tryExecute(function () use ($packageName) {
            $stats = spin(
                callback: fn () => $this->databaseService->unseedPackage($packageName),
                message: 'Removing package data...',
            );

            table(
                headers: ['Metric', 'Count'],
                rows: [
                    ['Icons deleted', $this->formatNumber($stats['icons_deleted'])],
                    ['Term relations deleted', $this->formatNumber($stats['term_relations_deleted'])],
                    ['Orphaned terms deleted', $this->formatNumber($stats['orphaned_terms_deleted'])],
                ],
            );

            outro('✅ Package unseeded successfully');

            return self::SUCCESS;
        }, 'Failed to unseed package');
    }

    /**
     * Unseed all packages
     */
    protected function unseedAll(): int
    {
        $confirmed = confirm(
            label: 'This will remove ALL Ichava data. Continue?',
            default: false,
            yes: 'Yes, remove all data',
            no: 'No, cancel',
            hint: '⚠️ ALL icons, terms, and relationships will be permanently deleted!',
        );

        if (! $confirmed && ! $this->option('force')) {
            warning('Operation cancelled.');

            return self::SUCCESS;
        }

        intro('🗑️  Unseeding all packages');

        return $this->tryExecute(function () {
            $stats = spin(
                callback: fn () => $this->databaseService->unseedAll(),
                message: 'Removing all data...',
            );

            table(
                headers: ['Metric', 'Count'],
                rows: [
                    ['Icons deleted', $this->formatNumber($stats['icons_deleted'])],
                    ['Term relations deleted', $this->formatNumber($stats['term_relations_deleted'])],
                    ['Terms deleted', $this->formatNumber($stats['terms_deleted'])],
                ],
            );

            outro('✅ All packages unseeded successfully');

            return self::SUCCESS;
        }, 'Failed to unseed');
    }

    /**
     * Refresh database (truncate + seed)
     */
    protected function handleRefresh(): int
    {
        $confirmed = confirm(
            label: 'This will delete all existing data and re-seed. Continue?',
            default: false,
            yes: 'Yes, refresh database',
            no: 'No, cancel',
            hint: '⚠️ All existing icons and terms will be replaced!',
        );

        if (! $confirmed && ! $this->option('force')) {
            warning('Operation cancelled.');

            return self::SUCCESS;
        }

        intro('🔄 Refreshing database');

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
        if (! $this->option('force')) {
            $confirmed = confirm(
                label: 'This will delete all icons and terms. Continue?',
                default: false,
                yes: 'Yes, truncate tables',
                no: 'No, cancel',
                hint: '⚠️ All data will be permanently deleted!',
            );

            if (! $confirmed) {
                warning('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        info('🗑️  Truncating tables...');

        return $this->tryExecute(function () {
            $truncated = spin(
                callback: fn () => $this->databaseService->truncateTables(),
                message: 'Truncating tables...',
            );

            info('Tables truncated: ' . implode(', ', $truncated));
            $this->logOperation('Tables truncated');

            return self::SUCCESS;
        }, 'Failed to truncate');
    }

    /**
     * Display database statistics
     */
    protected function handleStats(): int
    {
        intro('📊 Ichava Database Statistics');

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
            message: 'Gathering statistics...',
        );

        table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Total Icons', $this->formatNumber($stats['icons'])],
                ['Total Packages', $this->formatNumber($stats['packages'])],
                ['Categories', $this->formatNumber($stats['categories'])],
                ['Variants', $this->formatNumber($stats['variants'])],
                ['Term Relationships', $this->formatNumber($stats['term_relationships'])],
                ['Database Size', $stats['database_size'] ?? 'N/A'],
            ],
        );
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
