<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Command output
|--------------------------------------------------------------------------
|
| Strings the Artisan commands print. Grouped by command so a translator sees
| one screen's worth of context at a time, and so a removed command takes its
| strings with it.
|
| Command *descriptions* are deliberately absent: `$description` is a property,
| evaluated before the container exists, so `__()` cannot run there.
|
*/

return [

    // Shared by every command through BaseCommand.
    'common' => [
        'completed_in'       => '⏱️  Completed in :time',
        'tip'                => '💡 :message',
        'invalid_action'     => 'Invalid action: :action',
        'valid_actions'      => 'Valid actions: :actions',
        'select_action'      => 'Would you like to select a valid action?',
        'select_action_hint' => 'Select an action or cancel',
        'invalid_type'       => 'Invalid type: :type',
        'valid_types'        => 'Valid types: :types',
        'select_type'        => 'Would you like to select a valid type?',
        'select_type_hint'   => 'Select a type or cancel',
        'cancel_option'      => 'Cancel operation',
        'exported'           => 'Exported to: :path',
        'export_failed'      => 'Failed to export: :error',
        'tables_missing'     => 'Required tables do not exist: :tables',
        'run_migrations'     => 'Run migrations first: php artisan :command migrate',
        'operation_failed'   => 'Operation failed',
        'yes'                => 'Yes',
        'no'                 => 'No',
        'cancelled'          => 'Operation cancelled.',
        'not_available'      => 'N/A',

        'stats' => [
            'icons'              => 'Total Icons',
            'packages'           => 'Total Packages',
            'categories'         => 'Categories',
            'variants'           => 'Variants',
            'term_relationships' => 'Term Relationships',
            'database_size'      => 'Database Size',
            'cache_driver'       => 'Cache Driver',
        ],
    ],

    'cleanup_logs' => [
        'analyzing'         => 'Analyzing log files...',
        'processing'        => 'Processing log files...',
        'processing_hint'   => 'This may take a moment for large log directories',
        'dry_run'           => 'DRY RUN MODE - No files will be deleted',
        'none_found'        => 'No Ichava log files found',
        'nothing_to_do'     => 'No old log files to clean up.',
        'dir_missing'       => 'Log directory not found: :path',
        'retention_ask'     => 'How many days of logs to retain?',
        'retention_hint'    => 'Logs older than this will be deleted',
        'retention_invalid' => 'Please enter a valid number of days (minimum 1)',
        'total_files'       => 'Total log files',
        'would_delete'      => 'Would delete',
        'deleted'           => 'Deleted',

        // Laravel Prompts surfaces: intro/outro/note/warning and the two
        // summary tables. These reach the user exactly like the strings above;
        // they were missed on the first pass because the guard only watched
        // `$this->` helpers and these are free functions.
        'intro'             => '🧹 Cleaning up Ichava logs older than :days days',
        'cleaned_up'        => '✅ Cleaned up :count old log file(s)',
        'would_delete_note' => 'Would delete :count file(s). Run without --dry-run to actually delete.',
        'delete_failed'     => ':count file(s) failed to delete. Check permissions.',

        'table' => [
            'metric' => 'Metric',
            'count'  => 'Count',
            'kept'   => 'Kept',
            'failed' => 'Failed',
            'file'   => 'File',
            'age'    => 'Age',
            'action' => 'Action',
            'days'   => ':days days',
        ],

        // The glyph comes from the shared status vocabulary, not the string.
        'action' => [
            'deleted'      => 'Deleted',
            'would_delete' => 'Would delete',
            'failed'       => 'Failed',
            'kept'         => 'Kept',
        ],
    ],

    'watch' => [
        'intro'            => '👁️ Watching icon files for changes',
        'scanning'         => 'Scanning for changes...',
        'already_running'  => 'File watcher already running, skipped.',
        'count'            => 'Count',
        'packages_scanned' => 'Packages Scanned',
        'new_icons'        => 'New Icons',
        'updated_icons'    => 'Updated Icons',
        'deleted_icons'    => 'Deleted Icons',
        'total_changes'    => 'Total Changes',
        'duration'         => 'Duration',
        'synchronized'     => '✅ Database synchronized with file system!',
        'no_changes'       => '✨ No changes detected, database up to date.',
    ],

    'check_updates' => [
        'intro'       => '🔍 Checking icon-pack upstream sources',
        'polling'     => 'Polling upstream sources (12h cache on hit)…',
        'none'        => 'No registered packs to check.',
        'behind'      => '⚠️  :count pack(s) behind upstream',
        'unreachable' => '⚠️  :count pack(s) unreachable; rest up to date',
        'up_to_date'  => '✅ All packs up to date',

        'table' => [
            'package' => 'Package',
            'source'  => 'Source',
            'status'  => 'Status',
            'current' => 'Current',
            'latest'  => 'Latest',
            'notes'   => 'Notes',
        ],
    ],

    'cache' => [
        'select'      => 'What cache operation would you like to perform?',
        'select_hint' => 'Select an action to perform',

        'options' => [
            'clear'    => 'Clear - Remove all cached data',
            'rebuild'  => 'Rebuild - Regenerate all caches',
            'refresh'  => 'Refresh - Clear and rebuild caches',
            'generate' => 'Generate - Create production-optimized cache',
            'manifest' => 'Manifest - Generate icon manifest file',
            'stats'    => 'Stats - Show cache statistics',
        ],

        'clear' => [
            'intro'            => '🧹 Clearing icon caches',
            'clearing_all'     => 'Clearing all caches...',
            'clearing_package' => 'Clearing cache for package: :package...',
            'cleared'          => 'Cleared :count cache key(s)',
            'cleared_keys'     => 'Cleared Cache Keys',
            'failed'           => 'Failed to clear cache',
        ],

        'rebuild' => [
            'intro'      => '🔨 Rebuilding icon caches',
            'rebuilding' => 'Rebuilding caches...',
            'done'       => '✅ Cache rebuilt successfully',
            'failed'     => 'Failed to rebuild cache',
        ],

        'refresh' => [
            'intro'      => '🔄 Refreshing icon caches',
            'refreshing' => 'Clearing and rebuilding caches...',
            'done'       => '✅ Cache refreshed successfully',
            'failed'     => 'Failed to refresh cache',
        ],

        'generate' => [
            'intro'      => '⚡ Generating production cache',
            'generating' => 'Generating optimized production cache...',
            'done'       => '✅ Production cache generated',
            'failed'     => 'Failed to generate cache',
        ],

        'manifest' => [
            'intro'          => '🎨 Generating Ichava icon manifest',
            'fresh'          => '🟢 Manifest is fresh; skipping. Use --force to rebuild.',
            'overwrite'      => 'Manifest exists and is stale. Overwrite?',
            'overwrite_yes'  => 'Yes, rebuild',
            'overwrite_no'   => 'No, cancel',
            'overwrite_hint' => 'The existing manifest will be replaced',
            'cancelled'      => 'Manifest generation cancelled.',
            'generating'     => 'Generating manifest file...',
            'saved'          => '📁 Manifest saved to: :path',
            'deploy_tip'     => '💡 Add this command to your deployment process: php artisan :command manifest --force',
            'done'           => '✅ Manifest generation complete!',
            'failed'         => 'Failed to generate manifest',
        ],

        'stats' => [
            'intro'     => '📊 Ichava Cache Statistics',
            'gathering' => 'Gathering cache statistics...',
        ],

        'metric' => [
            'categories'        => 'Categories',
            'packages'          => 'Packages',
            'total_icons'       => 'Total Icons',
            'build_time'        => 'Build Time',
            'keys_cleared'      => 'Keys Cleared',
            'file_size'         => 'File Size',
            'driver'            => 'Cache Driver',
            'cached_packages'   => 'Cached Packages',
            'cached_categories' => 'Cached Categories',
            'total_keys'        => 'Total Cache Keys',
            'manifest_exists'   => 'Manifest Exists',
            'manifest_stale'    => 'Manifest Stale',
        ],
    ],

    'job_status' => [
        'intro'                => '📊 Ichava Icon Seeding Job Status',
        'intro_package'        => '📊 Job Status: :package',
        'no_packages'          => 'No icon packages registered.',
        'no_progress'          => 'No job progress data found.',
        'no_progress_hint'     => 'Jobs are tracked after running: php artisan :command seed',
        'no_progress_for'      => 'No progress data found for: :package',
        'no_progress_for_hint' => 'This package may not have been seeded yet, or progress data has expired.',
        'no_data'              => 'No data',
        'summary'              => '📊 Summary:',
        'counting'             => 'Counting icons...',
        'progress'             => 'Progress:',
        'icons'                => 'Icons: :processed / :total',
        'started'              => 'Started: :at (:ago)',
        'completed'            => 'Completed: :at (:ago)',
        'duration'             => 'Duration: :duration',
        'exception'            => 'Exception: :class',
        'in_database'          => 'Icons in database: :count',

        'table' => [
            'package'  => 'Package',
            'status'   => 'Status',
            'progress' => 'Progress',
            'icons'    => 'Icons',
            'updated'  => 'Updated',
            'count'    => 'Count',
            'property' => 'Property',
            'job_id'   => 'Job ID',
        ],

        'metric' => [
            'active'      => 'Active jobs',
            'completed'   => 'Completed',
            'failed'      => 'Failed',
            'total_icons' => 'Total icons in DB',
        ],

        'clear' => [
            'confirm'  => "Clear progress data for ':package'?",
            'hint'     => 'This will remove the cached progress tracking data',
            'clearing' => 'Clearing progress...',
            'done'     => '✅ Progress cleared for: :package',
        ],
    ],

    'info' => [
        'select'      => 'What information would you like to view?',
        'select_hint' => 'Select what to display',

        'options' => [
            'stats'     => 'Stats - Overview statistics',
            'packages'  => 'Packages - List registered icon packages',
            'icons'     => 'Icons - Browse icons',
            'status'    => 'Status - Lifecycle and health status',
            'languages' => 'Languages - PostgreSQL FTS languages',
            'discover'  => 'Discover - Find unregistered packages',
        ],

        'packages' => [
            'intro'              => '📦 Registered Icon Packages',
            'loading'            => 'Loading packages...',
            'none'               => 'No packages registered.',
            'none_hint'          => 'Register packages in your service provider using IchavaRegistrar',
            'search'             => 'Search packages (leave empty to show all)',
            'search_placeholder' => 'e.g., fontawesome',
            'search_hint'        => 'Filter packages by name',
        ],

        'icons' => [
            'intro'              => '🎨 Icon Browser',
            'search'             => 'Search icons',
            'search_placeholder' => 'e.g., arrow, user, check',
            'search_hint'        => 'Filter icons by name',
            'loading'            => 'Loading icons...',
            'none'               => 'No icons found.',
            'showing'            => 'Showing :count icons. Use --limit to show more.',
        ],

        'status' => [
            'intro'       => '🔍 Ichava Lifecycle Status',
            'resetting'   => 'Resetting lifecycle state...',
            'reset'       => 'Lifecycle state reset',
            'checking'    => 'Checking status...',
            'migrations'  => 'Migrations',
            'seeds'       => 'Seeds',
            'cache'       => 'Cache',
            'stage'       => 'Current Stage: :stage',
            'ready'       => 'System Ready:  :ready',
            'icon_count'  => 'Icon Count:   :count',
            'next_steps'  => 'Next Steps:',
            'operational' => '✅ Ichava is fully operational!',
        ],

        'languages' => [
            'intro'     => '🌍 PostgreSQL FTS Languages',
            'loading'   => 'Loading languages...',
            'none'      => 'No FTS languages found or not using PostgreSQL.',
            'current'   => '📌 Current language: :language',
            'configure' => 'Configure in config/ichava.php or ICHAVA_SEARCH_LANGUAGE env var',

            'table' => [
                'language'    => 'Language',
                'owner'       => 'Owner',
                'description' => 'Description',
            ],
        ],

        'discover' => [
            'intro'    => '🔍 Discovering Icon Packages',
            'scanning' => 'Scanning filesystem...',
            'none'     => 'No packages discovered.',
        ],

        'stats' => [
            'intro'       => '📊 Ichava Statistics',
            'gathering'   => 'Gathering statistics...',
            'loading_top' => 'Loading top packages...',
            'top'         => '🏆 Top :count Packages by Icon Count:',
        ],

        'table' => [
            'package'    => 'Package',
            'path'       => 'Path',
            'icons'      => 'Icons',
            'status'     => 'Status',
            'name'       => 'Name',
            'registered' => 'Registered',
            'icon_count' => 'Icon Count',
        ],
    ],

    'install' => [
        'intro'                     => '🧩 Install Ichava Icon Set',
        'catalog_failed'            => 'Could not load icon set catalog: :error',
        'catalog_empty'             => 'No icon sets declared in icon-sets.json.',
        'select'                    => 'Which icon set would you like to install?',
        'select_hint'               => 'Pick a set to require via Composer and seed',
        'option'                    => ':title (:count icons, :variants) — :installed · :seeded',
        'unknown_set'               => "Unknown icon set ':set'.",
        'available'                 => 'Available: :sets',
        'already_installed'         => "':title' looks already installed.",
        'already_installed_version' => "':title' looks already installed. (version :version)",
        'reinstall_note'            => 'Re-running will require the latest release and re-seed its icons.',
        'reinstall_confirm'         => 'Continue with reinstall?',
        'latest'                    => 'Latest release: :version',
        'latest_unknown'            => 'Could not resolve the latest release tag; composer will install the newest stable release.',
        'require_confirm'           => "Require ':target' via Composer?",
        'seed_skipped'              => 'Skipped seeding. Seed later with: php artisan :command seed --package=:package',
        'required'                  => '✅ :title required successfully',
        'seeding'                   => 'Seeding icons for :package...',
        'seed_failed'               => "Composer require succeeded but seeding ':package' failed.",
        'seed_retry'                => 'Retry seeding with: php artisan :command seed --package=:package',
        'installed'                 => '✅ :title installed and seeded successfully',
        'tables_missing'            => 'Core database tables are missing. Icons cannot be seeded until core migrations have run.',
        'missing_tables'            => 'Missing tables: :tables',
        'migrate_confirm'           => 'Run core migrations now?',
        'rerun'                     => 'Re-run this command once migration is done: php artisan :command',
        'migrate_failed'            => 'Core migrations did not complete.',
        'migrated'                  => 'Core migrations completed.',

        // The glyph in front of each comes from the shared status vocabulary.
        'state' => [
            'installed'         => 'installed',
            'installed_version' => 'installed (:version)',
            'not_installed'     => 'not installed',
            'seeded'            => 'seeded',
            'seeded_count'      => 'seeded (:count)',
            'not_seeded'        => 'not seeded',
        ],

        'composer' => [
            'refused'       => "Refusing to run composer with unexpected target ':target'.",
            'running'       => 'Running composer require :target...',
            'failed_manual' => 'Composer require failed. Run it manually to see full output:',
            'manual'        => ':composer require :target',
            'done'          => 'Composer require completed: :target',
            'failed'        => 'Composer require failed: :error',
        ],
    ],

    'database' => [
        'select'      => 'What database operation would you like to perform?',
        'select_hint' => 'Select an action to perform',

        'options' => [
            'seed'       => 'Seed - Populate database with icons and terms',
            'seed_icons' => 'Seed Icons - Seed icons only',
            'seed_terms' => 'Seed Terms - Seed terms only',
            'migrate'    => 'Migrate - Run Ichava migrations',
            'unseed'     => 'Unseed - Remove icon data from database',
            'refresh'    => 'Refresh - Truncate and re-seed',
            'truncate'   => 'Truncate - Clear all tables',
            'stats'      => 'Stats - Show database statistics',
        ],

        'migrate' => [
            'intro'          => '🔄 Running Ichava migrations',
            'running'        => 'Running migrations...',
            'done'           => '✅ Migrations completed successfully',
            'failed'         => 'Migration failed',
            'fresh_confirm'  => 'This will DROP all Ichava tables and re-run migrations. Continue?',
            'fresh_hint'     => '⚠️ All existing Ichava data will be permanently deleted!',
            'fresh_intro'    => '🔄 Running fresh Ichava migration',
            'fresh_running'  => 'Dropping and recreating tables...',
            'dropped_tables' => 'Dropped Tables',
            'fresh_done'     => '✅ Fresh migration completed successfully',
        ],

        'seed' => [
            'intro'         => '🌱 Seeding Ichava database',
            'fresh_confirm' => 'This will delete all existing data before seeding. Continue?',
            'fresh_hint'    => '⚠️ Existing icons and terms will be deleted!',
            'done'          => '✅ Database seeded successfully',
            'queued'        => 'Icon seeding jobs are queued. Stats will be accurate after jobs complete.',
            'monitor'       => 'Monitor jobs: php artisan :command',
            'view_stats'    => 'View stats: php artisan :command stats',
            'icons'         => '📦 Seeding icons...',
            'icons_force'   => '📦 Seeding icons... (force update mode)',
            'icons_spinner' => 'Seeding icons...',
            'icons_failed'  => 'Failed to seed icons',
            'terms'         => '🏷️  Seeding terms...',
            'terms_spinner' => 'Seeding terms...',
            'terms_failed'  => 'Failed to seed terms',
        ],

        'unseed' => [
            'select'      => 'What would you like to unseed?',
            'select_hint' => 'Select what to unseed',

            'options' => [
                'all'     => 'All packages - Remove all Ichava data',
                'package' => 'Specific package - Choose a package to unseed',
                'cancel'  => 'Cancel - Do nothing',
            ],

            'package_ask'            => 'Enter the package name to unseed',
            'package_placeholder'    => 'e.g., ichava/icons-bundle',
            'package_hint'           => 'Enter the full package name (vendor/package)',
            'package_confirm'        => "This will remove all data for package ':package'. Continue?",
            'package_confirm_hint'   => '⚠️ Icons and term relationships for this package will be deleted!',
            'package_intro'          => '🗑️  Unseeding package: :package',
            'package_removing'       => 'Removing package data...',
            'package_done'           => '✅ Package unseeded successfully',
            'package_failed'         => 'Failed to unseed package',
            'all_confirm'            => 'This will remove ALL Ichava data. Continue?',
            'all_confirm_hint'       => '⚠️ ALL icons, terms, and relationships will be permanently deleted!',
            'all_intro'              => '🗑️  Unseeding all packages',
            'all_removing'           => 'Removing all data...',
            'all_done'               => '✅ All packages unseeded successfully',
            'all_failed'             => 'Failed to unseed',
            'count'                  => 'Count',
            'icons_deleted'          => 'Icons deleted',
            'term_relations_deleted' => 'Term relations deleted',
            'orphaned_terms_deleted' => 'Orphaned terms deleted',
            'terms_deleted'          => 'Terms deleted',
        ],

        'refresh' => [
            'confirm' => 'This will delete all existing data and re-seed. Continue?',
            'hint'    => '⚠️ All existing icons and terms will be replaced!',
            'intro'   => '🔄 Refreshing database',
        ],

        'truncate' => [
            'confirm' => 'This will delete all icons and terms. Continue?',
            'hint'    => '⚠️ All data will be permanently deleted!',
            'intro'   => '🗑️  Truncating tables...',
            'running' => 'Truncating tables...',
            'done'    => 'Tables truncated: :tables',
            'failed'  => 'Failed to truncate',
        ],

        'stats' => [
            'intro'     => '📊 Ichava Database Statistics',
            'gathering' => 'Gathering statistics...',
        ],
    ],

];
