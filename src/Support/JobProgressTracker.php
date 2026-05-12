<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed progress counters for long-running icon-seeding jobs.
 * Read by `php artisan ichava:job-status` and the browser UI.
 */
class JobProgressTracker
{
    protected const CACHE_PREFIX = 'ichava:job:progress:';

    protected const CACHE_TTL = 86400; // 24 hours

    /**
     * Start tracking a seeding job
     */
    public static function start(string $packageName, int $totalIcons, ?string $jobId = null): void
    {
        $data = [
            'job_id' => $jobId ?? uniqid('job_', true),
            'package' => $packageName,
            'status' => 'processing',
            'total' => $totalIcons,
            'processed' => 0,
            'progress_percent' => 0,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        Cache::put(
            self::cacheKey($packageName),
            $data,
            now()->addSeconds(self::CACHE_TTL)
        );

        app('ichava.logger')->seedingInfo("Job started: {$packageName}", $data);
    }

    /**
     * Update job progress.
     *
     * Concurrency: parallel workers can race on the same key. We acquire a
     * short-lived cache lock around the read-modify-write and refuse to
     * regress `processed` so a slow worker arriving with a stale lower count
     * does not overwrite a newer higher count (lost-update protection).
     */
    public static function update(string $packageName, int $processed, array $metrics = []): void
    {
        $key = self::cacheKey($packageName);
        $lock = Cache::lock("{$key}:write", 3);

        if (! $lock->get()) {
            // Another worker is writing; this update will be subsumed by the
            // next one. Progress UI tolerates a brief stutter.
            return;
        }

        try {
            $data = Cache::get($key);

            if (! $data) {
                return;
            }

            // Lost-update protection: never regress the counter.
            if ($processed < ($data['processed'] ?? 0)) {
                return;
            }

            $total = $data['total'] ?? 1;
            $progress = $total > 0 ? round(($processed / $total) * 100, 2) : 0;

            $data = array_merge($data, [
                'processed' => $processed,
                'progress_percent' => $progress,
                'updated_at' => now()->toIso8601String(),
            ], $metrics);

            Cache::put($key, $data, now()->addSeconds(self::CACHE_TTL));

            // Log progress every 25%
            if ($progress > 0 && ($progress % 25) < 0.1) {
                app('ichava.logger')->seedingProgress($packageName, $processed, $total, $metrics);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Mark job as completed
     */
    public static function complete(string $packageName, array $stats = []): void
    {
        $data = Cache::get(self::cacheKey($packageName));

        if (! $data) {
            return;
        }

        $startTime = Carbon::parse($data['started_at']);
        $duration = now()->diffInSeconds($startTime);

        $data = array_merge($data, [
            'status' => 'completed',
            'progress_percent' => 100,
            'completed_at' => now()->toIso8601String(),
            'duration_seconds' => $duration,
        ], $stats);

        Cache::put(
            self::cacheKey($packageName),
            $data,
            now()->addDays(7) // Keep completed jobs for 7 days
        );

        app('ichava.logger')->seedingCompleted($packageName, $data);
    }

    /**
     * Mark job as failed
     */
    public static function fail(string $packageName, \Throwable $exception): void
    {
        $data = Cache::get(self::cacheKey($packageName));

        if (! $data) {
            $data = [
                'package' => $packageName,
                'started_at' => now()->toIso8601String(),
            ];
        }

        $data = array_merge($data, [
            'status' => 'failed',
            'failed_at' => now()->toIso8601String(),
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
        ]);

        Cache::put(
            self::cacheKey($packageName),
            $data,
            now()->addDays(7)
        );

        app('ichava.logger')->seedingError("Job failed: {$packageName}", $data);
    }

    /**
     * Get job progress
     */
    public static function get(string $packageName): ?array
    {
        return Cache::get(self::cacheKey($packageName));
    }

    /**
     * Get all active jobs
     */
    public static function getAll(): array
    {
        // This would require a more sophisticated cache key tracking system
        // For now, return empty array - can be enhanced later
        return [];
    }

    /**
     * Clear job progress
     */
    public static function clear(string $packageName): void
    {
        Cache::forget(self::cacheKey($packageName));
    }

    /**
     * Get cache key for package
     */
    protected static function cacheKey(string $packageName): string
    {
        return self::CACHE_PREFIX.str_replace('/', ':', $packageName);
    }
}
