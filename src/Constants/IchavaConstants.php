<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Constants;

/**
 * IchavaConstants - Ecosystem-Wide Constants
 *
 * Centralised repository of numeric and string constants used across the
 * Ichava ecosystem. Grouping them here prevents magic numbers from being
 * scattered through services, drivers, and commands.
 *
 * Constant groups:
 * - Cache configuration (TTL values for dev and production)
 * - Path validation limits (max length, depth, segment size)
 * - File size limits (min/max SVG byte sizes)
 * - Queue configuration (timeout, retries, stagger delay, progress TTL)
 * - Logging configuration (retention days, performance threshold)
 * - Browser UI pagination (per-page limits)
 * - Rate limiting (browser, API, cache operation limits)
 * - Database sync interval
 */
final class IchavaConstants
{
    /**
     * Default cache TTL (Time To Live) in seconds
     *
     * Used for caching icon discovery results, rendered SVGs, and configuration.
     *
     * @var int 3600 seconds = 1 hour
     */
    public const DEFAULT_CACHE_TTL = 3600;

    /**
     * Cache TTL for production environments (24 hours)
     *
     * Longer cache duration for production to reduce filesystem I/O
     *
     * @var int 86400 seconds = 24 hours
     */
    public const PRODUCTION_CACHE_TTL = 86400;

    /**
     * Maximum icon path length
     *
     * Prevents DoS attacks via excessively long paths.
     * Based on filesystem path length limits across major operating systems.
     *
     * @var int 255 characters (Linux: 4096, Windows: 260, macOS: 1024 - using conservative limit)
     */
    public const MAX_PATH_LENGTH = 255;

    /**
     * Maximum icon name length
     *
     * Reasonable limit for icon filenames to prevent storage issues.
     *
     * @var int 100 characters
     */
    public const MAX_ICON_NAME_LENGTH = 100;

    /**
     * Maximum path segment length
     *
     * Individual segments in icon path (variant/category/etc.)
     *
     * @var int 50 characters per segment
     */
    public const MAX_PATH_SEGMENT_LENGTH = 50;

    /**
     * Maximum nesting depth for icon paths
     *
     * Prevents DoS attacks via deeply nested paths.
     * Example: vendor/package::variant/category/sub1/sub2/.../icon.svg
     *
     * @var int 10 levels deep
     */
    public const MAX_NESTING_DEPTH = 10;

    /**
     * Maximum SVG file size in bytes
     *
     * Prevents loading excessively large SVG files that could impact performance.
     * Most icon SVGs are < 10KB, this allows for complex illustrations.
     *
     * @var int 1048576 bytes = 1 MB
     */
    public const MAX_SVG_FILE_SIZE = 1048576;

    /**
     * Minimum SVG file size in bytes
     *
     * Files smaller than this are considered invalid/corrupt.
     *
     * @var int 10 bytes (minimum valid SVG: <svg></svg>)
     */
    public const MIN_SVG_FILE_SIZE = 10;

    /**
     * Default SVG assets path relative to package root.
     */
    public const SVG_ASSETS_PATH = 'resources/assets/svg';

    /**
     * Default levels to traverse up from ServiceProvider file to package root.
     *
     * Standard Laravel package structure:
     *   - src/Providers/ServiceProvider.php (3 levels up to root)
     *   - Level 1: Providers/
     *   - Level 2: src/
     *   - Level 3: package-root/
     *
     * @var int 3 directory levels
     */
    public const PROVIDER_LEVELS_UP = 3;

    /**
     * Default queue timeout in seconds
     *
     * Maximum time allowed for icon seeding jobs before timeout.
     *
     * @var int 600 seconds = 10 minutes
     */
    public const QUEUE_TIMEOUT = 600;

    /**
     * Default queue retry attempts
     *
     * Number of times to retry failed icon seeding jobs.
     *
     * @var int 3 attempts
     */
    public const QUEUE_RETRIES = 3;

    /**
     * Queue job stagger delay in seconds
     *
     * Delay between dispatching multiple jobs to prevent overwhelming queue worker.
     *
     * @var int 2 seconds
     */
    public const QUEUE_STAGGER_DELAY = 2;

    /**
     * Progress tracking TTL in seconds
     *
     * How long to keep job progress data in cache before cleanup.
     *
     * @var int 86400 seconds = 24 hours
     */
    public const PROGRESS_TTL = 86400;

    /**
     * Default log retention in days
     *
     * Logs older than this are automatically deleted during cleanup.
     *
     * @var int 7 days
     */
    public const LOG_RETENTION_DAYS = 7;

    /**
     * Performance logging threshold in milliseconds
     *
     * Only log operations exceeding this duration to reduce log noise.
     *
     * @var int 100 milliseconds
     */
    public const PERFORMANCE_THRESHOLD_MS = 100;

    /**
     * Default icons per page in browser UI
     *
     * Balances performance with usability for icon grid display.
     *
     * @var int 60 icons per page
     */
    public const BROWSER_PER_PAGE = 60;

    /**
     * Maximum icons per page (hard limit)
     *
     * Prevents performance issues from loading too many icons at once.
     *
     * @var int 200 icons per page
     */
    public const BROWSER_MAX_PER_PAGE = 200;

    /**
     * Browser route rate limit (requests per minute)
     *
     * @var int 300 requests/min
     */
    public const RATE_LIMIT_BROWSER = 300;

    /**
     * API route rate limit (requests per minute)
     *
     * @var int 600 requests/min
     */
    public const RATE_LIMIT_API = 600;

    /**
     * Cache operation rate limit (requests per minute)
     *
     * Lower limit for cache-heavy operations.
     *
     * @var int 10 requests/min
     */
    public const RATE_LIMIT_CACHE = 10;

    /**
     * Database auto-sync interval in seconds
     *
     * How often to check for icon file changes and sync to database.
     *
     * @var int 60 seconds = 1 minute
     */
    public const DB_SYNC_INTERVAL = 60;

    /**
     * Get cache TTL based on environment
     *
     * Returns longer TTL for production, shorter for development.
     *
     * @param string|null $environment Laravel environment name
     *
     * @return int Cache TTL in seconds
     */
    public static function getCacheTTL(?string $environment = null): int
    {
        $env = $environment ?? app()->environment();

        return $env === 'production'
            ? self::PRODUCTION_CACHE_TTL
            : self::DEFAULT_CACHE_TTL;
    }

    /**
     * Get SVG file size limits as array
     *
     * @return array{min: int, max: int}
     */
    public static function getSvgSizeLimits(): array
    {
        return [
            'min' => self::MIN_SVG_FILE_SIZE,
            'max' => self::MAX_SVG_FILE_SIZE,
        ];
    }

    /**
     * Get path validation limits as array
     *
     * @return array{max_length: int, max_depth: int, max_segment: int}
     */
    public static function getPathLimits(): array
    {
        return [
            'max_length'  => self::MAX_PATH_LENGTH,
            'max_depth'   => self::MAX_NESTING_DEPTH,
            'max_segment' => self::MAX_PATH_SEGMENT_LENGTH,
        ];
    }
}
