<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;

/**
 * Compares installed packs with their upstream releases (network I/O).
 *
 * Bound to IconPackUpdateChecker. Depend on this, not the class, so a test or
 * a host can substitute it without subclassing (C-06).
 *
 * @phpstan-import-type CheckResult from IconPackUpdateChecker
 */
interface ChecksPackUpdates
{
    /**
     * Every installed pack, or those whose name contains $packageFilter.
     *
     * @return list<CheckResult>
     */
    public function checkAll(?string $packageFilter = null): array;

    /**
     * One result per upstream source the pack declares.
     *
     * @return list<CheckResult>
     */
    public function checkPackage(string $packageName): array;

    /**
     * The pack's primary upstream source.
     *
     * @return CheckResult
     */
    public function checkOne(string $packageName): array;
}
