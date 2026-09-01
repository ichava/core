<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

/**
 * PerformanceTimer - Simple performance timer helper
 *
 * Tracks operation duration and automatically logs to IchavaLogger.
 * Useful for profiling icon discovery, rendering, and other operations.
 *
 * **Usage:**
 * ```php
 * $timer = app(IchavaLogger::class)->startTimer();
 * // ... perform operation ...
 * $durationMs = $timer->stop('Icon discovery', ['icons_found' => 150]);
 * ```
 *
 * @api
 */
final class PerformanceTimer
{
    /**
     * Timer start time in microseconds
     */
    private float $startTime;

    /**
     * Create a new performance timer
     *
     * @param  IchavaLogger  $logger  Logger instance for recording metrics
     */
    public function __construct(private IchavaLogger $logger)
    {
        $this->startTime = microtime(true);
    }

    /**
     * Stop timer and log performance
     *
     * Calculates elapsed time and logs to the performance channel.
     *
     * @param  string  $operation  Operation name being timed
     * @param  array<string, mixed>  $context  Additional context data
     * @return float Duration in milliseconds
     */
    public function stop(string $operation, array $context = []): float
    {
        $durationMs = (microtime(true) - $this->startTime) * 1000;

        $this->logger->performance($operation, array_merge($context, [
            'duration_ms' => round($durationMs, 2),
        ]));

        return $durationMs;
    }

    /**
     * Get elapsed time without stopping
     *
     * @return float Duration in milliseconds
     */
    public function elapsed(): float
    {
        return (microtime(true) - $this->startTime) * 1000;
    }
}
