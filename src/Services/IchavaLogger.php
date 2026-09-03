<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Throwable;
use Illuminate\Support\Facades\Log;
use Simtabi\Laranail\Ichava\Support\PerformanceTimer;
use Simtabi\Laranail\Ichava\Providers\IchavaServiceProvider;

/**
 * Centralized logger that writes to the dedicated `ichava`, `ichava-icons`,
 * and `ichava-queue` daily-rotation channels (registered by the service
 * provider at boot). Adds structured context, security and performance
 * helpers, and seeding-progress tracking.
 *
 * @see IchavaServiceProvider::registerLogChannels()
 */
final class IchavaLogger
{
    private string $channel;

    private bool $enabled;

    private bool $logPerformance;

    private bool $logSecurity;

    private string $seedingChannel;

    public function __construct()
    {
        // Channels are registered dynamically by IchavaServiceProvider
        // Defaults match the channel names registered there
        $this->channel = config('ichava.core.logging.channel', 'ichava');
        $this->enabled = config('ichava.core.logging.enabled', true);
        $this->logPerformance = config('ichava.core.logging.performance', false);
        $this->logSecurity = config('ichava.core.logging.security', true);
        $this->seedingChannel = config('ichava.core.logging.seeding_channel', 'ichava-icons');
    }

    /**
     * Log an informational message to the main ichava channel.
     *
     * Silently no-ops if logging is disabled via ichava.logging.enabled.
     *
     * @param string $message Human-readable message
     * @param array<string, mixed> $context Additional structured context
     */
    public function info(string $message, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->channel)->info($message, $this->enrichContext($context));
    }

    /**
     * Log a warning message to the main ichava channel.
     *
     * Use for degraded-but-recoverable conditions (e.g. missing optional config,
     * icon fallback triggered, slow cache operation).
     *
     * @param string $message Human-readable warning
     * @param array<string, mixed> $context Additional structured context
     */
    public function warning(string $message, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->channel)->warning($message, $this->enrichContext($context));
    }

    /**
     * Log an error, optionally with full exception details.
     *
     * When an exception is provided, its class, message, file, line, and stack
     * trace are automatically added to the log context.
     *
     * @param string $message Human-readable error description
     * @param Throwable|null $exception Exception to capture (optional)
     * @param array<string, mixed> $context Additional structured context
     */
    public function error(string $message, ?Throwable $exception = null, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $enrichedContext = $this->enrichContext($context);

        if ($exception) {
            $enrichedContext = array_merge($enrichedContext, [
                'exception' => get_class($exception),
                'message'   => $exception->getMessage(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => $exception->getTraceAsString(),
            ]);
        }

        Log::channel($this->channel)->error($message, $enrichedContext);
    }

    /**
     * Log a debug message to the main ichava channel.
     *
     * Only written when the channel log level permits debug output.
     * No-ops silently if logging is disabled.
     *
     * @param string $message Debug message
     * @param array<string, mixed> $context Additional structured context
     */
    public function debug(string $message, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->channel)->debug($message, $this->enrichContext($context));
    }

    /**
     * Log a security event with automatic request context enrichment.
     *
     * Prepends [SECURITY] to the message and adds user_id, client IP, and
     * User-Agent to the context automatically. Gated by ichava.logging.security.
     *
     * @param string $message Description of the security event
     * @param array<string, mixed> $context Additional structured context
     */
    public function security(string $message, array $context = []): void
    {
        if (! $this->enabled || ! $this->logSecurity) {
            return;
        }

        Log::channel($this->channel)->warning("[SECURITY] {$message}", array_merge(
            $this->enrichContext($context),
            [
                'user_id'    => auth()->id(),
                'ip'         => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        ));
    }

    /**
     * Log a performance metric for an operation.
     *
     * Prepends [PERFORMANCE] to the operation name. Gated by ichava.logging.performance
     * (disabled by default to avoid noisy logs in production).
     *
     * @param string $operation Human-readable operation name (e.g. 'Icon discovery')
     * @param array<string, mixed> $metrics Timing/count metrics (e.g. ['duration_ms' => 42])
     */
    public function performance(string $operation, array $metrics = []): void
    {
        if (! $this->enabled || ! $this->logPerformance) {
            return;
        }

        Log::channel($this->channel)->debug("[PERFORMANCE] {$operation}", $this->enrichContext($metrics));
    }

    /**
     * Log a cache hit/miss/flush operation.
     *
     * Prepends [CACHE] to the operation name. Like performance(), this is gated
     * by ichava.logging.performance to keep production logs clean.
     *
     * @param string $operation Description of the cache event (e.g. 'hit: outline/home')
     * @param array<string, mixed> $context Additional context (e.g. ['key' => '...', 'ttl' => 300])
     */
    public function cache(string $operation, array $context = []): void
    {
        if (! $this->enabled || ! $this->logPerformance) {
            return;
        }

        Log::channel($this->channel)->debug("[CACHE] {$operation}", $this->enrichContext($context));
    }

    /**
     * Log an SVG sanitization event (dangerous content removed).
     *
     * Silently skipped when $removed is empty (clean SVG, nothing to report).
     * Gated by ichava.logging.security. Written as a WARNING because removal
     * of unexpected content may indicate a supply-chain or content-injection issue.
     *
     * @param string $iconName Path of the icon that was sanitized
     * @param array<string, mixed> $removed Map of removed element/attribute names
     */
    public function sanitization(string $iconName, array $removed = []): void
    {
        if (! $this->enabled || ! $this->logSecurity || empty($removed)) {
            return;
        }

        Log::channel($this->channel)->warning('[SANITIZATION] Dangerous content removed', [
            'icon'      => $iconName,
            'removed'   => $removed,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log an informational seeding message to the ichava-icons channel.
     *
     * Use this (not info()) for any output produced during icon database seeding
     * so that seeding output stays in its own daily log file.
     *
     * @param string $message Seeding progress or status message
     * @param array<string, mixed> $context Additional structured context
     */
    public function seedingInfo(string $message, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->seedingChannel)->info($message, $this->enrichContext($context));
    }

    /**
     * Log a seeding error to the ichava-icons channel.
     *
     * @param string $message Error description
     * @param array<string, mixed> $context Additional structured context
     */
    public function seedingError(string $message, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->seedingChannel)->error($message, $this->enrichContext($context));
    }

    /**
     * Log a seeding warning to the ichava-icons channel.
     *
     * @param string $message Warning description
     * @param array<string, mixed> $context Additional structured context
     */
    public function seedingWarning(string $message, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->seedingChannel)->warning($message, $this->enrichContext($context));
    }

    /**
     * Log seeding progress with an automatically calculated completion percentage.
     *
     * Written to the ichava-icons channel. Percentage is calculated as
     * round(($processed / $total) * 100, 2) and included in the log context.
     *
     * @param string $packageName Name of the icon package being seeded
     * @param int $processed Number of icons processed so far
     * @param int $total Total icon count for this package
     * @param array<string, mixed> $metrics Optional extra metrics (e.g. elapsed time)
     */
    public function seedingProgress(string $packageName, int $processed, int $total, array $metrics = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $progress = $total > 0 ? round(($processed / $total) * 100, 2) : 0;

        Log::channel($this->seedingChannel)->info("Seeding progress: {$packageName}", array_merge(
            $this->enrichContext($metrics),
            [
                'package'          => $packageName,
                'processed'        => $processed,
                'total'            => $total,
                'progress_percent' => $progress,
            ],
        ));
    }

    /**
     * Log successful seeding completion for a package.
     *
     * @param string $packageName Name of the icon package that was seeded
     * @param array<string, mixed> $stats Final stats (e.g. ['inserted' => 500, 'skipped' => 12])
     */
    public function seedingCompleted(string $packageName, array $stats = []): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->seedingChannel)->info("Seeding completed: {$packageName}", array_merge(
            $this->enrichContext($stats),
            ['package' => $packageName],
        ));
    }

    /**
     * Log a queue job dispatch to the ichava-icons channel.
     *
     * @param string $jobClass Fully-qualified job class name
     * @param array<string, mixed> $context Job payload or metadata
     */
    public function jobDispatched(string $jobClass, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->seedingChannel)->info("Job dispatched: {$jobClass}", array_merge(
            $this->enrichContext($context),
            ['job' => $jobClass],
        ));
    }

    /**
     * Log a failed queue job with full exception details to the ichava-icons channel.
     *
     * Captures exception class, message, file, and line automatically.
     *
     * @param string $jobClass Fully-qualified job class name
     * @param Throwable $exception The exception that caused the failure
     * @param array<string, mixed> $context Additional context (e.g. job payload)
     */
    public function jobFailed(string $jobClass, Throwable $exception, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        Log::channel($this->seedingChannel)->error("Job failed: {$jobClass}", array_merge(
            $this->enrichContext($context),
            [
                'job'       => $jobClass,
                'exception' => get_class($exception),
                'message'   => $exception->getMessage(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
            ],
        ));
    }

    /**
     * Create a performance timer for automatic duration tracking
     *
     * @example
     * ```php
     * $timer = $logger->startTimer();
     * // ... operation ...
     * $timer->stop('Icon discovery', ['icons_found' => 150]);
     * ```
     */
    public function startTimer(): PerformanceTimer
    {
        return new PerformanceTimer($this);
    }

    /**
     * Whether logging is globally enabled (ichava.logging.enabled).
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether performance logging is enabled (ichava.logging.performance).
     */
    public function isPerformanceEnabled(): bool
    {
        return $this->logPerformance;
    }

    /**
     * Whether security event logging is enabled (ichava.logging.security).
     */
    public function isSecurityEnabled(): bool
    {
        return $this->logSecurity;
    }

    /**
     * The name of the main log channel (default: 'ichava').
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * The name of the icon-seeding log channel (default: 'ichava-icons').
     */
    public function getSeedingChannel(): string
    {
        return $this->seedingChannel;
    }

    /**
     * Enrich a context array with a standard ISO-8601 timestamp.
     *
     * All public logging methods run their context through this before writing,
     * ensuring every log entry has a consistent `timestamp` field.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function enrichContext(array $context): array
    {
        return array_merge($context, [
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
