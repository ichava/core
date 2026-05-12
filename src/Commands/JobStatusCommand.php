<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use Carbon\Carbon;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\JobProgressTracker;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Display icon seeding job status
 *
 * Shows progress of currently running and recently completed icon seeding jobs.
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
class JobStatusCommand extends BaseCommand
{
    protected $signature = 'ichava:job-status
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
     * Display status for a single package
     */
    protected function displaySinglePackage(string $packageName): int
    {
        intro("📊 Job Status: {$packageName}");

        $progress = JobProgressTracker::get($packageName);

        if (! $progress) {
            warning("No progress data found for: {$packageName}");
            note('💡 This package may not have been seeded yet, or progress data has expired.');

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
        intro('📊 Ichava Icon Seeding Job Status');

        $registry = app(IconRegistry::class);
        $packages = $registry->all();

        if (empty($packages)) {
            warning('No icon packages registered.');

            return self::SUCCESS;
        }

        $activeJobs = 0;
        $completedJobs = 0;
        $failedJobs = 0;

        $rows = collect($packages)->map(function ($packageData, $packageName) use (&$activeJobs, &$completedJobs, &$failedJobs) {
            $progress = JobProgressTracker::get($packageName);

            if (! $progress) {
                if ($this->option('all')) {
                    return [
                        $packageName,
                        '<fg=gray>No data</>',
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
                'completed' => $completedJobs++,
                'failed' => $failedJobs++,
                default => null,
            };

            $progressBar = $this->createProgressBar($progressPercent);

            return [
                $packageName,
                $this->formatStatus($status),
                "{$progressBar} {$progressPercent}%",
                "{$processed}/{$total}",
                $updatedAt,
            ];
        })->filter()->values()->toArray();

        if (empty($rows)) {
            warning('No job progress data found.');
            note('💡 Jobs are tracked after running: php artisan ichava:database seed');

            return self::SUCCESS;
        }

        table(
            headers: ['Package', 'Status', 'Progress', 'Icons', 'Updated'],
            rows: $rows
        );

        // Summary
        $this->newLine();
        info('📊 Summary:');

        $totalIcons = spin(
            callback: fn () => Icon::count(),
            message: 'Counting icons...'
        );

        table(
            headers: ['Metric', 'Count'],
            rows: [
                ['Active jobs', (string) $activeJobs],
                ['Completed', (string) $completedJobs],
                ['Failed', (string) $failedJobs],
                ['Total icons in DB', $this->formatNumber($totalIcons)],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Display detailed progress data
     */
    protected function displayProgressData(string $packageName, array $progress): void
    {
        $status = $progress['status'] ?? 'unknown';

        table(
            headers: ['Property', 'Value'],
            rows: [
                ['Package', $packageName],
                ['Status', $this->formatStatus($status)],
                ['Job ID', $progress['job_id'] ?? '-'],
            ]
        );

        $processed = $progress['processed'] ?? 0;
        $total = $progress['total'] ?? 0;
        $progressPercent = $progress['progress_percent'] ?? 0;

        $this->newLine();
        info('Progress:');
        $this->line("  {$this->createProgressBar($progressPercent, 20)} {$progressPercent}%");
        $this->line("  Icons: {$processed} / {$total}");

        if (isset($progress['started_at'])) {
            $startedAt = Carbon::parse($progress['started_at']);
            $this->line("  Started: {$startedAt->format('Y-m-d H:i:s')} ({$startedAt->diffForHumans()})");
        }

        if (isset($progress['completed_at'])) {
            $completedAt = Carbon::parse($progress['completed_at']);
            $this->line("  Completed: {$completedAt->format('Y-m-d H:i:s')} ({$completedAt->diffForHumans()})");
        }

        if (isset($progress['duration_seconds'])) {
            $this->line("  Duration: {$this->formatDuration($progress['duration_seconds'])}");
        }

        if (isset($progress['error'])) {
            $this->newLine();
            $this->failure($progress['error']);
            if (isset($progress['exception'])) {
                $this->line("  Exception: <fg=red>{$progress['exception']}</>");
            }
        }

        $this->newLine();
        $dbCount = $this->countDatabaseIcons($packageName);
        info("Icons in database: {$this->formatNumber($dbCount)}");
    }

    /**
     * Clear progress for a package
     */
    protected function clearProgress(string $packageName): int
    {
        $confirmed = confirm(
            label: "Clear progress data for '{$packageName}'?",
            default: false,
            yes: 'Yes, clear it',
            no: 'No, cancel',
            hint: 'This will remove the cached progress tracking data'
        );

        if (! $confirmed && ! $this->option('force')) {
            warning('Operation cancelled.');

            return self::SUCCESS;
        }

        spin(
            callback: fn () => JobProgressTracker::clear($packageName),
            message: 'Clearing progress...'
        );

        outro("✅ Progress cleared for: {$packageName}");

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
