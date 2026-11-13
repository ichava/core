<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\PackConfigCache;
use Simtabi\Laranail\Ichava\Services\IconWatcherService;
use Simtabi\Laranail\Ichava\Services\ConfigurationService;

/*
 * C-05. IconCacheService reached IconWatcherService through the container, and
 * the watcher reached back through ConfigurationService (which holds the cache
 * service) to forget a pack's cached config.json. PackConfigCache owns those
 * keys now, so a rescan forgets the config without touching either service.
 */

it('forgets a pack config on rescan without resolving ConfigurationService', function (): void {
    $resolved = 0;
    app()->resolving(ConfigurationService::class, function () use (&$resolved): void {
        $resolved++;
    });

    app(IconWatcherService::class)->rescanPackage('ichava/test-icons');

    expect($resolved)->toBe(0);
});

it('forgets through the same key ConfigurationService remembers under', function (): void {
    $dir = dirname(__DIR__, 2) . '/resources/assets/svg/test-icons';
    $cache = app(PackConfigCache::class);

    app(ConfigurationService::class)->rememberPackageConfig($dir);

    expect(cache()->store('file')->has($cache->key($dir)))->toBeTrue()
        ->and($cache->forget($dir))->toBeTrue()
        ->and(cache()->store('file')->has($cache->key($dir)))->toBeFalse();
});
