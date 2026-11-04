<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;
use Simtabi\Laranail\Ichava\Tests\Fixtures\BasePack\Providers\IconsServiceProvider;

/*
|--------------------------------------------------------------------------
| A pack that declares two class names gets everything a pack provider did
|--------------------------------------------------------------------------
|
| Every estate pack provider set its name, its path, its config file when it
| had one, its Blade component and its icon directory, in that order. The
| fixture provider extends IconPackServiceProvider and declares only its
| constants class and its component class, so each of those five has to come
| from the base. Every assertion reads what the framework ended up holding --
| the built Package, Blade's alias map, the registry -- not the source.
|
*/

beforeEach(function (): void {
    $this->app->register(IconsServiceProvider::class);
    $this->package = (new ReflectionProperty($this->app->getProvider(IconsServiceProvider::class), 'package'))
        ->getValue($this->app->getProvider(IconsServiceProvider::class));
});

it('names the package from the constants class', function (): void {
    expect($this->package->getSlashNamespace())->toBe('ichava/icon-sets-base-fixture')
        ->and($this->package->name)->toBe('icon-sets-base-fixture');
});

it('resolves the pack root from the provider file', function (): void {
    expect(str_replace('\\', '/', $this->package->basePath()))->toEndWith('tests/fixtures/BasePack');
});

it('registers the config file the pack ships under its slug', function (): void {
    expect($this->package->configFileNames)->toContain('icon-sets-base-fixture');
});

it('registers the Blade component as <x-{slug}-icon>', function (): void {
    expect(Blade::getClassComponentAliases())->toHaveKey('icon-sets-base-fixture-icon', IconComponent::class);
});

it('registers resources/assets/svg with the registry, attributed to the pack provider', function (): void {
    $registry = app(IconRegistry::class);

    expect($registry->isRegistered('ichava/icon-sets-base-fixture'))->toBeTrue()
        ->and($registry->get('ichava/icon-sets-base-fixture')['provider_class'] ?? null)->toBe(IconsServiceProvider::class)
        ->and($registry->get('ichava/icon-sets-base-fixture')['blade_component'] ?? null)->toBe('icon-sets-base-fixture-icon');
});

it('lets a pack provider name no laranail type at all', function (): void {
    $source = (string) file_get_contents((new ReflectionClass(IconsServiceProvider::class))->getFileName());

    expect($source)->not->toContain('Simtabi\\Laranail\\Package\\')
        ->and($source)->not->toContain('configurePackage');
});
