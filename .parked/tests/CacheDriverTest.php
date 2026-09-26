<?php

declare(strict_types=1);

// Moved from tests/Unit/EnumsTest.php with .parked/Enums/CacheDriver.php. Not run.

use Simtabi\Laranail\Ichava\Enums\CacheDriver;

describe('CacheDriver enum', function () {
    it('exposes all five drivers', function () {
        expect(CacheDriver::cases())->toHaveCount(5);
        expect(CacheDriver::FILE->value)->toBe('file');
        expect(CacheDriver::REDIS->value)->toBe('redis');
        expect(CacheDriver::ARRAY->value)->toBe('array');
        expect(CacheDriver::DATABASE->value)->toBe('database');
        expect(CacheDriver::MEMCACHED->value)->toBe('memcached');
    });

    it('parses from string values', function () {
        expect(CacheDriver::from('file'))->toBe(CacheDriver::FILE);
        expect(CacheDriver::tryFrom('does-not-exist'))->toBeNull();
    });
});
