<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Support\Helpers;

/**
 * ConfigurationService - Package Configuration Loader
 *
 * Handles loading and validation of package-specific config.json files.
 * For global config/ichava.php values, use config() helper directly.
 *
 * This service is intentionally kept minimal to avoid becoming a "god object".
 * It only handles package-specific configuration files, not global Laravel config.
 */
final class ConfigurationService
{
    private const CONFIG_FILENAME = 'config.json';

    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Constructor with dependency injection
     */
    public function __construct(
        private IconCacheService $cache,
    ) {}

    /**
     * Load and validate package config.json from a directory.
     *
     * @return array<string, mixed>
     *
     * @throws IchavaException
     */
    public function loadPackageConfig(string $directoryPath): array
    {
        $configPath = $this->resolveConfigPath($directoryPath);

        if (! File::exists($configPath)) {
            throw IchavaException::missingConfigFile($configPath);
        }

        $json = File::get($configPath);
        $config = $this->parseJson($json, $configPath);
        $this->validatePackageConfig($config, $configPath);

        return $config;
    }

    /**
     * Load package config with caching
     */
    public function rememberPackageConfig(string $directoryPath): array
    {
        $cacheKey = $this->getCacheKey($directoryPath);

        return $this->cache->rememberConfig($cacheKey, function () use ($directoryPath) {
            return $this->loadPackageConfig($directoryPath);
        });
    }

    /**
     * Clear cached package config
     */
    public function forgetPackageConfig(string $directoryPath): bool
    {
        $cacheKey = $this->getCacheKey($directoryPath);

        return $this->cache->forgetConfig($cacheKey);
    }

    /**
     * Check if config has variants
     */
    public function hasVariants(array $config): bool
    {
        return isset($config['variants']) && is_array($config['variants']) && ! empty($config['variants']);
    }

    /**
     * Get variants from config
     */
    public function getVariants(array $config): array
    {
        return $config['variants'] ?? [];
    }

    /**
     * Check if config has categories
     */
    public function hasCategories(array $config): bool
    {
        return isset($config['categories']) && is_array($config['categories']) && ! empty($config['categories']);
    }

    /**
     * Get vendor from config
     */
    public function getVendor(array $config): string
    {
        $packageName = $config['package']['name'] ?? '';

        return Helpers::getVendorFromPackage($packageName);
    }

    /**
     * Validate required package config fields
     */
    private function validatePackageConfig(array $config, string $configPath): void
    {
        if (empty($config['package']['name'])) {
            throw IchavaException::invalidConfig(
                'Missing required field: package.name',
                $configPath,
            );
        }

        if (! preg_match('/^[a-z0-9_-]+\/[a-z0-9_-]+$/i', $config['package']['name'])) {
            throw IchavaException::invalidConfig(
                'Invalid package.name format. Expected: vendor/package',
                $configPath,
            );
        }

        if (empty($config['config']['icon_prefix'])) {
            throw IchavaException::invalidConfig(
                'Missing required field: config.icon_prefix',
                $configPath,
            );
        }
    }

    private function resolveConfigPath(string $directoryPath): string
    {
        return Str::finish($directoryPath, DIRECTORY_SEPARATOR).self::CONFIG_FILENAME;
    }

    /**
     * Parse JSON with error handling
     */
    private function parseJson(string $json, string $configPath): array
    {
        $config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw IchavaException::invalidConfig(
                'JSON parse error: '.json_last_error_msg(),
                $configPath,
            );
        }

        if (! is_array($config)) {
            throw IchavaException::invalidConfig(
                'Config must be a JSON object',
                $configPath,
            );
        }

        return $config;
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(string $directoryPath): string
    {
        return 'config:'.md5($directoryPath);
    }
}
