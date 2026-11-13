<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;
use Simtabi\Laranail\Ichava\Tests\Support\TestIconsPackComponent;
use Simtabi\Laranail\Ichava\Tests\Fixtures\SpecPack\Providers\IconsServiceProvider;

/*
 * Proposal 2, the provider side. A pack on IconPackServiceProvider can shape
 * itself in the optional pack() hook; each assertion reads the registry or
 * Blade's live alias map.
 */

beforeEach(function (): void {
    $this->app->register(IconsServiceProvider::class);
    $this->registry = app(IconRegistry::class);
});

it('shapes the default set from code', function (): void {
    expect($this->registry->kindOf('ichava/icon-sets-spec-fixture::illustrations/office'))->toBe(IconKind::Illustration)
        ->and($this->registry->kindOf('ichava/icon-sets-spec-fixture::shapes/circle'))->toBe(IconKind::Icon)
        ->and($this->registry->aliases('ichava/icon-sets-spec-fixture'))->toBe(['shapes/ring' => 'shapes/dot']);
});

it('registers a second set and the chosen sets of a directory, attributed to the pack', function (): void {
    expect($this->registry->get('ichava/icon-sets-spec-fixture-brands')['prefix'])->toBe('brand')
        ->and($this->registry->get('ichava/icon-sets-spec-fixture-brands')['provider_class'])->toBe(IconsServiceProvider::class)
        ->and($this->registry->isRegistered('ichava/icon-sets-spec-fixture-two'))->toBeTrue()
        ->and($this->registry->isRegistered('ichava/icon-sets-spec-fixture-one'))->toBeFalse();
});

it('registers the main and the extra component, canonical and short', function (): void {
    $aliases = Blade::getClassComponentAliases();

    expect($aliases['ichava-icon-sets-spec-fixture::icon'] ?? null)->toBe(IconComponent::class)
        ->and($aliases['icon-sets-spec-fixture-icon'] ?? null)->toBe(IconComponent::class)
        ->and($aliases['ichava-icon-sets-spec-fixture::badge'] ?? null)->toBe(TestIconsPackComponent::class)
        ->and($aliases['icon-sets-spec-fixture-badge'] ?? null)->toBe(TestIconsPackComponent::class)
        ->and($this->registry->get('ichava/icon-sets-spec-fixture')['blade_component'])->toBe('icon-sets-spec-fixture-icon');
});

it('registers the config file under the name the pack gives', function (): void {
    $package = (new ReflectionProperty($this->app->getProvider(IconsServiceProvider::class), 'package'))
        ->getValue($this->app->getProvider(IconsServiceProvider::class));

    expect($package->configFileNames)->toContain('spec-custom')
        ->not->toContain('icon-sets-spec-fixture');
});

it('leaves a pack that writes no pack() hook exactly as before', function (): void {
    $this->app->register(Simtabi\Laranail\Ichava\Tests\Fixtures\BasePack\Providers\IconsServiceProvider::class);

    expect($this->registry->get('ichava/icon-sets-base-fixture')['blade_component'])->toBe('icon-sets-base-fixture-icon')
        ->and(Blade::getClassComponentAliases())->toHaveKey('ichava-icon-sets-base-fixture::icon');
});

it('registers only the canonical pack tags when the short prefix is off', function (): void {
    $this->configOverrides = ['ichava.ichava-core.components.short_prefix' => null];
    $this->refreshApplication();
    $this->app->register(IconsServiceProvider::class);

    $aliases = Blade::getClassComponentAliases();

    expect($aliases)->toHaveKey('ichava-icon-sets-spec-fixture::icon')
        ->not->toHaveKey('icon-sets-spec-fixture-icon')
        ->and(app(IconRegistry::class)->get('ichava/icon-sets-spec-fixture')['blade_component'])->toBe('ichava-icon-sets-spec-fixture::icon');
});
