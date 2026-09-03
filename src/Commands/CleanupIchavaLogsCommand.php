<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use Exception;
use Carbon\Carbon;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

use Illuminate\Support\Facades\File;

use function Laravel\Prompts\warning;
use function Laravel\Prompts\progress;

/**
 * Removes Ichava log files older than the configured retention period.
 * Runs daily via the scheduler or on demand.
 */
class CleanupIchavaLogsCommand extends BaseCommand
{
    protected $signature = 'ichava:cleanup-logs
                            {--days= : Number of days to retain logs (default: from config)}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Force operation without confirmation}';

    protected $description = 'Clean up old Ichava icon seeding log files';

    public function handle(): int
    {
        $retentionDays = $this->getRetentionDays();
        $dryRun = $this->option('dry-run');

        intro("🧹 Cleaning up Ichava logs older than {$retentionDays} days");

        if ($dryRun) {
            warning('DRY RUN MODE - No files will be deleted');
        }

        $logPath = storage_path('logs');
        $cutoffDate = Carbon::now()->subDays($retentionDays);

        if (! File::isDirectory($logPath)) {
            $this->failure("Log directory not found: {$logPath}");

            return self::FAILURE;
        }

        // Find all Ichava log files (ichava-*.log, ichava-icons-*.log, ichava-queue-*.log)
        $logFiles = array_merge(
            File::glob($logPath . '/ichava-*.log'),
            File::glob($logPath . '/ichava-icons-*.log'),
            File::glob($logPath . '/ichava-queue-*.log'),
        );

        // Remove duplicates
        $logFiles = array_unique($logFiles);

        if (empty($logFiles)) {
            $this->success('No Ichava log files found');

            return self::SUCCESS;
        }

        $stats = $this->processLogFiles($logFiles, $cutoffDate, $dryRun);

        $this->displaySummary($stats, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Get retention days from option or config
     */
    protected function getRetentionDays(): int
    {
        $days = $this->option('days');

        if ($days !== null) {
            return (int) $days;
        }

        // Ask user if not provided
        if (! $this->isQuiet()) {
            $configDefault = config('ichava.core.logging.retention_days', 7);

            $days = text(
                label: 'How many days of logs to retain?',
                placeholder: (string) $configDefault,
                default: (string) $configDefault,
                validate: fn (string $value) => ! is_numeric($value) || (int) $value < 1
                    ? 'Please enter a valid number of days (minimum 1)'
                    : null,
                hint: 'Logs older than this will be deleted',
            );

            return (int) $days;
        }

        return (int) config('ichava.core.logging.retention_days', 7);
    }

    /**
     * Process log files and return statistics
     */
    protected function processLogFiles(array $logFiles, Carbon $cutoffDate, bool $dryRun): array
    {
        $stats = [
            'total'   => count($logFiles),
            'deleted' => 0,
            'failed'  => 0,
            'kept'    => 0,
            'files'   => [],
        ];

        // Use progress bar for better UX
        $results = progress(
            label: $dryRun ? 'Analyzing log files...' : 'Processing log files...',
            steps: $logFiles,
            callback: function ($file) use ($cutoffDate, $dryRun, &$stats) {
                $fileName = basename($file);
                $fileTime = filemtime($file);
                $fileDate = Carbon::createFromTimestamp($fileTime);
                $age = $fileDate->diffInDays(now());

                $result = [
                    'file'   => $fileName,
                    'age'    => $age,
                    'action' => 'kept',
                ];

                if ($fileTime < $cutoffDate->timestamp) {
                    if ($dryRun) {
                        $result['action'] = 'would_delete';
                        $stats['deleted']++;
                    } else {
                        try {
                            if (File::delete($file)) {
                                $result['action'] = 'deleted';
                                $stats['deleted']++;
                            } else {
                                $result['action'] = 'failed';
                                $stats['failed']++;
                            }
                        } catch (Exception $e) {
                            $result['action'] = 'failed';
                            $result['error'] = $e->getMessage();
                            $stats['failed']++;
                        }
                    }
                } else {
                    $stats['kept']++;
                }

                $stats['files'][] = $result;

                return $result;
            },
            hint: 'This may take a moment for large log directories',
        );

        return $stats;
    }

    /**
     * Display cleanup summary using Laravel Prompts table
     */
    protected function displaySummary(array $stats, bool $dryRun): void
    {
        // Summary table
        table(
            headers: ['Metric', 'Count'],
            rows: [
                ['Total log files', (string) $stats['total']],
                [$dryRun ? 'Would delete' : 'Deleted', (string) $stats['deleted']],
                ['Kept', (string) $stats['kept']],
                ['Failed', (string) $stats['failed']],
            ],
        );

        // Show verbose details if requested
        if ($this->isVerbose() && ! empty($stats['files'])) {
            $rows = array_map(fn ($file) => [
                $file['file'],
                "{$file['age']} days",
                match ($file['action']) {
                    'deleted'      => '✅ Deleted',
                    'would_delete' => '🔍 Would delete',
                    'failed'       => '❌ Failed',
                    default        => '⏭️ Kept',
                },
            ], $stats['files']);

            table(
                headers: ['File', 'Age', 'Action'],
                rows: $rows,
            );
        }

        // Final message
        if (! $dryRun && $stats['deleted'] > 0) {
            outro("✅ Cleaned up {$stats['deleted']} old log file(s)");
        } elseif ($dryRun && $stats['deleted'] > 0) {
            note("Would delete {$stats['deleted']} file(s). Run without --dry-run to actually delete.");
        } elseif ($stats['deleted'] === 0) {
            info('No old log files to clean up.');
        }

        if ($stats['failed'] > 0) {
            warning("{$stats['failed']} file(s) failed to delete. Check permissions.");
        }
    }
}
