<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Actions\ClearDiscoveryCaches;
use Simtabi\Laranail\Ichava\Actions\SearchIconsInDatabase;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;

/*
|--------------------------------------------------------------------------
| Two concerns leave IconDiscoveryService as actions
|--------------------------------------------------------------------------
|
| Not a wholesale restructuring. These are the two places that actually
| produced defects: the database search path, which hand-wrote PostgreSQL FTS
| with no driver branch and then called a driver method on a model, and cache
| invalidation, which cleared two keys of six and could not reach two of the
| rest because they live in a different store.
|
| Each is now a single-purpose invokable with its own dependencies, testable
| without standing up the 967-line service around it. The service keeps its
| public API and delegates, so nothing downstream changes.
|
*/

beforeEach(function () {
    $this->app['config']->set('cache.default', 'file');
    Cache::store('file')->flush();
});

it('searches the database without the discovery service', function () {
    // The point of the extraction: this needed a 967-line collaborator before.
    Icon::query()->create([
        'package'   => 'ichava/test-icons',
        'name'      => 'anchor',
        'path'      => 'ichava/test-icons::anchor',
        'category'  => 'general',
        'file_path' => 'anchor.svg',
    ]);

    $result = app(SearchIconsInDatabase::class)(query: 'anchor');

    expect($result['total'])->toBe(1)
        ->and($result['items'][0]['name'])->toBe('anchor');
});

it('delegates to the model scope rather than writing its own SQL', function () {
    // F3: the inlined `to_tsvector(...) @@ plainto_tsquery(...)` threw on every
    // driver but PostgreSQL. Asserted on the class, because the whole point is
    // that there is now one query builder to be wrong in.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/Actions/SearchIconsInDatabase.php',
    );

    expect($source)->not->toContain('to_tsvector')
        ->and($source)->not->toContain('plainto_tsquery')
        ->and($source)->toContain('->search(');
});

it('clears every cache the discovery service writes', function () {
    $svc = app(IconDiscoveryService::class);

    expect($svc->searchIcons('anchor')['total'] ?? 0)->toBe(0);

    Icon::query()->create([
        'package'   => 'ichava/test-icons',
        'name'      => 'anchor',
        'path'      => 'ichava/test-icons::anchor',
        'category'  => 'general',
        'file_path' => 'anchor.svg',
    ]);

    expect($svc->searchIcons('anchor')['total'] ?? 0)->toBe(0);

    app(ClearDiscoveryCaches::class)();

    expect($svc->searchIcons('anchor')['total'] ?? 0)->toBe(1);
});

it('clears through the service, which is how callers reach it', function () {
    // The test above invokes the action directly, so it says nothing about the
    // delegation. A mutation proved it: replacing the service's call with a
    // no-op left every assertion green. Callers use clearCache(); that path
    // needs its own coverage.
    $svc = app(IconDiscoveryService::class);

    expect($svc->searchIcons('beacon')['total'] ?? 0)->toBe(0);

    Icon::query()->create([
        'package'   => 'ichava/test-icons',
        'name'      => 'beacon',
        'path'      => 'ichava/test-icons::beacon',
        'category'  => 'general',
        'file_path' => 'beacon.svg',
    ]);

    $svc->clearCache();

    expect($svc->searchIcons('beacon')['total'] ?? 0)->toBe(1);
});

it('keeps the service delegating, so callers are unaffected', function () {
    // The service's public API is the contract. An extraction that changes it
    // is a rewrite wearing a refactor's name.
    $svc = new ReflectionClass(IconDiscoveryService::class);

    expect($svc->hasMethod('searchIcons'))->toBeTrue()
        ->and($svc->getMethod('searchIcons')->isPublic())->toBeTrue()
        ->and($svc->hasMethod('clearCache'))->toBeTrue()
        ->and($svc->getMethod('clearCache')->isPublic())->toBeTrue();

    // ...and the service no longer carries the query itself.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/Services/IconDiscoveryService.php',
    );
    expect($source)->not->toContain('to_tsvector');
});
