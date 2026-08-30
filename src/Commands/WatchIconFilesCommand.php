<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

use Simtabi\Laranail\Ichava\Services\IconWatcherService;

/**
 * Watch Icon Files Command
 *
 * Watches icon files for changes and auto-syncs the database.
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
class WatchIconFilesCommand extends BaseCommand
{
    protected $signature = 'ichava:watch
                            {--force : Force scan even if already running}';

    protected $description = 'Watch icon files for changes and auto-sync database';

    public function handle(IconWatcherService $watcher): int
    {
        intro('👁️ Watching icon files for changes');

        $stats = spin(
            callback: fn () => $this->option('force')
                ? $watcher->forceScan()
                : $watcher->watch(),
            message: 'Scanning for changes...',
        );

        if (($stats['status'] ?? null) === 'skipped') {
            warning('File watcher already running, skipped.');

            return self::SUCCESS;
        }

        // Display results
        table(
            headers: ['Metric', 'Count'],
            rows: [
                ['Packages Scanned', (string) $stats['packages_scanned']],
                ['New Icons', (string) $stats['new_icons']],
                ['Updated Icons', (string) $stats['updated_icons']],
                ['Deleted Icons', (string) $stats['deleted_icons']],
                ['Total Changes', (string) $stats['total_changes']],
                ['Duration', "{$stats['duration_ms']}ms"],
            ],
        );

        if ($stats['total_changes'] > 0) {
            outro('✅ Database synchronized with file system!');
        } else {
            info('✨ No changes detected, database up to date.');
        }

        return self::SUCCESS;
    }
}
