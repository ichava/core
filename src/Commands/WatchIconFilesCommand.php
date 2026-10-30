<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

use Simtabi\Laranail\Ichava\Support\Coerce;
use Simtabi\Laranail\Console\Tools\Support\TimeFormat;
use Simtabi\Laranail\Console\Tools\Widgets\MetricTable;
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
    protected $signature = 'ichava::ichava-core.watch
                            {--force : Force scan even if already running}
                            {--package= : Rescan one pack now, by package name}
                            {--refresh : Record every pack\'s current fingerprint after the scan}';

    protected $description = 'Watch icon files for changes and auto-sync database';

    public function handle(IconWatcherService $watcher): int
    {
        intro(__('ichava/ichava-core::commands.watch.intro'));

        $this->line('  ' . __('ichava/ichava-core::commands.watch.last_scan', [
            'time' => IconWatcherService::getLastScanTime()?->toDateTimeString() ?? __('ichava/ichava-core::commands.info.health.never'),
        ]));

        $package = Coerce::stringOrNull($this->option('package'));

        if ($package !== null && $package !== '') {
            $result = $watcher->rescanPackage($package);

            if (! $result['found']) {
                warning(__('ichava/ichava-core::commands.watch.unknown_package', ['package' => $package]));

                return self::FAILURE;
            }

            info(__('ichava/ichava-core::commands.watch.rescanned', [
                'package' => $package,
                'new'     => $result['new'],
                'updated' => $result['updated'],
                'deleted' => $result['deleted'],
            ]));

            return self::SUCCESS;
        }

        $stats = spin(
            callback: fn () => $this->forced()
                ? $watcher->forceScan()
                : $watcher->watch(),
            message: __('ichava/ichava-core::commands.watch.scanning'),
        );

        if (($stats['status'] ?? null) === 'skipped') {
            warning(__('ichava/ichava-core::commands.watch.already_running'));

            return self::SUCCESS;
        }

        MetricTable::make()
            ->headers(value: __('ichava/ichava-core::commands.watch.count'))
            ->metrics([
                __('ichava/ichava-core::commands.watch.packages_scanned') => Coerce::int($stats['packages_scanned']),
                __('ichava/ichava-core::commands.watch.new_icons')        => Coerce::int($stats['new_icons']),
                __('ichava/ichava-core::commands.watch.updated_icons')    => Coerce::int($stats['updated_icons']),
                __('ichava/ichava-core::commands.watch.deleted_icons')    => Coerce::int($stats['deleted_icons']),
                __('ichava/ichava-core::commands.watch.total_changes')    => Coerce::int($stats['total_changes']),
                __('ichava/ichava-core::commands.watch.duration')         => TimeFormat::fromMillis(Coerce::float($stats['duration_ms'])),
            ])
            ->render($this->output);

        if ($this->option('refresh')) {
            info(__('ichava/ichava-core::commands.watch.refreshed', ['count' => $watcher->refreshFingerprints()]));
        }

        if ($stats['total_changes'] > 0) {
            outro(__('ichava/ichava-core::commands.watch.synchronized'));
        } else {
            info(__('ichava/ichava-core::commands.watch.no_changes'));
        }

        return self::SUCCESS;
    }
}
