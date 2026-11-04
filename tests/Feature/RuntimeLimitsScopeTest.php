<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\RuntimeLimits;

/*
 * C-01. A library must not change the host's process-wide limits. Ichava used
 * to raise `memory_limit` and lift `max_execution_time` while its provider
 * registered, so every host request and command ran with them. The limits now
 * apply only inside ichava's own seed, watch and cache operations, read from
 * the merged config, and are restored when the operation returns.
 */

beforeEach(function (): void {
    $this->hostMemory = ini_get('memory_limit');
    $this->hostTime = ini_get('max_execution_time');
});

afterEach(function (): void {
    ini_set('memory_limit', (string) $this->hostMemory);
    ini_set('max_execution_time', (string) $this->hostTime);
});

it('leaves the host memory and time limits alone when the provider boots', function (): void {
    ini_set('memory_limit', '3G');
    ini_set('max_execution_time', '45');

    $this->refreshApplication();

    expect(ini_get('memory_limit'))->toBe('3G')
        ->and(ini_get('max_execution_time'))->toBe('45');
});

it('applies the merged config inside an ichava operation and restores the host values after', function (): void {
    config()->set('ichava.ichava-core.runtime.memory_limit', '2G');
    config()->set('ichava.ichava-core.runtime.max_execution_time', 0);
    ini_set('memory_limit', '3G');
    ini_set('max_execution_time', '45');

    $inside = app(RuntimeLimits::class)->scope(fn (): array => [ini_get('memory_limit'), ini_get('max_execution_time')]);

    expect($inside)->toBe(['2G', '0'])
        ->and(ini_get('memory_limit'))->toBe('3G')
        ->and(ini_get('max_execution_time'))->toBe('45');
});

it('restores the host values when the operation throws', function (): void {
    ini_set('memory_limit', '3G');

    expect(fn () => app(RuntimeLimits::class)->scope(function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect(ini_get('memory_limit'))->toBe('3G');
});

it('reads the shipped config default, not a code fallback', function (): void {
    expect(app(RuntimeLimits::class)->configurator()->getPending())
        ->toMatchArray(['memory_limit' => '2G', 'max_execution_time' => 0]);
});

it('lets one operation override the timeout without touching the config', function (): void {
    expect(app(RuntimeLimits::class)->configurator(timeout: 120)->getPending()['max_execution_time'])->toBe(120);
});
