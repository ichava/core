<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

use Simtabi\Laranail\Ichava\Services\IconRegistry;

/**
 * How {@see IconRegistry::registerMany()} runs.
 */
final readonly class BulkOptions
{
    /**
     * @param string $name the label the run's `started` and `completed` events and its log line carry
     * @param bool $failFast rethrow the first failure; by default a broken set is reported and skipped
     * @param bool $log log one line with the run's counts
     */
    public function __construct(
        public string $name = 'bulk',
        public bool $failFast = false,
        public bool $log = false,
    ) {}
}
