<?php

declare(strict_types=1);

use Illuminate\Cache\FileStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconCacheService;

/**
 * The cache is untrusted input (V55, C-F1).
 *
 * The suite pins `cache.default` to `array`, which hands back the object it was
 * given and never crosses serialize()/unserialize(). These tests run on the
 * `file` driver, so what comes back is what another process wrote.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/ichava-cachepoison-' . bin2hex(random_bytes(4));
    mkdir($this->root, 0700, true);

    config()->set('cache.stores.file', ['driver' => 'file', 'path' => $this->root]);
    config()->set('cache.default', 'file');

    $this->service = $this->app->make(IconCacheService::class);

    // The key IconCacheService::remember() writes for a logical key.
    $this->fullKey = fn (string $key): string => (fn (string $k): string => $this->buildKey($k))
        ->call($this->service, $key);

    // Write a payload that unserializes to __PHP_Incomplete_Class: an object of a
    // class no autoloader can resolve, exactly what a build that renamed or
    // removed a cached class leaves behind.
    $this->plantIncomplete = function (string $fullKey): void {
        /** @var FileStore $store */
        $store = Cache::store('file')->getStore();
        $class = 'Ichava\\Removed\\CachedPayload' . bin2hex(random_bytes(4));
        $path = $store->path($fullKey);

        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, (time() + 3600) . 'O:' . strlen($class) . ':"' . $class . '":0:{}');

        expect(Cache::store('file')->get($fullKey))->toBeInstanceOf(__PHP_Incomplete_Class::class);
    };
});

afterEach(function (): void {
    if (! empty($this->root) && is_dir($this->root)) {
        (new Filesystem)->deleteDirectory($this->root);
    }
});

it('rebuilds an entry that unserializes to an incomplete class', function (): void {
    $fullKey = ($this->fullKey)('poison.probe');
    ($this->plantIncomplete)($fullKey);

    $value = $this->service->remember('poison.probe', fn (): array => ['rebuilt' => true], expects: 'array');

    expect($value)->toBe(['rebuilt' => true])
        ->and(Cache::store('file')->get($fullKey))->toBe(['rebuilt' => true]);
});

it('refuses an incomplete class even when no type is expected', function (): void {
    $fullKey = ($this->fullKey)('poison.untyped');
    ($this->plantIncomplete)($fullKey);

    $value = $this->service->remember('poison.untyped', fn (): string => 'rebuilt');

    expect($value)->toBe('rebuilt')
        ->and(Cache::store('file')->get($fullKey))->toBe('rebuilt');
});

it('evicts a value of the wrong type', function (): void {
    $fullKey = ($this->fullKey)('poison.shape');
    Cache::store('file')->put($fullKey, 'a string where an array belongs', 3600);

    $value = $this->service->remember('poison.shape', fn (): array => ['ok'], expects: 'array');

    expect($value)->toBe(['ok'])
        ->and(Cache::store('file')->get($fullKey))->toBe(['ok']);
});

it('serves a valid hit without calling the callback', function (): void {
    $this->service->remember('poison.valid', fn (): array => ['first'], expects: 'array');

    $value = $this->service->remember('poison.valid', function (): array {
        throw new RuntimeException('a valid hit must not rebuild');
    }, expects: 'array');

    expect($value)->toBe(['first']);
});

it('repairs a poisoned package-count entry at the call site', function (): void {
    $fullKey = ($this->fullKey)('icons.counts.packages');
    ($this->plantIncomplete)($fullKey);

    // Icon::getPackageCounts() is typed `: array`; an incomplete object reaching
    // it would be a TypeError on every request until the TTL ran out.
    expect(Icon::getPackageCounts())->toBeArray()
        ->and(Cache::store('file')->get($fullKey))->toBeArray();
});

it('abandons every existing entry when the cache version changes', function (): void {
    config()->set('ichava.ichava-core.cache.version', 'v2');
    $before = ($this->fullKey)('poison.version');
    $this->service->remember('poison.version', fn (): string => 'old');

    config()->set('ichava.ichava-core.cache.version', 'v3');
    $after = ($this->fullKey)('poison.version');

    expect($after)->not->toBe($before)
        ->and($this->service->remember('poison.version', fn (): string => 'new'))->toBe('new');
});

it('reads the cache version from ICHAVA_CACHE_VERSION', function (): void {
    $set = function (?string $value): void {
        foreach ([&$_ENV, &$_SERVER] as &$bag) {
            if ($value === null) {
                unset($bag['ICHAVA_CACHE_VERSION']);
            } else {
                $bag['ICHAVA_CACHE_VERSION'] = $value;
            }
        }
        putenv($value === null ? 'ICHAVA_CACHE_VERSION' : 'ICHAVA_CACHE_VERSION=' . $value);
    };

    $config = dirname(__DIR__, 2) . '/config/ichava-core.php';

    try {
        $set('v9-test');
        expect((require $config)['cache']['version'])->toBe('v9-test');

        $set(null);
        expect((require $config)['cache']['version'])->toBe('v2');
    } finally {
        $set(null);
    }
});
