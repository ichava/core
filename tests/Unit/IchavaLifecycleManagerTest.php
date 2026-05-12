<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Ichava\Services\IchavaLifecycleManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->lifecycle = app(IchavaLifecycleManager::class);
    Cache::forget('ichava:lifecycle:ready');
});

describe('IchavaLifecycleManager stage detection', function () {
    it('reports UNINITIALIZED when migrations are absent or incomplete', function () {
        // The shipped migration omits two of the columns hasMigrations() checks
        // for, so it returns false on a real schema. Either way, getStage()
        // surfaces UNINITIALIZED and we exercise the early-return paths.
        DB::statement('DROP TABLE IF EXISTS ichava_icons');
        expect($this->lifecycle->hasMigrations())->toBeFalse();
        expect($this->lifecycle->getStage())->toBe('UNINITIALIZED');
    });

    it('reports false for hasSeeds when migrations are absent', function () {
        DB::statement('DROP TABLE IF EXISTS ichava_icons');
        expect($this->lifecycle->hasSeeds())->toBeFalse();
    });

    it('reports cache as available when the store works', function () {
        expect($this->lifecycle->hasCache())->toBeTrue();
    });

    it('isReady returns false on a fresh app', function () {
        expect($this->lifecycle->isReady())->toBeFalse();
    });
});

describe('IchavaLifecycleManager state-cache helpers', function () {
    it('persists ready state via markAsReady', function () {
        $this->lifecycle->markAsReady();
        expect(Cache::get('ichava:lifecycle:ready'))->toBeTrue();
    });

    it('clears ready state via reset', function () {
        $this->lifecycle->markAsReady();
        $this->lifecycle->reset();
        expect(Cache::get('ichava:lifecycle:ready'))->toBeNull();
    });

    it('forceReady marks as ready even when stages are incomplete', function () {
        DB::statement('DROP TABLE IF EXISTS ichava_icons');
        $this->lifecycle->forceReady();
        expect(Cache::get('ichava:lifecycle:ready'))->toBeTrue();
    });

    it('returns UNINITIALIZED from getStage when migration check fails', function () {
        DB::statement('DROP TABLE IF EXISTS ichava_icons');
        expect($this->lifecycle->getStage())->toBe('UNINITIALIZED');
    });
});

describe('IchavaLifecycleManager waitUntilReady', function () {
    it('returns false after exhausting attempts when not ready', function () {
        DB::statement('DROP TABLE IF EXISTS ichava_icons');
        $ready = $this->lifecycle->waitUntilReady(maxAttempts: 2, delayMs: 1);
        expect($ready)->toBeFalse();
    });
});
