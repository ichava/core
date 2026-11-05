<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Support\PackConfigCache;
use Simtabi\Laranail\Ichava\Support\PackConfigData;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

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

    /**
     * Constructor with dependency injection
     */
    public function __construct(
        private PackConfigCache $configCache,
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

        // One config.json parser in core: Helpers::loadConfigJson() (C-04).
        $config = Helpers::loadConfigJson($directoryPath);
        $this->validatePackageConfig($config, $configPath);

        return $config;
    }

    /**
     * Load package config with caching
     *
     * @return array<string, mixed>
     */
    public function rememberPackageConfig(string $directoryPath): array
    {
        $config = $this->configCache->remember($directoryPath, fn (): array => $this->loadPackageConfig($directoryPath));

        return is_array($config) ? $this->stringKeyed($config, $this->resolveConfigPath($directoryPath)) : $this->loadPackageConfig($directoryPath);
    }

    /**
     * Clear cached package config
     */
    public function forgetPackageConfig(string $directoryPath): bool
    {
        return $this->configCache->forget($directoryPath);
    }

    /**
     * Check if config has variants
     *
     * Reads `metadata.data.variants`, the one place config.json declares them.
     *
     * @param array<string, mixed> $config
     */
    public function hasVariants(array $config): bool
    {
        return PackConfigData::variants($config) !== [];
    }

    /**
     * Get variants from config
     *
     * Reads `metadata.data.variants`, the one place config.json declares them.
     *
     * @param array<string, mixed> $config
     *
     * @return array<mixed>
     */
    public function getVariants(array $config): array
    {
        return PackConfigData::variants($config);
    }

    /**
     * Check if config has categories
     *
     * Reads `metadata.data.categories`, the one place config.json declares them.
     *
     * @param array<string, mixed> $config
     */
    public function hasCategories(array $config): bool
    {
        return PackConfigData::categories($config) !== [];
    }

    /**
     * Get vendor from config
     *
     * @param array<string, mixed> $config
     */
    public function getVendor(array $config): string
    {
        return Helpers::getVendorFromPackage($this->packageName($config) ?? '');
    }

    /**
     * Validate required package config fields
     *
     * @param array<string, mixed> $config
     */
    private function validatePackageConfig(array $config, string $configPath): void
    {
        $packageName = $this->packageName($config);

        if ($packageName === null || $packageName === '') {
            throw IchavaException::invalidConfig(
                'Missing required field: package.name',
                $configPath,
            );
        }

        if (! preg_match('/^[a-z0-9_-]+\/[a-z0-9_-]+$/i', $packageName)) {
            throw IchavaException::invalidConfig(
                'Invalid package.name format. Expected: vendor/package',
                $configPath,
            );
        }

        $settings = $config['config'] ?? null;

        if (! is_array($settings) || empty($settings['icon_prefix'])) {
            throw IchavaException::invalidConfig(
                'Missing required field: config.icon_prefix',
                $configPath,
            );
        }
    }

    private function resolveConfigPath(string $directoryPath): string
    {
        return Str::finish($directoryPath, DIRECTORY_SEPARATOR) . self::CONFIG_FILENAME;
    }

    /**
     * `package.name` when it is a string, null otherwise.
     *
     * @param array<string, mixed> $config
     */
    private function packageName(array $config): ?string
    {
        $package = $config['package'] ?? null;
        $name = is_array($package) ? ($package['name'] ?? null) : null;

        return is_string($name) ? $name : null;
    }

    /**
     * Re-key a decoded JSON object as the string-keyed map it is.
     *
     * @param array<mixed> $config
     *
     * @return array<string, mixed>
     *
     * @throws IchavaException when a top-level key is not a string
     */
    private function stringKeyed(array $config, string $configPath): array
    {
        $object = [];

        foreach ($config as $key => $value) {
            if (! is_string($key)) {
                throw IchavaException::invalidConfig('Config must be a JSON object', $configPath);
            }

            $object[$key] = $value;
        }

        return $object;
    }
}
