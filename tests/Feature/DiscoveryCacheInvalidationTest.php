<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;

/*
|--------------------------------------------------------------------------
| clearCache() clears the caches this class wrote
|--------------------------------------------------------------------------
|
| It forgot two keys out of six, and could not have reached two of the rest:
| the filesystem path writes through `cache()->store('file')`, the database
| path through IconCacheService under an entirely different prefix
| (`icons.search.db.`), while clearCache() called `Cache::forget()` on the
| DEFAULT store with hand-built keys.
|
| Asserted behaviourally -- seed, observe staleness, clear, observe freshness
| -- rather than by poking at keys. Keys are md5-suffixed and unenumerable;
| what a caller actually depends on is that clearing works.
|
*/

beforeEach(function () {
    // The file store is real files on disk and outlives the test run, so a
    // previous run's cached search result reads as this run's cold lookup.
    // Found the hard way: this test passed alone and failed in the suite.
    $this->app['config']->set('cache.default', 'file');
    Cache::store('file')->flush();
});

it('serves fresh results after a clear', function () {
    $svc = app(IconDiscoveryService::class);

    // Cold: nothing matches, and that miss is now cached.
    expect($svc->searchIcons('beacon')['total'] ?? 0)->toBe(0);

    Icon::query()->create([
        'package'   => 'ichava/test-icons',
        'name'      => 'beacon',
        'path'      => 'ichava/test-icons::beacon',
        'category'  => 'general',
        'file_path' => 'beacon.svg',
    ]);

    // Still stale -- this is the cache doing its job, not the defect.
    expect($svc->searchIcons('beacon')['total'] ?? 0)->toBe(0);

    $svc->clearCache();

    // The defect: before the fix this stayed 0, because clearCache() never
    // touched the store or the prefix the search results live under.
    expect($svc->searchIcons('beacon')['total'] ?? 0)->toBe(1);
});
