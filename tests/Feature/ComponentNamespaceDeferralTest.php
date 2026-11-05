<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\View\ComponentPrefixes;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;

/*
 * Proposal 1. `ichava-core` is core's canonical Blade prefix and is always
 * registered; the short prefix (`<x-ichava::icon>` by default) is a configured
 * convenience beside it: `components.short_prefix`, validated against Blade's
 * own tag parser, or null to turn it off. `components.enabled` false registers
 * no core tag at all. Every test reads Blade's live maps, not the provider.
 *
 * This replaces the "deferred bare namespace" pins: the short form is no longer
 * a deferral but the configured default, and the 331 existing
 * `<x-ichava::icon>` call sites keep working unless a host turns it off.
 */

const ICHAVA_COMPONENTS = 'Simtabi\\Laranail\\Ichava\\View\\Components';

afterEach(function (): void {
    // Blade's maps live on a singleton that outlives the app between tests.
    $this->configOverrides = [];
});

function coreAliases(): array
{
    return array_filter(
        Blade::getClassComponentAliases(),
        static fn (string $class): bool => str_starts_with($class, ICHAVA_COMPONENTS . '\\'),
    );
}

it('registers the canonical and the short prefix by default, over the same class', function (): void {
    $aliases = Blade::getClassComponentAliases();
    $namespaces = Blade::getClassComponentNamespaces();

    expect($aliases['ichava-core::icon'] ?? null)->toBe(IconComponent::class)
        ->and($aliases['ichava::icon'] ?? null)->toBe(IconComponent::class)
        ->and($namespaces['ichava-core'] ?? null)->toBe(ICHAVA_COMPONENTS)
        ->and($namespaces['ichava'] ?? null)->toBe(ICHAVA_COMPONENTS)
        ->and(app(ComponentPrefixes::class)->registered())->toBe(['ichava-core', 'ichava']);
});

it('drops the short prefix when it is null, and keeps the canonical one', function (): void {
    $this->configOverrides = ['ichava.ichava-core.components.short_prefix' => null];
    $this->refreshApplication();

    $aliases = Blade::getClassComponentAliases();

    expect($aliases)->toHaveKey('ichava-core::icon')
        ->and($aliases)->not->toHaveKey('ichava::icon')
        ->and(Blade::getClassComponentNamespaces())->not->toHaveKey('ichava')
        ->and(fn () => Blade::render('<x-ichava::icon name="ichava/test-icons::check" />'))->toThrow(InvalidArgumentException::class, 'ichava::icon');
});

it('registers another short prefix in place of ichava', function (): void {
    $this->configOverrides = ['ichava.ichava-core.components.short_prefix' => 'icon'];
    $this->refreshApplication();

    expect(Blade::getClassComponentAliases())->toHaveKey('icon::icon')
        ->not->toHaveKey('ichava::icon');
});

it('refuses a short prefix Blade cannot parse, or one that is the canonical prefix', function (string $prefix): void {
    $this->configOverrides = ['ichava.ichava-core.components.short_prefix' => $prefix];

    expect(fn () => $this->refreshApplication())->toThrow(IchavaException::class, 'short_prefix');
})->with(['a/b', 'a::b', 'ichava-core', 'Bad Prefix']);

it('registers no core tag when components are disabled', function (): void {
    $this->configOverrides = ['ichava.ichava-core.components.enabled' => false];
    $this->refreshApplication();

    expect(coreAliases())->toBe([])
        ->and(Blade::getClassComponentNamespaces())->not->toHaveKey('ichava-core');
});

it('renders the same bytes through every registered prefix', function (): void {
    app(IconRegistry::class)->fromDirectory(dirname(__DIR__) . '/fixtures/KindPack');

    $short = Blade::render('<x-ichava::icon name="ichava-fixtures/kind-pack::glyphs/star" class="w-4" />');
    $canonical = Blade::render('<x-ichava-core::icon name="ichava-fixtures/kind-pack::glyphs/star" class="w-4" />');

    expect($short)->toContain('<svg')
        ->and($canonical)->toBe($short);
});

it('adds no unscoped alias beyond the configured short one', function (): void {
    $short = config('ichava.ichava-core.components.short_prefix');

    expect(coreAliases())->not->toBeEmpty();

    foreach (array_keys(coreAliases()) as $alias) {
        expect(str_starts_with($alias, 'ichava-core::') || ($short !== null && str_starts_with($alias, "{$short}::")))->toBeTrue("unscoped alias {$alias}");
    }
});

it('reports a short alias another provider has overwritten', function (): void {
    expect(app(ComponentPrefixes::class)->shadowedShortAlias())->toBeNull();

    Blade::component('ichava::icon', Illuminate\View\Component::class);

    expect(app(ComponentPrefixes::class)->shadowedShortAlias())->toBe(Illuminate\View\Component::class);
});

it('has doctor warn when the short alias is shadowed', function (): void {
    $status = function (): ?string {
        Illuminate\Support\Facades\Artisan::call('ichava::ichava-core.info', ['type' => 'doctor', '--format' => 'json']);
        $report = json_decode(Illuminate\Support\Facades\Artisan::output(), true);

        return collect($report['checks'] ?? [])->firstWhere('id', 'component.short_prefix_shadowed')['status'] ?? null;
    };

    expect($status())->toBe('ok');

    Blade::component('ichava::icon', Illuminate\View\Component::class);

    expect($status())->toBe('warn');
});
