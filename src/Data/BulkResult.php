<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

use Simtabi\Laranail\Ichava\Services\IconRegistry;

/**
 * What one {@see IconRegistry::registerMany()} run did.
 */
final readonly class BulkResult
{
    /**
     * @param list<string> $registered package names, in order
     * @param list<string> $skipped directories that do not exist
     * @param array<string, string> $failed set (package name or directory) => message
     */
    public function __construct(
        public array $registered,
        public array $skipped,
        public array $failed,
        public float $durationMs,
    ) {}

    /** @return array{registered: int, skipped: int, failed: int} */
    public function counts(): array
    {
        return ['registered' => count($this->registered), 'skipped' => count($this->skipped), 'failed' => count($this->failed)];
    }
}
