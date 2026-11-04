<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Enums\KindSource;
use Simtabi\Laranail\Ichava\Data\KindResolution;
use Simtabi\Laranail\Ichava\Facades\IchavaFacade;
use Simtabi\Laranail\Ichava\Support\KindResolver;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\IconClassifier;
use Simtabi\Laranail\Ichava\Support\PackConfigData;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;
use Simtabi\Laranail\Ichava\Services\IconWatcherService;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;

/*
 * Kind resolution, contract §1–2 and §4: every D6 row, the precedence chain,
 * provenance on the stored row, and the mixed pack end to end.
 *
 * The mixed pack is the contract's §1.4 example. Its table is asserted
 * verbatim below, so the example in the contract cannot drift from the code.
 */
const MIXED_PACK = 'ichava-fixtures/mixed-pack';
const LEGACY_PACK = 'ichava-fixtures/variant-pack';

function fixturePath(string $name): string
{
    return dirname(__DIR__) . '/fixtures/' . $name;
}

function seedFixture(string $package, string $directory, bool $force = false): void
{
    $result = app(IchavaSeeder::class)->seedPackage($package, fixturePath($directory), useQueue: false, force: $force);

    expect($result['error'])->toBeNull();
}

function resolveKind(string $package, string $itemPath): KindResolution
{
    return app(KindResolver::class)->resolve($package, $itemPath);
}

/** @return array<string, array{kind: string, source: string, rule: string|null}> */
function storedResolutions(string $package): array
{
    $rows = [];

    foreach (Icon::where('package', $package)->get() as $icon) {
        $rows[KindResolver::itemPath($icon->path)] = [
            'kind'   => $icon->kind->value,
            'source' => $icon->kindSource()->value,
            'rule'   => $icon->kind_rule,
        ];
    }

    ksort($rows);

    return $rows;
}

beforeEach(function (): void {
    config()->set('ichava.ichava-core.kinds.overrides', []);
    app(IconRegistry::class)->fromDirectory(fixturePath('MixedKindPack'));
});

it('resolves the contract §1.4 example table', function (): void {
    $table = [
        'glyphs/hero-banner'   => [IconKind::Illustration, KindSource::Item, null],
        'glyphs/home'          => [IconKind::Icon, KindSource::Pack, null],
        'spot/tiny-cat'        => [IconKind::Icon, KindSource::Rule, 'spot/tiny-*'],
        'spot/big/park'        => [IconKind::Illustration, KindSource::Rule, 'spot/**'],
        'illustrations/office' => [IconKind::Illustration, KindSource::Category, 'illustrations'],
    ];

    foreach ($table as $path => [$kind, $source, $rule]) {
        $resolution = resolveKind(MIXED_PACK, $path);

        expect($resolution->kind)->toBe($kind, $path)
            ->and($resolution->source)->toBe($source, $path)
            ->and($resolution->rule)->toBe($rule, $path);
    }
});

it('numbers the sources in row order', function (): void {
    expect(array_map(static fn (KindSource $s): int => $s->row(), KindSource::cases()))->toBe([1, 2, 3, 4, 5, 6])
        ->and(array_map(static fn (KindSource $s): string => $s->value, KindSource::cases()))
        ->toBe(['host', 'item', 'rule', 'category', 'pack', 'default']);
});

it('row 1: a host override decides, by exact key or glob', function (): void {
    config()->set('ichava.ichava-core.kinds.overrides', [
        MIXED_PACK . '::glyphs/home' => 'illustration',
        '*::spot/**'                 => 'icon',
    ]);

    expect(resolveKind(MIXED_PACK, 'glyphs/home'))
        ->toEqual(new KindResolution(IconKind::Illustration, KindSource::Host, MIXED_PACK . '::glyphs/home'))
        ->and(resolveKind(MIXED_PACK, 'spot/big/park'))
        ->toEqual(new KindResolution(IconKind::Icon, KindSource::Host, '*::spot/**'));
});

it('row 1: an exact host key beats a glob host key declared before it', function (): void {
    config()->set('ichava.ichava-core.kinds.overrides', [
        MIXED_PACK . '::glyphs/**'   => 'icon',
        MIXED_PACK . '::glyphs/home' => 'illustration',
    ]);

    expect(resolveKind(MIXED_PACK, 'glyphs/home')->rule)->toBe(MIXED_PACK . '::glyphs/home')
        ->and(resolveKind(MIXED_PACK, 'glyphs/hero-banner')->rule)->toBe(MIXED_PACK . '::glyphs/**');
});

it('row 1: glob host keys apply in declared order, and invalid keys are ignored', function (): void {
    config()->set('ichava.ichava-core.kinds.overrides', [
        'no-separator'             => 'illustration',
        MIXED_PACK . '::[bad]'     => 'illustration',
        MIXED_PACK . '::glyphs/*'  => 'sticker',
        MIXED_PACK . '::glyphs/h*' => 'illustration',
        MIXED_PACK . '::glyphs/**' => 'icon',
        'other/pack::glyphs/home'  => 'illustration',
    ]);

    expect(resolveKind(MIXED_PACK, 'glyphs/home')->rule)->toBe(MIXED_PACK . '::glyphs/h*');
});

it('row 2: an item key decides', function (): void {
    expect(resolveKind(MIXED_PACK, 'glyphs/hero-banner'))
        ->toEqual(new KindResolution(IconKind::Illustration, KindSource::Item, null));
});

it('row 3: the first matching rule wins, in array order', function (): void {
    expect(resolveKind(MIXED_PACK, 'spot/tiny-cat')->rule)->toBe('spot/tiny-*')
        ->and(resolveKind(MIXED_PACK, 'spot/tiny-dog/deep')->rule)->toBe('spot/**');
});

it('row 4: the category decides', function (): void {
    expect(resolveKind(MIXED_PACK, 'illustrations/office'))
        ->toEqual(new KindResolution(IconKind::Illustration, KindSource::Category, 'illustrations'));
});

it('row 5: the pack decides', function (): void {
    expect(resolveKind(MIXED_PACK, 'glyphs/home'))
        ->toEqual(new KindResolution(IconKind::Icon, KindSource::Pack, null));
});

it('row 6: the default decides for a pack that declares nothing, or none at all', function (): void {
    app(IconRegistry::class)->fromDirectory(fixturePath('VariantPack'));

    expect(resolveKind(LEGACY_PACK, 'outline/home'))
        ->toEqual(new KindResolution(IconKind::Icon, KindSource::Default, null))
        ->and(resolveKind('ichava-fixtures/not-registered', 'a/b'))
        ->toEqual(new KindResolution(IconKind::Icon, KindSource::Default, null));
});

it('chains every row on one item: 1 > 2 > 3 > 4 > 5 > 6', function (): void {
    $registry = app(IconRegistry::class);
    $package = 'ichava-fixtures/chain-pack';
    $path = 'illustrations/hero';

    $registry->registerIconSet($package, $registry->set(MIXED_PACK), [
        'package_name'   => $package,
        'icon_set_name'  => $package,
        'base_path'      => fixturePath('MixedKindPack'),
        'kind'           => 'illustration',
        'category_kinds' => ['illustrations' => 'icon'],
        'kind_rules'     => [['match' => 'illustrations/*', 'kind' => 'illustration']],
        'item_kinds'     => [$path => 'icon'],
    ]);
    config()->set('ichava.ichava-core.kinds.overrides', [$package . '::' . $path => 'illustration']);

    $seen = [resolveKind($package, $path)->source];

    config()->set('ichava.ichava-core.kinds.overrides', []);
    $seen[] = resolveKind($package, $path)->source;

    $strip = function (string $key) use ($registry, $package): void {
        $metadata = $registry->get($package);
        unset($metadata[$key]);
        $registry->unregister($package);
        $registry->registerIconSet($package, $registry->set(MIXED_PACK), $metadata);
    };

    $strip('item_kinds');
    $seen[] = resolveKind($package, $path)->source;
    $strip('kind_rules');
    $seen[] = resolveKind($package, $path)->source;
    $strip('category_kinds');
    $seen[] = resolveKind($package, $path)->source;
    $strip('kind');
    $seen[] = resolveKind($package, $path)->source;

    expect($seen)->toBe(KindSource::cases());
});

it('reads the new pack keys from the documented shape, and the top level as a fallback', function (): void {
    $rules = [['match' => 'a/**', 'kind' => 'illustration'], ['match' => 'a**', 'kind' => 'icon'], ['match' => 'b/*', 'kind' => 'nope'], ['kind' => 'icon']];
    $items = ['a/x' => ['kind' => 'Illustration '], 'a/y' => ['kind' => 'sticker'], 'a/z' => 'icon'];

    $documented = ['metadata' => ['data' => ['kinds' => ['rules' => $rules], 'items' => $items]]];
    $legacy = ['kinds' => ['rules' => $rules], 'items' => $items];

    foreach ([$documented, $legacy] as $config) {
        expect(PackConfigData::kindRules($config))->toBe([['match' => 'a/**', 'kind' => IconKind::Illustration]])
            ->and(PackConfigData::items($config))->toBe(['a/x' => IconKind::Illustration]);
    }

    expect(PackConfigData::kindRules([]))->toBe([])
        ->and(PackConfigData::items([]))->toBe([]);
});

it('stores provenance on every seeded row of the mixed pack', function (): void {
    seedFixture(MIXED_PACK, 'MixedKindPack');

    expect(storedResolutions(MIXED_PACK))->toBe([
        'glyphs/hero-banner'   => ['kind' => 'illustration', 'source' => 'item', 'rule' => null],
        'glyphs/home'          => ['kind' => 'icon', 'source' => 'pack', 'rule' => null],
        'illustrations/office' => ['kind' => 'illustration', 'source' => 'category', 'rule' => 'illustrations'],
        'spot/big/park'        => ['kind' => 'illustration', 'source' => 'rule', 'rule' => 'spot/**'],
        'spot/tiny-cat'        => ['kind' => 'icon', 'source' => 'rule', 'rule' => 'spot/tiny-*'],
    ])
        // The twin is not an item: it rides on its light file.
        ->and(Icon::where('package', MIXED_PACK)->where('name', 'office')->sole()->themes())->toBe(['dark']);
});

it('reports the mixed pack per kind in statistics, the package facet and about', function (): void {
    seedFixture(MIXED_PACK, 'MixedKindPack');
    $browser = app(IconBrowserService::class);
    $browser->clearCache();

    $statistics = $browser->getStatistics();
    $package = collect($browser->getFilters()['packages'])->firstWhere('name', MIXED_PACK);
    $scoped = collect($browser->getFilters(['kind' => 'illustration'])['packages'])->firstWhere('name', MIXED_PACK);

    expect($statistics['by_kind'])->toBe(['icon' => 2, 'illustration' => 3])
        ->and($statistics['by_package_kind'][MIXED_PACK])->toBe(['icon' => 2, 'illustration' => 3])
        ->and($package['by_kind'])->toBe(['icon' => 2, 'illustration' => 3])
        ->and($scoped['by_kind'])->toBe(['icon' => 0, 'illustration' => 3]);

    Artisan::call('about');
    $about = Artisan::output();

    expect($about)->toMatch('/Icons by kind\s*\.*\s*icon 2 · illustration 3/')
        ->and($about)->toMatch('/Default kind\s*\.*\s*icon/')
        ->and($about)->toMatch('/Kind sources\s*\.*\s*item 1 · rule 2 · category 1 · pack 1/')
        ->and($about)->toMatch('/Kind overrides\s*\.*\s*0/');
});

it('sends an empty by_package_kind when nothing is seeded', function (): void {
    $browser = app(IconBrowserService::class);
    $browser->clearCache();

    expect($browser->getStatistics()['by_package_kind'])->toBe([]);
});

it('filters by several kinds with OR, and adds kind_source to every icon', function (): void {
    seedFixture(MIXED_PACK, 'MixedKindPack');
    $browser = app(IconBrowserService::class);

    $both = $browser->getIcons(['kind' => ['icon', 'illustration', 'icon'], 'packages' => [MIXED_PACK]]);
    $one = $browser->getIcons(['kind' => ['illustration'], 'packages' => [MIXED_PACK]]);
    $bad = $browser->getIcons(['kind' => ['animation'], 'packages' => [MIXED_PACK]]);
    $home = $browser->transformIcon(Icon::where('package', MIXED_PACK)->where('name', 'home')->sole());

    expect($both->total())->toBe(5)
        ->and($one->total())->toBe(3)
        ->and($bad->total())->toBe(0)
        ->and($home['kind_source'])->toBe('pack')
        ->and($home)->not->toHaveKey('kind_rule');
});

it('seeds a v0.1 manifest with no kind keys as icons from the default row', function (): void {
    app(IconRegistry::class)->fromDirectory(fixturePath('VariantPack'));
    seedFixture(LEGACY_PACK, 'VariantPack');

    $rows = storedResolutions(LEGACY_PACK);

    expect($rows)->not->toBeEmpty()
        ->and(array_unique(array_column($rows, 'kind')))->toBe(['icon'])
        ->and(array_unique(array_column($rows, 'source')))->toBe(['default'])
        ->and(array_unique(array_column($rows, 'rule')))->toBe([null]);
});

it('re-files an unchanged file when only its provenance changed, without --force', function (): void {
    seedFixture(MIXED_PACK, 'MixedKindPack');

    Icon::where('package', MIXED_PACK)->update(['kind_source' => 'default', 'kind_rule' => null]);
    seedFixture(MIXED_PACK, 'MixedKindPack');

    expect(storedResolutions(MIXED_PACK)['spot/big/park'])->toBe(['kind' => 'illustration', 'source' => 'rule', 'rule' => 'spot/**']);
});

it('writes the same provenance from the file watcher', function (): void {
    app(IconWatcherService::class)->forceScan();

    expect(storedResolutions(MIXED_PACK)['spot/tiny-cat'])->toBe(['kind' => 'icon', 'source' => 'rule', 'rule' => 'spot/tiny-*'])
        ->and(storedResolutions(MIXED_PACK)['illustrations/office']['source'])->toBe('category');
});

it('reports kind.drift after an override change, and clears it after a re-seed', function (): void {
    seedFixture(MIXED_PACK, 'MixedKindPack');

    $drift = function (): array {
        Artisan::call('ichava::ichava-core.info', ['type' => 'doctor', '--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return collect($report['checks'])->firstWhere('id', 'kind.drift');
    };

    expect($drift()['status'])->toBe('ok')
        ->and($drift()['count'])->toBe(0);

    config()->set('ichava.ichava-core.kinds.overrides', [MIXED_PACK . '::glyphs/home' => 'illustration']);

    $red = $drift();
    expect($red['status'])->toBe('warn')
        ->and($red['count'])->toBe(1)
        ->and(json_encode($red['detail']))->toContain('ichava::ichava-core.database seed');

    seedFixture(MIXED_PACK, 'MixedKindPack');

    expect($drift()['count'])->toBe(0)
        ->and(storedResolutions(MIXED_PACK)['glyphs/home'])->toBe(['kind' => 'illustration', 'source' => 'host', 'rule' => MIXED_PACK . '::glyphs/home']);
});

it('answers kind through the facade, the registry and the classifier alike', function (): void {
    config()->set('ichava.ichava-core.kinds.overrides', [MIXED_PACK . '::glyphs/home' => 'illustration']);

    $classifier = new IconClassifier(app(IconRegistry::class));

    expect(IchavaFacade::kindOf(MIXED_PACK . '::spot/big/park'))->toBe(IconKind::Illustration)
        ->and(IchavaFacade::kindResolution(MIXED_PACK . '::spot.tiny-cat'))
        ->toEqual(new KindResolution(IconKind::Icon, KindSource::Rule, 'spot/tiny-*'))
        ->and(app(IconRegistry::class)->kindOf(MIXED_PACK . '::glyphs/home'))->toBe(IconKind::Illustration)
        ->and($classifier->kind(MIXED_PACK, 'files/glyphs/hero-banner.svg'))->toBe(IconKind::Illustration)
        ->and($classifier->resolution(MIXED_PACK, 'files/spot/big/park.svg')->rule)->toBe('spot/**')
        // kindFor() keeps its rows 4–6 contract for its callers.
        ->and(app(IconRegistry::class)->kindFor(MIXED_PACK, 'glyphs'))->toBe(IconKind::Icon)
        ->and(IchavaFacade::kindOf('not a valid id'))->toBe(IconKind::Icon);
});

it('derives the item path from a stored path', function (): void {
    expect(KindResolver::itemPath('files/illustrations/office.svg'))->toBe('illustrations/office')
        ->and(KindResolver::itemPath('tabler/files/outline/home.svg'))->toBe('outline/home')
        ->and(KindResolver::itemPath('home.svg'))->toBe('home')
        ->and(KindResolver::itemPath('files\\a\\b.SVG'))->toBe('a/b');
});
