<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Simtabi\Laranail\Ichava\Constants\IchavaConstants;
use Simtabi\Laranail\Ichava\Services\InformationService;
use Simtabi\Laranail\Ichava\Tests\Fixtures\TranslatedPack\Providers\IconsServiceProvider;

/*
|--------------------------------------------------------------------------
| `InformationService::discoverPackages()` finds the packs Composer installed
|--------------------------------------------------------------------------
|
| It used to glob `vendor/*\/ichava-*`, which matches no pack in the estate:
| packs install as `vendor/ichava/icon-sets-*`. And it reported `registered`
| through `IconRegistry::has()`, which answers whether an ICON exists, so a
| registered pack read as unregistered. `ichava::ichava-core.info discover`
| therefore listed nothing on every installation.
|
| A pack now says what it is in its composer.json, `"type": "ichava-icon-set"`,
| and discovery asks Composer's own runtime API for that type. The installed
| set is injected with `InstalledVersions::reload()`, Composer's documented
| seam for exactly this, and restored afterwards.
|
*/

/** A Composer `installed.php` holding one pack, at the fixture's path. */
function installedWithPack(string $name, string $path, string $type = IchavaConstants::PACK_COMPOSER_TYPE): array
{
    return [
        'root' => [
            'name'           => 'acme/host-app',
            'pretty_version' => 'dev-main',
            'version'        => 'dev-main',
            'reference'      => null,
            'type'           => 'project',
            'install_path'   => sys_get_temp_dir(),
            'aliases'        => [],
            'dev'            => false,
        ],
        'versions' => [
            $name => [
                'pretty_version'  => '0.1.0',
                'version'         => '0.1.0.0',
                'reference'       => null,
                'type'            => $type,
                'install_path'    => $path,
                'aliases'         => [],
                'dev_requirement' => false,
            ],
        ],
    ];
}

afterEach(function (): void {
    // Back to whatever vendor/composer/installed.php says.
    InstalledVersions::reload(null);
});

it('discovers an installed pack by its composer type', function (): void {
    $path = dirname(__DIR__) . '/fixtures/TranslatedPack';
    InstalledVersions::reload(installedWithPack('ichava/fixture-pack', $path));

    $discovered = app(InformationService::class)->discoverPackages();

    expect($discovered)->toHaveKey('ichava/fixture-pack')
        ->and($discovered['ichava/fixture-pack'])->toBe([
            'path'       => $path,
            'registered' => false,
        ]);
});

it('reports a discovered pack as registered once its provider has booted', function (): void {
    $path = dirname(__DIR__) . '/fixtures/TranslatedPack';
    InstalledVersions::reload(installedWithPack('ichava/fixture-pack', $path));

    $this->app->register(IconsServiceProvider::class);

    expect(app(InformationService::class)->discoverPackages()['ichava/fixture-pack']['registered'] ?? null)->toBeTrue();
});

it('ignores an installed package of any other type', function (): void {
    InstalledVersions::reload(installedWithPack('acme/not-a-pack', sys_get_temp_dir(), 'library'));

    expect(app(InformationService::class)->discoverPackages())->not->toHaveKey('acme/not-a-pack');
});
