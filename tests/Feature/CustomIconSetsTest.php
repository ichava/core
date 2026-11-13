<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Services\IconRegistry;

/*
 * C-09. `custom-icons.sets` documented `enabled`, `prefix`, `default_class`,
 * `blade_namespace` and `blade_component`, and the registry read `path` only.
 * Each documented key now reaches the set, through the same PackBuilder path
 * a pack takes, and a `bulk` entry registers every set under its path.
 */

function customIconsTree(): string
{
    $root = sys_get_temp_dir() . '/ichava-custom-' . bin2hex(random_bytes(4));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>';

    File::ensureDirectoryExists("{$root}/social");
    File::put("{$root}/social/twitter.svg", $svg);

    foreach (['alpha', 'beta'] as $set) {
        File::ensureDirectoryExists("{$root}/vendored/{$set}/files");
        File::put("{$root}/vendored/{$set}/files/square.svg", $svg);
        File::put("{$root}/vendored/{$set}/config.json", json_encode(['package' => ['name' => "vendored/{$set}"], 'config' => ['icon_prefix' => $set]]));
    }

    return $root;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/ichava-custom-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('registers a flat directory with every documented key applied, at boot', function (): void {
    $root = customIconsTree();
    $this->configOverrides = ['ichava.ichava-core.custom-icons.sets' => [
        'social' => [
            'enabled'         => true,
            'path'            => "{$root}/social",
            'prefix'          => 'soc',
            'default_class'   => 'social-icon',
            'blade_namespace' => 'ichava',
            'blade_component' => 'social',
        ],
    ]];
    $this->refreshApplication();

    $registry = app(IconRegistry::class);
    $meta = $registry->get('app/social');

    expect($meta['prefix'])->toBe('soc')
        ->and($meta['blade_component'])->toBe('ichava-social')
        ->and($registry->render('app/social::twitter'))->toContain('social-icon');
});

it('skips an entry that is not enabled', function (): void {
    $root = customIconsTree();

    app(IconRegistry::class)->registerFromConfig(['social' => ['enabled' => false, 'path' => "{$root}/social"]]);

    expect(app(IconRegistry::class)->isRegistered('app/social'))->toBeFalse();
});

it('names a set by its package key when one is given, and keeps a plain path entry working', function (): void {
    $root = customIconsTree();

    app(IconRegistry::class)->registerFromConfig([
        'social' => ['path' => "{$root}/social", 'package' => 'acme/social'],
        "{$root}/vendored/alpha",
    ]);

    expect(app(IconRegistry::class)->isRegistered('acme/social'))->toBeTrue()
        ->and(app(IconRegistry::class)->isRegistered('vendored/alpha'))->toBeTrue();
});

it('registers every set under a bulk entry, limited by only', function (): void {
    $root = customIconsTree();

    app(IconRegistry::class)->registerFromConfig([
        'vendored' => ['path' => "{$root}/vendored", 'bulk' => true, 'only' => ['beta']],
    ]);

    expect(app(IconRegistry::class)->isRegistered('vendored/beta'))->toBeTrue()
        ->and(app(IconRegistry::class)->isRegistered('vendored/alpha'))->toBeFalse();
});
