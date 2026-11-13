<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;

/**
 * Ichava's tables: existence, migration, truncation, unseeding and statistics.
 *
 * Bound to DatabaseOperationsService. Depend on this, not the class, so a test
 * or a host can substitute it without subclassing (C-06).
 */
interface DatabaseOperations
{
    /** @phpstan-impure reads the database */
    public function tablesExist(): bool;

    /**
     * @phpstan-impure reads the database
     *
     * @return list<string>
     */
    public function getMissingTables(): array;

    /** @return list<string> */
    public function dropTables(): array;

    public function runMigrations(): int;

    /** @return array{dropped_tables: list<string>, forgotten: list<string>, migration_exit_code: int, success: bool} */
    public function freshMigration(): array;

    /** @return list<string> */
    public function truncateTables(): array;

    /** @return array<string, mixed> */
    public function unseedPackage(string $packageName): array;

    /** @return array<string, mixed> */
    public function unseedAll(): array;

    public function cleanupOrphanedTerms(): int;

    public function shouldUseQueue(int $iconCount, bool $forceSync = false): bool;

    /** @return array<string, mixed> */
    public function getStatistics(): array;

    /** @return array<array-key, mixed> package name => icon count */
    public function getPackageStatistics(): array;

    /** @phpstan-impure reads the database */
    public function hasSeeds(): bool;
}
