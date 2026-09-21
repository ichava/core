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
    protected $signature = 'ichava::ichava-core.cleanup-logs
                            {--days= : Number of days to retain logs (default: from config)}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Force operation without confirmation}';

    protected $description = 'Clean up old Ichava icon seeding log files';

    public function handle(): int
    {
        $retentionDays = $this->getRetentionDays();
        $dryRun = $this->option('dry-run');

        intro(__('ichava/ichava-core::commands.cleanup_logs.intro', ['days' => $retentionDays]));

        if ($dryRun) {
            warning(__('ichava/ichava-core::commands.cleanup_logs.dry_run'));
        }

        $logPath = storage_path('logs');
        $cutoffDate = Carbon::now()->subDays($retentionDays);

        if (! File::isDirectory($logPath)) {
            $this->failure(__('ichava/ichava-core::commands.cleanup_logs.dir_missing', ['path' => $logPath]));

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
            $this->success(__('ichava/ichava-core::commands.cleanup_logs.none_found'));

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
            $configDefault = config('ichava.ichava-core.logging.retention_days', 7);

            $days = text(
                label: __('ichava/ichava-core::commands.cleanup_logs.retention_ask'),
                placeholder: (string) $configDefault,
                default: (string) $configDefault,
                validate: fn (string $value) => ! is_numeric($value) || (int) $value < 1
                    ? __('ichava/ichava-core::commands.cleanup_logs.retention_invalid')
                    : null,
                hint: __('ichava/ichava-core::commands.cleanup_logs.retention_hint'),
            );

            return (int) $days;
        }

        return (int) config('ichava.ichava-core.logging.retention_days', 7);
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
            label: $dryRun ? __('ichava/ichava-core::commands.cleanup_logs.analyzing') : __('ichava/ichava-core::commands.cleanup_logs.processing'),
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
            headers: [__('ichava/ichava-core::commands.cleanup_logs.table.metric'), __('ichava/ichava-core::commands.cleanup_logs.table.count')],
            rows: [
                [__('ichava/ichava-core::commands.cleanup_logs.total_files'), (string) $stats['total']],
                [$dryRun ? __('ichava/ichava-core::commands.cleanup_logs.would_delete') : __('ichava/ichava-core::commands.cleanup_logs.deleted'), (string) $stats['deleted']],
                [__('ichava/ichava-core::commands.cleanup_logs.table.kept'), (string) $stats['kept']],
                [__('ichava/ichava-core::commands.cleanup_logs.table.failed'), (string) $stats['failed']],
            ],
        );

        // Show verbose details if requested
        if ($this->isVerbose() && ! empty($stats['files'])) {
            $rows = array_map(fn ($file) => [
                $file['file'],
                __('ichava/ichava-core::commands.cleanup_logs.table.days', ['days' => $file['age']]),
                match ($file['action']) {
                    'deleted'      => __('ichava/ichava-core::commands.cleanup_logs.action.deleted'),
                    'would_delete' => __('ichava/ichava-core::commands.cleanup_logs.action.would_delete'),
                    'failed'       => __('ichava/ichava-core::commands.cleanup_logs.action.failed'),
                    default        => __('ichava/ichava-core::commands.cleanup_logs.action.kept'),
                },
            ], $stats['files']);

            table(
                headers: [__('ichava/ichava-core::commands.cleanup_logs.table.file'), __('ichava/ichava-core::commands.cleanup_logs.table.age'), __('ichava/ichava-core::commands.cleanup_logs.table.action')],
                rows: $rows,
            );
        }

        // Final message
        if (! $dryRun && $stats['deleted'] > 0) {
            outro(__('ichava/ichava-core::commands.cleanup_logs.cleaned_up', ['count' => $stats['deleted']]));
        } elseif ($dryRun && $stats['deleted'] > 0) {
            note(__('ichava/ichava-core::commands.cleanup_logs.would_delete_note', ['count' => $stats['deleted']]));
        } elseif ($stats['deleted'] === 0) {
            info(__('ichava/ichava-core::commands.cleanup_logs.nothing_to_do'));
        }

        if ($stats['failed'] > 0) {
            warning(__('ichava/ichava-core::commands.cleanup_logs.delete_failed', ['count' => $stats['failed']]));
        }
    }
}
