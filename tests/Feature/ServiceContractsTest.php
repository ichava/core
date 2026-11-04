<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Ichava\Contracts\ChecksPackUpdates;
use Simtabi\Laranail\Ichava\Contracts\DatabaseOperations;
use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;

/*
 * C-06. The two I/O services that already had a second implementation (the
 * test doubles that subclassed them) sit behind contracts. The contracts
 * resolve the concrete singletons, and a host or test substitutes the
 * contract without subclassing.
 */

it('binds each contract to the concrete singleton', function (): void {
    expect(app(ChecksPackUpdates::class))->toBe(app(IconPackUpdateChecker::class))
        ->and(app(DatabaseOperations::class))->toBe(app(DatabaseOperationsService::class));
});

it('lets the update command run on any ChecksPackUpdates, no subclass needed', function (): void {
    $fake = new class implements ChecksPackUpdates
    {
        public int $calls = 0;

        public function checkAll(?string $packageFilter = null): array
        {
            $this->calls++;

            return [];
        }

        public function checkPackage(string $packageName): array
        {
            return [];
        }

        public function checkOne(string $packageName): array
        {
            return [];
        }
    };
    app()->instance(ChecksPackUpdates::class, $fake);

    Artisan::call('ichava::ichava-core.check-updates', ['--format' => 'json']);

    expect($fake->calls)->toBe(1);
});
