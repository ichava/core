<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;
use Simtabi\Laranail\Ichava\Tests\Fixtures\ViewfulPack\Providers\IconsServiceProvider as ViewfulPack;
use Simtabi\Laranail\Ichava\Tests\Fixtures\TranslatedPack\Providers\IconsServiceProvider as EmptyViewsPack;

/*
|--------------------------------------------------------------------------
| One registration mechanism, for every resource type
|--------------------------------------------------------------------------
|
| Support\ServiceProvider hand-rolled the directory check for translations
| alone, so views and configs were never covered. package-tools already ships
| loadAllResources() for exactly this, covering six resource types.
|
| Two things make the substitution non-obvious, and both are asserted here.
|
| 1. loadAllResources() resolves paths from the PACKAGE, and Package::$basePath
|    is '' until registerPackage() calls setPathFrom() -- on the line AFTER
|    newPackage() returns. Called naively it would test "/resources/views",
|    find nothing, register nothing, and return fluently. No exception, no
|    warning: a green build over a feature that does not exist.
|
| 2. Upstream's autoLoadViews() registers on directory presence. All five real
|    packs ship resources/views/components/.gitkeep and zero templates, so
|    presence would register five namespaces that resolve nothing -- which is
|    how a later reader concludes views are broken. Ichava diverges: at least
|    one *.blade.php, or no namespace.
|
| Every assertion reads the live registry, never the provider source.
|
*/

it('registers a view namespace for a pack that ships a template', function () {
    $this->app->register(ViewfulPack::class);

    expect(array_keys(View::getFinder()->getHints()))->toContain('ichava/viewful-pack')
        ->and(View::exists('ichava/viewful-pack::components.badge'))->toBeTrue();
});

it('registers no view namespace for a pack whose views directory is empty', function () {
    // The shape of all five real packs: the directory is a deliberate
    // placeholder, kept by an explicit decision. A namespace resolving nothing
    // is worse than no namespace.
    $this->app->register(EmptyViewsPack::class);

    expect(array_keys(View::getFinder()->getHints()))->not->toContain('ichava/fixture-pack');
});

it('merges a pack config without the pack declaring it', function () {
    $this->app->register(ViewfulPack::class);

    expect(config('ichava.viewful-pack.marker'))->toBe('from-viewful-config');
});

it('still registers translations, which is what this replaced', function () {
    $this->app->register(ViewfulPack::class);

    expect(Lang::getLoader()->namespaces())->toHaveKey('ichava/viewful-pack')
        ->and(__('ichava/viewful-pack::icons.variants.solid'))->toBe('Solid');
});

it('resolves the package base path before auto-registration runs', function () {
    // The B8 guard, stated directly rather than inferred from the three above.
    // If the priming line is ever tidied away, every path check silently tests
    // "/resources/..." and this is the assertion that says so.
    $this->app->register(ViewfulPack::class);

    $provider = app()->getProvider(ViewfulPack::class);
    $package = (new ReflectionProperty($provider, 'package'))->getValue($provider);

    expect($package->basePath)->not->toBe('')
        ->and($package->hasViews)->toBeTrue()
        ->and($package->hasConfigs)->toBeTrue()
        ->and($package->hasTranslations)->toBeTrue();
});
