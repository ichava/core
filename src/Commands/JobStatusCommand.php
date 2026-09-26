<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use Carbon\Carbon;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Support\CommandName;
use Simtabi\Laranail\Console\Tools\Widgets\Gauge;
use Simtabi\Laranail\Console\Tools\Support\Status;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Console\Tools\Support\TimeFormat;
use Simtabi\Laranail\Console\Tools\Widgets\MetricTable;
use Simtabi\Laranail\Console\Tools\Widgets\StatusBadge;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;
use Simtabi\Laranail\Package\Tools\Services\Database\SeederRunTracker;

/**
 * Display icon seeding job status
 *
 * Shows progress of currently running and recently completed icon seeding jobs.
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
class JobStatusCommand extends BaseCommand
{
    protected $signature = 'ichava::ichava-core.job-status
                            {package? : Specific package to check}
                            {--all : Show all packages including inactive}
                            {--clear= : Clear progress for a specific package}
                            {--force : Force operation without confirmation}';

    protected $description = 'Display icon seeding job status and progress';

    public function handle(): int
    {
        if ($clearPackage = $this->option('clear')) {
            return $this->clearProgress($clearPackage);
        }

        $package = $this->argument('package');

        if ($package) {
            return $this->displaySinglePackage($package);
        }

        return $this->displayAllPackages();
    }

    /**
     * A package's seeding progress, from package-tools' SeederRunTracker, in the
     * shape this command renders. Seeding writes there under
     * IchavaSeeder::trackingKey(); the JobProgressTracker this used to read was
     * never written by anything.
     *
     * @return array<string, mixed>|null
     */
    protected function progressFor(string $packageName): ?array
    {
        $state = app(SeederRunTracker::class)->get(IchavaSeeder::trackingKey($packageName));

        if ($state === null) {
            return null;
        }

        $finished = $state['finished_at'] !== null;

        return [
            'status'           => $state['status']->value,
            'total'            => $state['total'],
            'processed'        => $state['processed'],
            'progress_percent' => $state['total'] > 0 ? round(min(100, $state['processed'] / $state['total'] * 100), 1) : 0,
            'started_at'       => $state['started_at'],
            'updated_at'       => $state['finished_at'] ?? $state['started_at'],
            'completed_at'     => $finished && $state['status']->value === 'completed' ? $state['finished_at'] : null,
            'duration_seconds' => $finished && $state['started_at'] !== null
                ? Carbon::parse($state['started_at'])->diffInSeconds(Carbon::parse($state['finished_at']))
                : null,
            'error' => $state['status']->value === 'failed' ? $state['message'] : null,
        ];
    }

    /**
     * Display status for a single package
     */
    protected function displaySinglePackage(string $packageName): int
    {
        intro(__('ichava/ichava-core::commands.job_status.intro_package', ['package' => $packageName]));

        $progress = $this->progressFor($packageName);

        if (! $progress) {
            warning(__('ichava/ichava-core::commands.job_status.no_progress_for', ['package' => $packageName]));
            $this->tip(__('ichava/ichava-core::commands.job_status.no_progress_for_hint'));

            return self::SUCCESS;
        }

        $this->displayProgressData($packageName, $progress);

        return self::SUCCESS;
    }

    /**
     * Display status for all packages
     */
    protected function displayAllPackages(): int
    {
        intro(__('ichava/ichava-core::commands.job_status.intro'));

        $registry = app(IconRegistry::class);
        $packages = $registry->all();

        if (empty($packages)) {
            warning(__('ichava/ichava-core::commands.job_status.no_packages'));

            return self::SUCCESS;
        }

        $activeJobs = 0;
        $completedJobs = 0;
        $failedJobs = 0;

        $rows = collect($packages)->map(function ($packageData, $packageName) use (&$activeJobs, &$completedJobs, &$failedJobs) {
            $progress = $this->progressFor($packageName);

            if (! $progress) {
                if ($this->option('all')) {
                    return [
                        $packageName,
                        StatusBadge::of(Status::Unknown)->label(__('ichava/ichava-core::commands.job_status.no_data'))->render(),
                        '-',
                        (string) $this->countDatabaseIcons($packageName),
                        '-',
                    ];
                }

                return null;
            }

            $status = $progress['status'] ?? 'unknown';
            $progressPercent = $progress['progress_percent'] ?? 0;
            $processed = $progress['processed'] ?? 0;
            $total = $progress['total'] ?? 0;
            $updatedAt = isset($progress['updated_at']) ? Carbon::parse($progress['updated_at'])->diffForHumans() : '-';

            match ($status) {
                'processing' => $activeJobs++,
                'completed'  => $completedJobs++,
                'failed'     => $failedJobs++,
                default      => null,
            };

            return [
                $packageName,
                $this->formatStatus($status),
                Gauge::make((float) $progressPercent)->width(10)->render(),
                "{$processed}/{$total}",
                $updatedAt,
            ];
        })->filter()->values()->toArray();

        if (empty($rows)) {
            warning(__('ichava/ichava-core::commands.job_status.no_progress'));
            $this->tip(__('ichava/ichava-core::commands.job_status.no_progress_hint', [
                'command' => CommandName::of(DatabaseCommand::class),
            ]));

            return self::SUCCESS;
        }

        table(
            headers: [
                __('ichava/ichava-core::commands.job_status.table.package'),
                __('ichava/ichava-core::commands.job_status.table.status'),
                __('ichava/ichava-core::commands.job_status.table.progress'),
                __('ichava/ichava-core::commands.job_status.table.icons'),
                __('ichava/ichava-core::commands.job_status.table.updated'),
            ],
            rows: $rows,
        );

        // Summary
        $this->newLine();
        info(__('ichava/ichava-core::commands.job_status.summary'));

        $totalIcons = spin(
            callback: fn () => Icon::count(),
            message: __('ichava/ichava-core::commands.job_status.counting'),
        );

        MetricTable::make()
            ->headers(value: __('ichava/ichava-core::commands.job_status.table.count'))
            ->metrics([
                __('ichava/ichava-core::commands.job_status.metric.active')      => $activeJobs,
                __('ichava/ichava-core::commands.job_status.metric.completed')   => $completedJobs,
                __('ichava/ichava-core::commands.job_status.metric.failed')      => $failedJobs,
                __('ichava/ichava-core::commands.job_status.metric.total_icons') => (int) $totalIcons,
            ])
            ->render($this->output);

        return self::SUCCESS;
    }

    /**
     * Display detailed progress data
     */
    protected function displayProgressData(string $packageName, array $progress): void
    {
        $status = $progress['status'] ?? 'unknown';

        MetricTable::make()
            ->headers(__('ichava/ichava-core::commands.job_status.table.property'))
            ->metrics([
                __('ichava/ichava-core::commands.job_status.table.package') => $packageName,
                __('ichava/ichava-core::commands.job_status.table.status')  => $this->formatStatus($status),
            ])
            ->render($this->output);

        $processed = $progress['processed'] ?? 0;
        $total = $progress['total'] ?? 0;
        $progressPercent = $progress['progress_percent'] ?? 0;

        $this->newLine();
        info(__('ichava/ichava-core::commands.job_status.progress'));
        $this->detail(Gauge::make((float) $progressPercent)->width(20)->render());
        $this->detail(__('ichava/ichava-core::commands.job_status.icons', ['processed' => $processed, 'total' => $total]));

        if (isset($progress['started_at'])) {
            $startedAt = Carbon::parse($progress['started_at']);
            $this->detail(__('ichava/ichava-core::commands.job_status.started', [
                'at'  => $startedAt->format('Y-m-d H:i:s'),
                'ago' => $startedAt->diffForHumans(),
            ]));
        }

        if (($progress['completed_at'] ?? null) !== null) {
            $completedAt = Carbon::parse($progress['completed_at']);
            $this->detail(__('ichava/ichava-core::commands.job_status.completed', [
                'at'  => $completedAt->format('Y-m-d H:i:s'),
                'ago' => $completedAt->diffForHumans(),
            ]));
        }

        if (isset($progress['duration_seconds'])) {
            $this->detail(__('ichava/ichava-core::commands.job_status.duration', [
                'duration' => TimeFormat::duration((float) $progress['duration_seconds']),
            ]));
        }

        if (isset($progress['error'])) {
            $this->newLine();
            $this->failure($progress['error']);
            if (isset($progress['exception'])) {
                $this->detail(__('ichava/ichava-core::commands.job_status.exception', ['class' => $progress['exception']]));
            }
        }

        $this->newLine();
        $dbCount = $this->countDatabaseIcons($packageName);
        info(__('ichava/ichava-core::commands.job_status.in_database', ['count' => $this->formatNumber($dbCount)]));
    }

    /**
     * Clear progress for a package
     */
    protected function clearProgress(string $packageName): int
    {
        if (! $this->confirmDestructive(
            __('ichava/ichava-core::commands.job_status.clear.confirm', ['package' => $packageName]),
            __('ichava/ichava-core::commands.job_status.clear.hint'),
        )) {
            return $this->cancelled(__('ichava/ichava-core::commands.common.cancelled'));
        }

        spin(
            callback: fn () => app(SeederRunTracker::class)->clear(IchavaSeeder::trackingKey($packageName)),
            message: __('ichava/ichava-core::commands.job_status.clear.clearing'),
        );

        outro(__('ichava/ichava-core::commands.job_status.clear.done', ['package' => $packageName]));

        return self::SUCCESS;
    }

    /**
     * Count icons in database for a package
     */
    protected function countDatabaseIcons(string $packageName): int
    {
        return Icon::where('package', $packageName)->count();
    }
}
