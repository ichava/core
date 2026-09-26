<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Parked\Support\Seeder;

/**
 * Members with no caller, moved here verbatim from `src/Support/Seeder/IchavaSeeder.php` on 2026-09-26.
 *
 * NOT autoloaded and NOT shipped (`/.parked export-ignore`). Kept so a member
 * can be restored from a file rather than from history, if a caller ever
 * appears. See `.parked/README.md` for the measurement behind each entry.
 */
trait IchavaSeederMethods
{
    /**
     * Get seeding job status by batch ID.
     */
    public function getStatus(string $batchId): ?array
    {
        $batch = Bus::findBatch($batchId);

        if (! $batch) {
            return null;
        }

        return [
            'id'             => $batch->id,
            'name'           => $batch->name,
            'progress'       => $batch->progress(),
            'total_jobs'     => $batch->totalJobs,
            'pending_jobs'   => $batch->pendingJobs,
            'processed_jobs' => $batch->processedJobs(),
            'failed_jobs'    => $batch->failedJobs,
            'has_failures'   => $batch->hasFailures(),
            'finished'       => $batch->finished(),
            'cancelled'      => $batch->cancelled(),
            'created_at'     => $batch->createdAt,
            'finished_at'    => $batch->finishedAt,
        ];
    }

    /**
     * Cancel a running seeding operation.
     */
    public function cancel(string $batchId): bool
    {
        $batch = Bus::findBatch($batchId);

        if (! $batch) {
            return false;
        }

        $batch->cancel();
        $this->logger->info('🛑 Seeding cancelled', ['batch_id' => $batchId]);

        return true;
    }

    protected function displayJobInstructions(int $jobCount): void
    {
        $queueName = config('ichava.ichava-core.queue.name', 'ichava-icons');

        $this->command->newLine();
        warning("⏳ {$jobCount} seeding jobs dispatched to queue");
        $this->command->newLine();
        note("Start queue worker: php artisan queue:work --queue={$queueName}");
        note('Or use Horizon: php artisan horizon');
    }
}
