<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;

/*
|--------------------------------------------------------------------------
| Foreign-key enforcement is toggled with each driver's own statement
|--------------------------------------------------------------------------
|
| `dropTables()` and `truncateTables()` suppress foreign keys around a bulk
| operation. That used to be a two-way branch -- pgsql, or everything else --
| which handed SQLite a MySQL statement:
|
|     SQLSTATE[HY000]: General error: 1 near "SET": syntax error
|     (SQL: SET FOREIGN_KEY_CHECKS=0)
|
| So `dropTables()`, `freshMigration()` and `truncateTables()` all threw on
| SQLite, which is this suite's default driver and a documented supported one.
|
| Nothing caught it because none of the three had a test. The CI matrix covers
| pgsql, mysql and mariadb -- every driver for which the `else` branch happened
| to be correct -- and the SQLite lane never called them.
|
| The first two cases below run against whatever driver DB_CONNECTION names, so
| they cover all four in CI. The third asserts the statement chosen per driver
| without needing that driver present, which is the only way one machine can
| check all four.
|
*/

it('truncates without a driver error', function () {
    // The regression. On SQLite this threw before the fix.
    app(DatabaseOperationsService::class)->truncateTables();
})->throwsNoExceptions();

it('drops without a driver error', function () {
    app(DatabaseOperationsService::class)->dropTables();
})->throwsNoExceptions();

it('leaves foreign keys enforced again afterwards', function () {
    // The toggle is only safe if the `finally` really restores it. On SQLite
    // that is observable directly.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('PRAGMA readback is SQLite-specific.');
    }

    app(DatabaseOperationsService::class)->truncateTables();

    expect((int) DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1);
});

it('chooses the right statement for every supported driver', function (string $driver, bool $enabled, string $expected) {
    // Covers the three drivers this machine cannot run, by asserting on the
    // statement rather than its effect. A two-way branch fails `sqlite` here.
    $service = (new ReflectionClass(DatabaseOperationsService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($service, 'setForeignKeyChecks');

    $captured = [];
    DB::shouldReceive('connection->getDriverName')->andReturn($driver);
    DB::shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$captured): bool {
        $captured[] = $sql;

        return true;
    });

    $method->invoke($service, $enabled);

    expect($captured)->toBe([$expected]);
})->with([
    ['pgsql',   false, 'SET session_replication_role = replica'],
    ['pgsql',   true,  'SET session_replication_role = DEFAULT'],
    ['mysql',   false, 'SET FOREIGN_KEY_CHECKS=0'],
    ['mysql',   true,  'SET FOREIGN_KEY_CHECKS=1'],
    ['mariadb', false, 'SET FOREIGN_KEY_CHECKS=0'],
    ['mariadb', true,  'SET FOREIGN_KEY_CHECKS=1'],
    ['sqlite',  false, 'PRAGMA foreign_keys = OFF'],
    ['sqlite',  true,  'PRAGMA foreign_keys = ON'],
]);
