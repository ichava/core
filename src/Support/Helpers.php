<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * Helpers - Ichava Utility Functions
 *
 * Provides centralized helper methods for:
 * - Configuration loading (config.json files)
 * - Database detection
 * - Language support
 * - Common operations
 */
class Helpers
{
    public const ICHAVA_PGSQL_LANGUAGES = [
        'english' => 'english',
        'simple' => 'simple',
    ];

    public const ICHAVA_PGSQL_DEFAULT_LANGUAGE = self::ICHAVA_PGSQL_LANGUAGES['simple'];

    /**
     * Check if database driver is PostgreSQL
     */
    public static function dbDriverIsPgSql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Load and parse a config.json file
     *
     * Centralized method for loading package configuration files.
     * Uses Laravel File facade and Arr helper for consistency.
     *
     * @param  string  $directoryPath  Path to directory containing config.json
     * @param  bool  $throwOnMissing  Whether to throw exception if file doesn't exist
     * @return array<string, mixed> Parsed configuration array
     *
     * @throws IchavaException If file doesn't exist or JSON is invalid
     */
    public static function loadConfigJson(string $directoryPath, bool $throwOnMissing = true): array
    {
        $configPath = Str::finish($directoryPath, '/').'config.json';

        if (! File::exists($configPath)) {
            if ($throwOnMissing) {
                throw IchavaException::missingConfigFile($configPath);
            }

            return [];
        }

        $json = File::get($configPath);
        $config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw IchavaException::invalidConfig(
                'JSON parse error: '.json_last_error_msg(),
                $configPath
            );
        }

        if (! is_array($config)) {
            throw IchavaException::invalidConfig(
                'Config must be a JSON object',
                $configPath
            );
        }

        return $config;
    }

    /**
     * Get vendor name from package name
     *
     * @param  string  $packageName  Full package name (vendor/package)
     * @return string Vendor name
     */
    public static function getVendorFromPackage(string $packageName): string
    {
        return Str::before($packageName, '/');
    }

    /**
     * Get package name from full package identifier
     *
     * @param  string  $packageName  Full package name (vendor/package)
     * @return string Package name without vendor
     */
    public static function getPackageFromIdentifier(string $packageName): string
    {
        return Str::after($packageName, '/');
    }

    /**
     * Sanitize path by removing leading/trailing slashes
     *
     * @param  string  $path  Path to sanitize
     * @return string Sanitized path
     */
    public static function sanitizePath(string $path): string
    {
        return trim($path, '/\\');
    }

    /**
     * Cache-busting version token for a published asset.
     *
     * Falls back gracefully when the file is not yet published (returns 'dev'
     * instead of emitting a filemtime() warning and a literal `?v=false`).
     */
    public static function assetVersion(string $relativePath): string
    {
        $configured = config('ichava.version');

        if (! empty($configured)) {
            return (string) $configured;
        }

        $absolute = public_path(ltrim($relativePath, '/'));

        return File::isFile($absolute) ? (string) filemtime($absolute) : 'dev';
    }

    /**
     * Resolve an Ichava log file path.
     *
     * Path resolution order:
     *   1. ICHAVA_LOG_PATH env (absolute, or relative to base_path()).
     *   2. storage_path('logs'), Laravel-standard.
     *
     * Used by config/ichava.php for the three rotation channels and by any
     * runtime caller that needs a writable log file at the same location.
     */
    public static function logPath(string $filename = ''): string
    {
        $configured = env('ICHAVA_LOG_PATH');

        if (empty($configured)) {
            $dir = function_exists('storage_path')
                ? storage_path('logs')
                : sys_get_temp_dir();
        } else {
            $isAbsolute = Str::startsWith($configured, DIRECTORY_SEPARATOR)
                || preg_match('/^[a-zA-Z]:[\\\\\/]/', $configured) === 1;

            $dir = $isAbsolute
                ? rtrim($configured, '/\\')
                : (function_exists('base_path')
                    ? base_path(rtrim($configured, '/\\'))
                    : rtrim($configured, '/\\'));
        }

        return $filename === ''
            ? $dir
            : $dir.DIRECTORY_SEPARATOR.ltrim($filename, '/\\');
    }
}
