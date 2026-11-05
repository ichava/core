<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Pack;

use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Constants\JsonConfigConstants;
use Simtabi\Laranail\Ichava\Services\ConfigurationService;

/**
 * A set's effective config.json: the registered definition's, overrides
 * included, or the file's while the set has not registered.
 *
 * The reader {@see JsonConfigConstants} goes through, so a pack's constants
 * class sees what PackBuilder registered rather than a second read of the file.
 */
final readonly class PackConfigReader
{
    public function __construct(
        private IconRegistry $registry,
        private ConfigurationService $configs,
    ) {}

    /**
     * The registered definition's config for the set in $directoryPath, or null
     * while nothing has registered from that directory.
     *
     * @return array<string, mixed>|null
     */
    public function registered(string $directoryPath): ?array
    {
        return $this->registry->definitionAt($directoryPath)?->config;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $directoryPath): array
    {
        return $this->registered($directoryPath) ?? $this->configs->rememberPackageConfig($directoryPath);
    }
}
