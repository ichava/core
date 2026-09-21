<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;
use Simtabi\Laranail\Ichava\Tests\Fixtures\TranslatedPack\Providers\IconsServiceProvider;

/*
|--------------------------------------------------------------------------
| Package metadata is read under the keys the registry actually writes
|--------------------------------------------------------------------------
|
| Nine call sites in this package read `$metadata['browser_metadata'][...]`.
| `IconRegistry` has never written a `browser_metadata` key -- 16 reads across
| core and browser, zero writes -- so every one of them fell through its `??`
| default. The browser showed the raw package slug where a title belonged, and
| an empty string where a description belonged.
|
| Nothing failed, because every read had a fallback that looked plausible. That
| is the same shape as the rest of this family's bugs: a fallback is only a
| safety net if something notices you are standing in it.
|
*/

beforeEach(function () {
    $this->app->register(IconsServiceProvider::class);
});

it('writes no browser_metadata key, which is why the reads were wrong', function () {
    $metadata = app(IconRegistry::class)->all()['ichava/fixture-pack'];

    expect($metadata)->not->toHaveKey('browser_metadata');
});

it('exposes the title under the key the registry writes', function () {
    $metadata = app(IconRegistry::class)->all()['ichava/fixture-pack'];

    // Not the package slug. That is what every reader got before.
    expect($metadata['name'])->toBe('Fixture Pack')
        ->and($metadata['name'])->not->toBe('ichava/fixture-pack');
});

it('gives discovery a real title rather than the package slug', function () {
    $pack = app(IconDiscoveryService::class)->getPackages()['ichava/fixture-pack'];

    // Before the fix this read $metadata['browser_metadata']['name'], which does
    // not exist, and fell through to icon_set_name -- the slug.
    expect($pack['name'])->toBe('Fixture Pack')
        ->and($pack['name'])->not->toBe('ichava/fixture-pack');

    expect($pack['description'])->toBe('Canonical description, from config.json');
});

it('carries the localised title through to consumers', function () {
    // The payoff of reading the right key: a locale switch reaches the browser,
    // because the registry applies its translation overlay on read.
    App::setLocale('fr');

    $metadata = app(IconRegistry::class)->all()['ichava/fixture-pack'];

    expect($metadata['name'])->toBe('Pack de démonstration');
});
