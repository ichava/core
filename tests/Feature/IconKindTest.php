<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;
use Simtabi\Laranail\Ichava\Services\IconWatcherService;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;

/*
 * SVG kind, end to end: what a pack declares in config.json reaches the row the
 * seeder writes, the query surface, the facets and the renderer.
 *
 * The fixture pack declares `package.kind: icon` and one category,
 * `scenes`, as `illustration` with a `-dark` theme twin. `glyphs` declares
 * nothing and so inherits the pack's kind. `moon-dark.svg` carries the twin
 * suffix but has no `moon.svg` to pair with, so it must stay an item.
 */
const KIND_PACK = 'ichava-fixtures/kind-pack';

function kindPackPath(): string
{
    return dirname(__DIR__) . '/fixtures/KindPack';
}

function seedKindPack(bool $force = false): void
{
    $result = app(IchavaSeeder::class)->seedPackage(KIND_PACK, kindPackPath(), useQueue: false, force: $force);

    expect($result['error'])->toBeNull();
}

beforeEach(function (): void {
    app(IconRegistry::class)->fromDirectory(kindPackPath());
});

it('resolves kind per category, then per pack, then icon', function (): void {
    $registry = app(IconRegistry::class);

    expect($registry->kindFor(KIND_PACK, 'scenes'))->toBe(IconKind::Illustration)
        ->and($registry->kindFor(KIND_PACK, 'glyphs'))->toBe(IconKind::Icon)
        ->and($registry->kindFor(KIND_PACK))->toBe(IconKind::Icon)
        ->and($registry->kindFor('ichava-fixtures/not-registered', 'scenes'))->toBe(IconKind::Icon)
        ->and($registry->kindOf(KIND_PACK . '::scenes/park'))->toBe(IconKind::Illustration)
        ->and($registry->kindOf(KIND_PACK . '::glyphs/star'))->toBe(IconKind::Icon);
});

it('writes the kind the pack declares on every seeded row', function (): void {
    seedKindPack();

    $kinds = Icon::where('package', KIND_PACK)->pluck('kind', 'name')->map(fn (IconKind $k): string => $k->value)->all();
    ksort($kinds);

    expect($kinds)->toBe([
        'moon-dark' => 'illustration',
        'park'      => 'illustration',
        'star'      => 'icon',
    ]);
});

it('records a declared dark twin on its light file instead of as an item', function (): void {
    seedKindPack();

    $park = Icon::where('package', KIND_PACK)->where('name', 'park')->sole();

    expect(Icon::where('package', KIND_PACK)->where('name', 'park-dark')->exists())->toBeFalse()
        ->and($park->themes())->toBe(['dark'])
        ->and($park->theme_variants['dark']['path'])->toBe('files/scenes/park-dark.svg')
        ->and($park->themeSvgContent('dark'))->toContain('#023047')
        ->and($park->themeSvgContent('sepia'))->toBeNull()
        // An unpaired suffixed file is still an asset: nothing to pair it with.
        ->and(Icon::where('package', KIND_PACK)->where('name', 'moon-dark')->exists())->toBeTrue();
});

it('re-files unchanged files when the kind changes, without --force', function (): void {
    seedKindPack();

    // Rows seeded before the pack declared its illustrations: same bytes,
    // same twins, only the kind is stale.
    Icon::where('package', KIND_PACK)->update(['kind' => 'icon']);

    seedKindPack();

    expect(Icon::where('package', KIND_PACK)->where('name', 'park')->value('kind'))->toBe(IconKind::Illustration);
});

it('drops a twin seeded as an item before its category declared twins', function (): void {
    seedKindPack();

    Icon::where('package', KIND_PACK)->update(['theme_variants' => null]);
    Icon::create([
        'package'   => KIND_PACK, 'name' => 'park-dark', 'path' => 'files/scenes/park-dark.svg',
        'file_hash' => md5_file(kindPackPath() . '/files/scenes/park-dark.svg'),
    ]);

    seedKindPack();

    expect(Icon::where('package', KIND_PACK)->where('name', 'park')->sole()->themes())->toBe(['dark'])
        ->and(Icon::where('package', KIND_PACK)->where('name', 'park-dark')->exists())->toBeFalse();
});

it('filters, counts and reports by kind on the query surface', function (): void {
    seedKindPack();
    app(IconBrowserService::class)->clearCache();

    $browser = app(IconBrowserService::class);

    $illustrations = $browser->getIcons(['kind' => 'illustration', 'packages' => [KIND_PACK]]);
    $nonsense = $browser->getIcons(['kind' => 'animation', 'packages' => [KIND_PACK]]);
    $kinds = collect($browser->getFilters()['kinds'])->keyBy('name');
    $byKind = $browser->getStatistics()['by_kind'];

    expect(collect($illustrations->items())->pluck('name')->sort()->values()->all())->toBe(['moon-dark', 'park'])
        ->and($nonsense->total())->toBe(0)
        ->and(Icon::query()->ofKind(IconKind::Illustration)->where('package', KIND_PACK)->count())->toBe(2)
        ->and($kinds['illustration']['count'])->toBe(2)
        ->and($kinds['illustration']['label'])->toBe('Illustrations')
        ->and($byKind['illustration'])->toBe(2)
        ->and($browser->transformIcon(Icon::where('name', 'park')->sole())['kind'])->toBe('illustration');

    $scenes = collect(collect($browser->buildIconTree())->firstWhere('pack', KIND_PACK)['cats'])->firstWhere('name', 'scenes');
    expect($scenes['kind'])->toBe('illustration');
});

it('keeps the kind facet complete when nothing is seeded', function (): void {
    $kinds = collect(app(IconBrowserService::class)->getFilters()['kinds'])->pluck('count', 'name')->all();

    expect($kinds)->toBe(['icon' => 0, 'illustration' => 0]);
});

it('files watcher-synced icons by kind and skips declared twins', function (): void {
    app(IconWatcherService::class)->forceScan();

    expect(Icon::where('package', KIND_PACK)->where('name', 'park')->value('kind'))->toBe(IconKind::Illustration)
        ->and(Icon::where('package', KIND_PACK)->where('name', 'park-dark')->exists())->toBeFalse();
});

it('lets an illustration be larger than the icon limit, and only an illustration', function (): void {
    config()->set('ichava.ichava-core.max_file_size', 200);
    config()->set('ichava.ichava-core.kinds.max_file_size.illustration', 10_000);

    $registry = app(IconRegistry::class);

    expect(File::size(kindPackPath() . '/files/scenes/park.svg'))->toBeGreaterThan(200)
        ->and($registry->render(KIND_PACK . '::scenes/park'))->toContain('<svg');

    config()->set('ichava.ichava-core.kinds.max_file_size.illustration', 200);

    expect(fn () => $registry->render(KIND_PACK . '::scenes/park'))->toThrow(Exception::class);
});

it('does not square an illustration a size preset is applied to', function (): void {
    $render = function (string $name, ?bool $lock = null): string {
        $component = new IconComponent(
            name: $name,
            size: 'lg',
            lockAspectRatio: $lock,
            iconRegistry: app(IconRegistry::class),
            svgProcessor: app(SvgProcessingService::class),
        );

        return $component->render()->toHtml();
    };

    $illustration = $render(KIND_PACK . '::scenes/park');
    $icon = $render(KIND_PACK . '::glyphs/star');

    expect($illustration)->toMatch('/width="\d+px"/')
        ->and($illustration)->not->toMatch('/height="\d+px"/')
        ->and($icon)->toMatch('/height="\d+px"/')
        // An explicit choice still wins over the kind.
        ->and($render(KIND_PACK . '::scenes/park', true))->toMatch('/height="\d+px"/');
});

it('keeps every colour and gradient of a multicolour illustration', function (): void {
    seedKindPack();

    $svg = Icon::where('package', KIND_PACK)->where('name', 'park')->sole()->svg_content;

    foreach (['#8ecae6', '#ffffff', '#ffb703', '#2a9d8f', '#6d4c41', '#52b788'] as $colour) {
        expect($svg)->toContain($colour);
    }

    expect($svg)->toContain('linearGradient')
        ->and($svg)->toContain('viewBox="0 0 400 200"')
        ->and($svg)->not->toContain('currentColor');
});

it('keeps a multicolour illustration intact under the monochrome conversion', function (): void {
    config()->set('ichava.ichava-core.svg.monochrome_to_current_color', true);
    seedKindPack();

    $svg = Icon::where('package', KIND_PACK)->where('name', 'park')->sole()->svg_content;

    expect($svg)->toContain('#ffb703')->and($svg)->toContain('#2a9d8f');
});

it('adds the kind column with icon as the backfill', function (): void {
    DB::table(Icon::TABLE)->insert([
        'package'    => 'legacy/pack', 'name' => 'old', 'path' => 'files/old.svg',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table(Icon::TABLE)->where('name', 'old')->value('kind'))->toBe('icon');
});
