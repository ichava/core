<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Enums\CacheDriver;
use Simtabi\Laranail\Ichava\Enums\ComponentSize;
use Simtabi\Laranail\Ichava\Enums\OptimizationLevel;

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

describe('ComponentSize enum', function () {
    it('exposes six named sizes', function () {
        expect(ComponentSize::cases())->toHaveCount(6);
    });

    it('returns correct CSS class for each size', function () {
        expect(ComponentSize::XS->getClass())->toBe('icon-xs');
        expect(ComponentSize::SM->getClass())->toBe('icon-sm');
        expect(ComponentSize::MD->getClass())->toBe('icon-md');
        expect(ComponentSize::LG->getClass())->toBe('icon-lg');
        expect(ComponentSize::XL->getClass())->toBe('icon-xl');
        expect(ComponentSize::XXL->getClass())->toBe('icon-xxl');
    });

    it('returns correct pixel values', function () {
        expect(ComponentSize::XS->getPixels())->toBe(12);
        expect(ComponentSize::SM->getPixels())->toBe(16);
        expect(ComponentSize::MD->getPixels())->toBe(20);
        expect(ComponentSize::LG->getPixels())->toBe(24);
        expect(ComponentSize::XL->getPixels())->toBe(32);
        expect(ComponentSize::XXL->getPixels())->toBe(48);
    });

    it('converts pixels to rem at 16px base', function () {
        expect(ComponentSize::SM->getRem())->toBe(1.0);
        expect(ComponentSize::MD->getRem())->toBe(1.25);
        expect(ComponentSize::XXL->getRem())->toBe(3.0);
    });

    it('detects named sizes', function () {
        expect(ComponentSize::isNamed('xs'))->toBeTrue();
        expect(ComponentSize::isNamed('xxl'))->toBeTrue();
        expect(ComponentSize::isNamed('huge'))->toBeFalse();
        expect(ComponentSize::isNamed('24px'))->toBeFalse();
    });

    it('parses arbitrary numeric sizes with default px unit', function () {
        $result = ComponentSize::parseArbitrary('24');
        expect($result)->toBe(['value' => '24', 'unit' => 'px']);
    });

    it('parses arbitrary sizes with explicit units', function () {
        expect(ComponentSize::parseArbitrary('24px'))->toBe(['value' => '24', 'unit' => 'px']);
        expect(ComponentSize::parseArbitrary('2rem'))->toBe(['value' => '2', 'unit' => 'rem']);
        expect(ComponentSize::parseArbitrary('1.5em'))->toBe(['value' => '1.5', 'unit' => 'em']);
        expect(ComponentSize::parseArbitrary('50%'))->toBe(['value' => '50', 'unit' => '%']);
        expect(ComponentSize::parseArbitrary('5vh'))->toBe(['value' => '5', 'unit' => 'vh']);
        expect(ComponentSize::parseArbitrary('5vw'))->toBe(['value' => '5', 'unit' => 'vw']);
        expect(ComponentSize::parseArbitrary('5vmin'))->toBe(['value' => '5', 'unit' => 'vmin']);
        expect(ComponentSize::parseArbitrary('5vmax'))->toBe(['value' => '5', 'unit' => 'vmax']);
    });

    it('parses named sizes through parseArbitrary', function () {
        expect(ComponentSize::parseArbitrary('md'))->toBe(['value' => '20', 'unit' => 'px']);
    });

    it('returns null on unparseable input', function () {
        expect(ComponentSize::parseArbitrary('garbage'))->toBeNull();
        expect(ComponentSize::parseArbitrary(''))->toBeNull();
    });

    it('formats arbitrary sizes for CSS', function () {
        expect(ComponentSize::format('24'))->toBe('24px');
        expect(ComponentSize::format('2rem'))->toBe('2rem');
        expect(ComponentSize::format('md'))->toBe('20px');
        expect(ComponentSize::format('garbage'))->toBeNull();
    });

    it('returns the full named-size table', function () {
        $all = ComponentSize::all();
        expect($all)->toBe([
            'xs' => 12, 'sm' => 16, 'md' => 20, 'lg' => 24, 'xl' => 32, 'xxl' => 48,
        ]);
    });
});

describe('OptimizationLevel enum', function () {
    it('exposes three levels', function () {
        expect(OptimizationLevel::cases())->toHaveCount(3);
    });

    it('removes comments only at basic and aggressive', function () {
        expect(OptimizationLevel::NONE->shouldRemoveComments())->toBeFalse();
        expect(OptimizationLevel::BASIC->shouldRemoveComments())->toBeTrue();
        expect(OptimizationLevel::AGGRESSIVE->shouldRemoveComments())->toBeTrue();
    });

    it('removes metadata only at aggressive', function () {
        expect(OptimizationLevel::NONE->shouldRemoveMetadata())->toBeFalse();
        expect(OptimizationLevel::BASIC->shouldRemoveMetadata())->toBeFalse();
        expect(OptimizationLevel::AGGRESSIVE->shouldRemoveMetadata())->toBeTrue();
    });

    it('minifies only at aggressive', function () {
        expect(OptimizationLevel::NONE->shouldMinify())->toBeFalse();
        expect(OptimizationLevel::BASIC->shouldMinify())->toBeFalse();
        expect(OptimizationLevel::AGGRESSIVE->shouldMinify())->toBeTrue();
    });
});
