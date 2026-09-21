<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;

/*
|--------------------------------------------------------------------------
| The Blade-component conflict detector, and the switches around it
|--------------------------------------------------------------------------
|
| IconRegistry::checkConflicts() has three detectors. Two fired. The third
| guarded on `$metadata['blade_component'] ?? null`, and nothing in the
| codebase ever wrote that key -- so the guard read null every time and its
| whole branch was unreachable. Blade keeps component aliases in a flat map,
| which makes a silent overwrite the exact failure this detector exists to
| catch, and it is the collision the global standard calls the headline risk.
|
| These began as Phase 4 reproductions and all three failed on main. They are
| kept as regressions: each one goes red again if its fix is reverted.
|
*/

it('records the Blade component alias a pack actually registered', function () {
    // Register through the real path -- fromDirectory(), the way every pack
    // does it -- rather than hand-injecting metadata. Injecting the key proves
    // the detector works when fed; it says nothing about whether anything
    // feeds it.
    $this->app->register(
        Simtabi\Laranail\Ichava\Tests\Fixtures\TranslatedPack\Providers\IconsServiceProvider::class,
    );

    $metadata = app(IconRegistry::class)->all()['ichava/fixture-pack'];

    // checkConflicts() guards its whole Blade-component branch on
    // `$metadata['blade_component'] ?? null`. Before this was recorded, the
    // guard read null every time and the branch was unreachable.
    //
    // The fixture calls loadBladeComponent(IconComponent::class, 'fixture-pack'),
    // which registers <x-fixture-pack-icon />. The recorded value must be what
    // Blade actually holds rather than something re-derived: the alias comes
    // from the short name passed at registration, which no other piece of
    // metadata carries.
    expect($metadata)->toHaveKey('blade_component', 'fixture-pack-icon');
});

it('builds a usage hint without warning when a pack has no component', function () {
    $registry = app(IconRegistry::class);
    $reflection = new ReflectionProperty($registry, 'packages');
    $reflection->setValue($registry, ['vendor/pack' => [
        'package_name'  => 'vendor/pack',
        'icon_set_name' => 'vendor/pack',
        'prefix'        => 'vp',
        'base_path'     => sys_get_temp_dir(),
        // no `blade_component` -- which is every pack, since nothing writes it
    ]]);

    $warnings = [];
    set_error_handler(function (int $no, string $msg) use (&$warnings): bool {
        $warnings[] = $msg;

        return true;
    }, E_WARNING);

    try {
        app(IconDiscoveryService::class)->getIconSyntax('vendor/pack', 'home');
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toBe([], 'getIconSyntax emitted: ' . implode('; ', $warnings));
});

it('ships the destructive auto_unseed switch, not just the additive one', function () {
    // AutoUnseedOnUnregistration reads it with a default of TRUE, so unseeding
    // is on by default. The shipped config declares only `auto_seed`, which
    // defaults to FALSE. A consumer who publishes the config finds a switch for
    // the additive behaviour and none for the destructive one.
    $database = config('ichava.ichava-core.database');

    expect($database)->toHaveKey(
        'auto_unseed',
        'config/ichava-core.php ships auto_seed but not auto_unseed, so the destructive default is undiscoverable.',
    );
});

it('detects two packs claiming the same Blade component alias', function () {
    // The point of the whole finding: not that the key exists, but that the
    // detector it feeds now fires. Blade keeps aliases in a flat map, so the
    // second registration silently replaces the first -- the collision is
    // invisible without this.
    $registry = app(IconRegistry::class);

    $packages = new ReflectionProperty($registry, 'packages');
    $packages->setValue($registry, ['vendor/first' => [
        'package_name'    => 'vendor/first',
        'icon_set_name'   => 'vendor/first',
        'prefix'          => 'aa',
        'blade_component' => 'shared-icon',
    ]]);

    $check = new ReflectionMethod($registry, 'checkConflicts');
    $check->invoke($registry, 'vendor/second', [
        'package_name'    => 'vendor/second',
        'icon_set_name'   => 'vendor/second',
        'prefix'          => 'bb',
        'blade_component' => 'shared-icon',
    ]);

    expect($registry->getConflicts())->toHaveKey('blade_component');
    expect($registry->getConflicts()['blade_component']['shared-icon'])
        ->toContain('vendor/first')
        ->toContain('vendor/second');
});
