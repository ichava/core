<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Tests\Fixtures\TranslatedPack\Providers\IconsServiceProvider;

/*
|--------------------------------------------------------------------------
| Pack translations are registered by default
|--------------------------------------------------------------------------
|
| `package-tools` defaults `hasTranslations` to false, and no pack in this
| family ever called it. Every `resources/lang` file shipped unreachable --
| which is why one pack could carry another pack's translations, and another
| could state the wrong licence, without a single test going red.
|
| These assertions read the LIVE translator, not the provider source. Grepping
| the provider proves how registration was written; only the loader's own
| namespace map proves what the framework ended up holding, and the whole
| mechanism turns on a default applied in newPackage() rather than on a call
| anyone can see at the call site.
|
*/

beforeEach(function () {
    $this->app->register(IconsServiceProvider::class);
});

it('registers the pack translation namespace in the live loader', function () {
    $namespaces = Lang::getLoader()->namespaces();

    expect($namespaces)->toHaveKey('ichava/fixture-pack');
    expect($namespaces['ichava/fixture-pack'])
        ->toEndWith('TranslatedPack/resources/lang');
});

it('resolves a real translation line through that namespace', function () {
    // The proof that the namespace is wired, not merely present: a miss would
    // hand the key straight back.
    expect(__('ichava/fixture-pack::icons.variants.solid'))->toBe('Solid');
});

it('does so without the pack opting in', function () {
    // The fixture's configurePackage() sets a name and a path and nothing else,
    // so the default applied in newPackage() is what wired this up. Read the
    // flag off the built Package rather than grepping the provider: the flag is
    // what the framework acted on, the source is only how it was written.
    $provider = app()->getProvider(IconsServiceProvider::class);
    $package = (new ReflectionProperty($provider, 'package'))->getValue($provider);

    expect($package->hasTranslations)->toBeTrue();
});

it('keeps config.json canonical for english name and description', function () {
    $metadata = app(IconRegistry::class)->all()['ichava/fixture-pack'];

    // The English lang file deliberately defines neither, so both fall through
    // to config.json. This is the drift fix: one source in English.
    expect($metadata['name'])->toBe('Fixture Pack');
    expect($metadata['description'])->toBe('Canonical description, from config.json');
});

it('lets another locale override name and description', function () {
    // No re-registration: the overlay is applied when the metadata is read, so
    // switching locale is enough. Resolving at registration time would have
    // frozen whichever locale was active during boot.
    App::setLocale('fr');

    $metadata = app(IconRegistry::class)->all()['ichava/fixture-pack'];

    expect($metadata['name'])->toBe('Pack de démonstration');
    expect($metadata['description'])->toBe('Description traduite, depuis les traductions');
});

it('exposes localised taxonomy labels, which config.json has no equivalent for', function () {
    $metadata = app(IconRegistry::class)->all()['ichava/fixture-pack'];

    expect($metadata['labels'])->toBe(['variants' => ['solid' => 'Solid']]);
});

it('omits label groups the pack does not define', function () {
    // A pack with variants must not grow an empty `categories` key, so packs
    // with different taxonomies can share one code path.
    $metadata = app(IconRegistry::class)->all()['ichava/fixture-pack'];

    expect($metadata['labels'])->not->toHaveKey('categories')
        ->and($metadata['labels'])->not->toHaveKey('sets');
});
