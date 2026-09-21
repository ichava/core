<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;

/*
|--------------------------------------------------------------------------
| Icon search runs on the configured driver, not only on PostgreSQL
|--------------------------------------------------------------------------
|
| `executeSearchQuery()` hand-wrote raw full-text SQL --
| `to_tsvector(…) @@ plainto_tsquery(…)` -- with no driver branch, so the
| method threw on SQLite, MySQL and MariaDB whenever the icons table existed.
| On SQLite: `unrecognized token: "@"`.
|
| `Icon::scopeSearch()` exists to make exactly that decision, and its own
| docblock records the previous time the estate got this wrong. The service
| bypassed the model and built the query itself, so the model learned the
| lesson and the service did not.
|
| The four-driver CI matrix was green throughout, because no test called this
| method with the table present. A matrix tests the drivers, not the methods
| nobody exercises.
|
*/

it('does not throw on the default driver', function () {
    expect(fn () => app(IconDiscoveryService::class)->searchIcons('home'))
        ->not->toThrow(QueryException::class);
});

it('emits no PostgreSQL-only syntax on a non-PostgreSQL driver', function () {
    // Asserting on the generated SQL rather than only on the absence of a
    // throw: a future driver that happens to tolerate the syntax would make
    // the test above pass while the query still means nothing there.
    $sql = Icon::query()->search('home')->toSql();

    expect($sql)->not->toContain('to_tsvector')
        ->and($sql)->not->toContain('plainto_tsquery');
})->skip(
    fn (): bool => DB::connection()->getDriverName() === 'pgsql',
    'PostgreSQL is the driver this syntax is correct on.',
);

it('finds an icon it should find', function () {
    // The repair must keep the method working, not merely stop it throwing.
    Icon::query()->create([
        'package'   => 'ichava/test-icons',
        'name'      => 'home',
        'path'      => 'ichava/test-icons::home',
        'category'  => 'general',
        'file_path' => 'home.svg',
    ]);

    $results = app(IconDiscoveryService::class)->searchIcons('home');

    expect($results['total'] ?? 0)->toBeGreaterThan(0);
});
