<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconCacheService;

/**
 * Cache round-trip coverage on a store that actually serialises.
 *
 * The rest of the suite runs on the `array` driver, which hands back the very
 * object it was given and therefore cannot observe anything that happens
 * between serialize() and unserialize(). That blind spot is how a poisoned
 * entry reached production: the first request rendered fine and every request
 * after it returned HTTP 500, while 291 tests stayed green.
 *
 * These tests use the `file` driver so the payload makes the full round trip.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/ichava-poison-' . bin2hex(random_bytes(4));
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

    mkdir($this->root . '/cache', 0700, true);
    config()->set('cache.stores.file', ['driver' => 'file', 'path' => $this->root . '/cache']);
    config()->set('cache.default', 'file');
});

afterEach(function (): void {
    if (! empty($this->root) && is_dir($this->root)) {
        (new Filesystem)->deleteDirectory($this->root);
    }
});

/** Build the object PHP produces for a payload naming an unloadable class. */
function incompleteIconData(): object
{
    $class = 'Simtabi\Laranail\Ichava\Data\IconDataThatNoLongerExists';

    return unserialize('O:' . strlen($class) . ':"' . $class . '":1:{s:4:"name";s:4:"star";}');
}

it('produces a genuine __PHP_Incomplete_Class for an unresolvable class', function (): void {
    // Pins the premise the fix rests on: PHP raises nothing here, it just
    // hands back an object that fails every type declaration downstream.
    expect(incompleteIconData())->toBeInstanceOf(__PHP_Incomplete_Class::class);
});

it('rebuilds instead of serving a poisoned entry, and repairs it', function (): void {
    /** @var IconCacheService $cache */
    $cache = app(IconCacheService::class);

    $rebuilt = 0;
    $build = function () use (&$rebuilt) {
        $rebuilt++;

        return new IconData('star', '/x/star.svg', null, null, 'tests/pack-a');
    };

    // Seed a healthy entry, then poison it exactly as a stale payload would.
    $cache->remember('icon:tests/pack-a:star', $build, IconData::class);
    expect($rebuilt)->toBe(1);

    $key = (function (string $k) {
        $m = new ReflectionMethod(IconCacheService::class, 'buildKey');

        return $m->invoke($this, $k);
    })->call($cache, 'icon:tests/pack-a:star');

    cache()->put($key, incompleteIconData(), now()->addMinutes(10));

    $value = $cache->remember('icon:tests/pack-a:star', $build, IconData::class);

    expect($value)->toBeInstanceOf(IconData::class)
        ->and($rebuilt)->toBe(2);

    // Self-healing: the bad entry is gone, so the next read is a normal hit
    // rather than a permanent 500 that outlives the request that caused it.
    $again = $cache->remember('icon:tests/pack-a:star', $build, IconData::class);
    expect($again)->toBeInstanceOf(IconData::class)
        ->and($rebuilt)->toBe(2);
});

it('rebuilds a poisoned collection too', function (): void {
    /** @var IconCacheService $cache */
    $cache = app(IconCacheService::class);

    $build = fn () => ['star' => new IconData('star', '/x/star.svg', null, null, 'tests/pack-a')];

    $key = (function (string $k) {
        $m = new ReflectionMethod(IconCacheService::class, 'buildKey');

        return $m->invoke($this, $k);
    })->call($cache, 'set:tests/pack-a:all');

    cache()->put($key, ['star' => incompleteIconData()], now()->addMinutes(10));

    $value = $cache->remember('set:tests/pack-a:all', $build, IconData::class);

    expect($value)->toBeArray()
        ->and($value['star'])->toBeInstanceOf(IconData::class);
});

it('keeps get(all) and all() on separate cache keys', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);
    $registry->fromDirectory($this->pack, self::class);

    $set = $registry->set('tests/pack-a');

    // all() writes a collection; get('all') asks for a single icon that does
    // not exist. Sharing one key made the second call return the first call's
    // array, which fails ?IconData the same way a poisoned entry does.
    $collection = $set->all();
    $single = $set->get('all');

    expect($collection)->toBeArray()
        ->and($single)->toBeNull();
});

it('rejects a stray positional argument instead of swallowing it', function (): void {
    /** @var IconCacheService $cache */
    $cache = app(IconCacheService::class);

    // remember() took two parameters for its whole life, and PHP hands surplus
    // arguments to a userland function without complaint. Icon::getPackageCounts()
    // passed a TTL as a third positional argument and it went nowhere at all.
    // The third slot is typed now, so the same mistake is loud.
    expect(fn () => $cache->remember('probe:stray', fn () => 'x', 60 * 24))
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

it('survives a poisoned entry on the exact path the bug report names', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);
    $registry->fromDirectory($this->pack, self::class);

    $set = $registry->set('tests/pack-a');

    // Whatever the pack exposes, take a real icon name from it so this test
    // pins the reported path rather than a fixture-layout assumption.
    $names = array_keys($set->all());
    expect($names)->not->toBeEmpty();
    $name = $names[0];

    expect($set->get($name))->toBeInstanceOf(IconData::class);

    // Ask the builder itself which key get() reads, rather than hard-coding the
    // current scheme -- a hard-coded key silently stops addressing the entry
    // under test the moment the scheme changes, and the test then passes by
    // poisoning nothing.
    $logical = (new ReflectionMethod($set, 'getCacheKey'))->invoke($set, $name, null, null);

    $cache = app(IconCacheService::class);
    $key = (function (string $k) {
        return (new ReflectionMethod(IconCacheService::class, 'buildKey'))->invoke($this, $k);
    })->call($cache, $logical);

    cache()->put($key, incompleteIconData(), now()->addMinutes(10));

    // Before the fix this was the reported TypeError at IconSetBuilder::get().
    expect($registry->set('tests/pack-a')->get($name))->toBeInstanceOf(IconData::class);
});
