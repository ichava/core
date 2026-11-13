<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Every test owns its file-cache directory
|--------------------------------------------------------------------------
|
| The discovery, cache and watcher services write through
| `cache()->store('file')` whatever the default store is. Testbench points that
| store at one directory under vendor/, so every test and every `--parallel`
| worker shared it: a search cached by one worker answered another worker's
| search against a different in-memory database, and the discovery-cache tests
| failed in 6 of 10 parallel runs.
|
| Refreshing the application is the deterministic stand-in for "another test,
| or another worker": what one boot wrote must not be readable from the next.
|
*/

it('does not use the shared Testbench storage directory', function () {
    $path = config('cache.stores.file.path');

    expect($path)->toBeString()
        ->and($path)->not->toBe(storage_path('framework/cache/data'))
        ->and($path)->toStartWith(sys_get_temp_dir());
});

it('does not hand one application boot the file cache of another', function () {
    $first = config('cache.stores.file.path');
    Cache::store('file')->forever('ichava.isolation.probe', 'written by the first boot');

    expect(Cache::store('file')->get('ichava.isolation.probe'))->toBe('written by the first boot');

    $this->refreshApplication();

    expect(config('cache.stores.file.path'))->not->toBe($first)
        ->and(Cache::store('file')->get('ichava.isolation.probe'))->toBeNull();
});
