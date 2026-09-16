<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconCacheService;

/**
 * Two contracts of the icon cache that nothing else pins.
 *
 * `IconSetBuilderCacheTest` already covers the payload shape on a serialising
 * store. These cover the key namespace and the method signature, which are
 * separate failures with the same symptom: a lookup that returns the wrong thing.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/ichava-cachecontract-' . bin2hex(random_bytes(4));
    $this->pack = $this->root . '/pack-a';

    $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/></svg>';

    // buildIconPath() resolves <pack>/files/<name>.svg -- basePath() is the
    // `files` directory itself, so an icon one level deeper is never found.
    mkdir($this->pack . '/files', 0700, true);
    file_put_contents($this->pack . '/config.json', json_encode([
        'schema_version' => '1.0',
        'package'        => ['name' => 'tests/pack-a', 'title' => 'Fixture', 'version' => '1.0.0', 'type' => 'collection', 'license' => 'MIT'],
        'metadata'       => ['data' => ['variants' => [], 'categories' => []]],
        'config'         => ['icon_prefix' => 'pa'],
    ]));
    file_put_contents($this->pack . '/files/star.svg', $svg);

    // A store that actually serialises, so the round trip is real.
    mkdir($this->root . '/cache', 0700, true);
    config()->set('cache.stores.file', ['driver' => 'file', 'path' => $this->root . '/cache']);
    config()->set('cache.default', 'file');
});

afterEach(function (): void {
    if (! empty($this->root) && is_dir($this->root)) {
        (new Filesystem)->deleteDirectory($this->root);
    }
});

it('keeps the whole-set cache key apart from a single icon named "all"', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);
    $registry->fromDirectory($this->pack, self::class);

    $set = $registry->set('tests/pack-a');

    // all() writes a map of icon payloads; get('all') asks for one icon that does
    // not exist. They used to flatten to the same key, so the second call read the
    // first call's value and handed a map of arrays to a reader expecting one icon.
    $collection = $set->all();
    $single = $set->get('all');

    expect($collection)->toBeArray()
        ->and($collection)->toHaveKey('star')
        ->and($single)->toBeNull();
});

it('still returns the icon after the whole set has been cached', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);
    $registry->fromDirectory($this->pack, self::class);

    $set = $registry->set('tests/pack-a');
    $set->all();

    expect($set->get('star'))->not->toBeNull()
        ->and($set->get('star')->name)->toBe('star');
});

it('uses the third argument as a ttl instead of discarding it', function (): void {
    /** @var IconCacheService $cache */
    $cache = app(IconCacheService::class);

    // remember() took exactly two parameters for its whole life, and PHP hands
    // surplus arguments to a userland function without complaint, so a third
    // positional argument went nowhere at all -- which is how
    // Icon::getPackageCounts() spent its life believing it had asked for a
    // 24-hour TTL. The slot is real now, and positional and named agree.
    $calls = 0;
    $build = function () use (&$calls) {
        $calls++;

        return 'value';
    };

    $cache->remember('probe:positional', $build, 3600);
    $cache->remember('probe:positional', $build, ttl: 3600);

    expect($calls)->toBe(1);

    // And a value that is not a lifetime is now refused rather than ignored.
    expect(fn () => $cache->remember('probe:bad', fn () => 'x', 'twenty-four hours'))
        ->toThrow(TypeError::class);
});

it('honours a per-call ttl', function (): void {
    /** @var IconCacheService $cache */
    $cache = app(IconCacheService::class);

    $calls = 0;
    $build = function () use (&$calls) {
        $calls++;

        return 'value';
    };

    $cache->remember('probe:ttl', $build, ttl: 3600);
    $cache->remember('probe:ttl', $build, ttl: 3600);

    expect($calls)->toBe(1);
});

it('reports package counts without tripping the new signature', function (): void {
    expect(Simtabi\Laranail\Ichava\Models\Icon::getPackageCounts())->toBeArray();
});
