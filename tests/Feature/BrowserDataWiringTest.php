<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Ichava\Ichava;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Ichava\Data\VariantData;
use Simtabi\Laranail\Ichava\Events\IconCacheEvent;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\ServiceProvider;
use Simtabi\Laranail\Ichava\Services\IconCacheService;
use Simtabi\Laranail\Ichava\Support\FtsLanguageHelper;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Drivers\DirectoryIconDriver;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;
use Simtabi\Laranail\Ichava\Constants\JsonConfigConstants;
use Simtabi\Laranail\Ichava\Services\ConfigurationService;

/*
 * Data the browser needs, produced by core: variant metadata, the default
 * pack, the tag/keyword/subcategory filters, filter-scoped facets and tree,
 * the status payload, and the two extension interfaces.
 */

const VARIANT_PACK = 'ichava-fixtures/variant-pack';

function fixturePack(string $dir): string
{
    return dirname(__DIR__) . '/fixtures/' . $dir;
}

final class VariantPackConstants extends JsonConfigConstants
{
    protected static function getConfigPath(): string
    {
        return fixturePack('VariantPack');
    }
}

final class KindFixtureProvider extends ServiceProvider
{
    public function configurePackage(Package $package): void {}
}

beforeEach(function (): void {
    app(IconBrowserService::class)->clearCache();
});

it('serves each pack’s variants in display order with their metadata', function (): void {
    app(IconRegistry::class)->fromDirectory(fixturePack('VariantPack'));
    app(IchavaSeeder::class)->seedPackage(VARIANT_PACK, fixturePack('VariantPack'), useQueue: false);

    $variants = app(IconBrowserService::class)->getFilters()['variants_by_package'][VARIANT_PACK];

    expect(array_column($variants, 'slug'))->toBe(['outline', 'filled'])
        ->and($variants[0])->toMatchArray([
            'label'       => 'Outline',
            'description' => 'Stroked shapes',
            'previewIcon' => 'outline/home',
            'colorScheme' => 'stroke-based',
            'default'     => true,
            'attributes'  => ['stroke_width' => 2, 'fill' => 'none'],
        ]);
});

it('lets a pack’s translated variant description win over config.json', function (): void {
    app('translator')->addLines(['icons.variant_descriptions.outline' => 'Traits fins'], 'en', VARIANT_PACK);
    app(IconRegistry::class)->fromDirectory(fixturePack('VariantPack'));
    app(IchavaSeeder::class)->seedPackage(VARIANT_PACK, fixturePack('VariantPack'), useQueue: false);

    $variants = app(IconBrowserService::class)->getFilters()['variants_by_package'][VARIANT_PACK];

    expect($variants[0]['description'])->toBe('Traits fins');
});

it('reads the variant getters through VariantData', function (): void {
    expect(VariantPackConstants::getVariantsSorted())->toBe(['outline', 'filled'])
        ->and(VariantPackConstants::getVariantData('filled'))->toBeInstanceOf(VariantData::class)
        ->and(VariantPackConstants::getVariantPreviewIcon('filled'))->toBe('filled/home')
        ->and(VariantPackConstants::getVariantColorScheme('outline'))->toBe('stroke-based')
        ->and(VariantPackConstants::getVariantName('missing'))->toBe('Missing');
});

it('marks the default pack', function (): void {
    test()->artisan('ichava::ichava-core.database', ['action' => 'seed', '--sync' => true])->run();

    $defaults = collect(app(IconBrowserService::class)->getFilters()['packages'])->where('default', true);

    expect($defaults->pluck('name')->all())->toBe([app(IconRegistry::class)->getDefault()]);
});

it('filters by tag, keyword and subcategory through the one filter path', function (): void {
    test()->artisan('ichava::ichava-core.database', ['action' => 'seed', '--sync' => true])->run();
    $browser = app(IconBrowserService::class);
    $all = $browser->getIcons(perPage: 500)->total();

    $tag = collect(Icon::first()?->tags)->first();
    $tagged = $browser->getIcons(['tags' => [$tag]], perPage: 500);

    expect($all)->toBeGreaterThan(1)
        ->and($tagged->total())->toBeGreaterThan(0)
        ->and(collect($tagged->items())->every(fn (Icon $icon): bool => in_array($tag, $icon->tags ?? [], true)))->toBeTrue()
        ->and($browser->getIcons(['keywords' => ['no-such-keyword-xyz']])->total())->toBe(0)
        ->and($browser->getIcons(['subs' => ['no-such-sub']])->total())->toBe(0);
});

it('scopes facet and tree counts to the active filters', function (): void {
    test()->artisan('ichava::ichava-core.database', ['action' => 'seed', '--sync' => true])->run();
    $browser = app(IconBrowserService::class);
    $one = Icon::first();

    $scoped = $browser->getFilters(['search' => $one?->name]);
    $kinds = collect($scoped['kinds'])->pluck('count', 'name');
    $tree = $browser->buildIconTree(['search' => $one?->name]);

    expect($kinds['icon'])->toBeLessThan(Icon::count())
        ->and($kinds['icon'])->toBeGreaterThan(0)
        ->and(array_sum(array_column($tree, 'icon_count')))->toBe($kinds['icon'])
        ->and(array_sum(array_column($browser->buildIconTree(), 'icon_count')))->toBe(Icon::count());
});

it('reports a parent category’s subtree total in the tree', function (): void {
    app(IconRegistry::class)->fromDirectory(fixturePack('PackConfigShapes/nested'));
    app(IchavaSeeder::class)->seedPackage('ichava-fixtures/nested-shape', fixturePack('PackConfigShapes/nested'), useQueue: false);

    $pack = collect(app(IconBrowserService::class)->buildIconTree())->firstWhere('pack', 'ichava-fixtures/nested-shape');
    $glyphs = collect($pack['cats'])->firstWhere('name', 'glyphs');

    expect($glyphs)->toHaveKey('total')
        ->and($glyphs['total'])->toBeGreaterThanOrEqual($glyphs['count']);
});

it('offers a search-language selector only on PostgreSQL, behind the guard', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        expect(FtsLanguageHelper::selectableLanguages())->toBe([]);

        return;
    }

    expect(FtsLanguageHelper::selectableLanguages())->toContain('simple', 'english')
        ->and(app(FtsLanguageHelper::class)->guardLanguage("english'); DROP TABLE ichava_icons; --"))->toBe('simple')
        ->and(app(FtsLanguageHelper::class)->guardLanguage('english'))->toBe('english');
});

it('reports status without server paths: orphans, last rebuild, logging', function (): void {
    Icon::create(['package' => 'gone/pack', 'name' => 'x', 'path' => 'files/x.svg']);
    event(IconCacheEvent::rebuilt(3, 2, 1, 12.5));

    $status = app(Ichava::class)->status();
    $json = json_encode($status->toArray());

    expect($status->orphans)->toBe(['gone/pack'])
        ->and($status->lastRebuild)->toMatchArray(['icon_count' => 3, 'build_time_ms' => 12.5])
        ->and($status->logging['channel'])->toBe('ichava')
        ->and($json)->not->toContain(dirname(__DIR__, 2));
});

it('prints health on info status and checks the install with doctor', function (): void {
    test()->artisan('ichava::ichava-core.info', ['type' => 'status'])
        ->expectsOutputToContain('Health')
        ->assertSuccessful();

    test()->artisan('ichava::ichava-core.info', ['type' => 'doctor'])
        ->expectsOutputToContain('Icon packs registered')
        ->assertSuccessful();

    config()->set('ichava.ichava-core.queue.connection', 'not-a-connection');

    test()->artisan('ichava::ichava-core.info', ['type' => 'doctor'])->assertFailed();
});

it('rescans one pack on watch --package', function (): void {
    test()->artisan('ichava::ichava-core.watch', ['--package' => 'ichava/test-icons'])
        ->expectsOutputToContain('Rescanned ichava/test-icons')
        ->assertSuccessful();

    expect(Icon::where('package', 'ichava/test-icons')->count())->toBeGreaterThan(0);

    test()->artisan('ichava::ichava-core.watch', ['--package' => 'not/a-pack'])->assertFailed();
});

it('forgets every pack’s cached config.json on clearAll', function (): void {
    $path = app(IconRegistry::class)->get('ichava/test-icons')['base_path'];
    app(ConfigurationService::class)->rememberPackageConfig($path);

    expect(app(IconCacheService::class)->clearAll()['configs'])->toBeGreaterThanOrEqual(1);
});

it('throws on conflicts in strict mode, and only then', function (): void {
    $registry = app(IconRegistry::class);
    $registry->fromDirectory(fixturePack('KindPack'));
    $clash = fn () => $registry->registerIconSet('ichava-fixtures/clash', $registry->set('ichava-fixtures/kind-pack'), [
        'package_name' => 'ichava-fixtures/clash', 'icon_set_name' => 'ichava-fixtures/clash',
        'base_path'    => fixturePack('KindPack'), 'prefix' => 'kindfix',
    ]);

    config()->set('ichava.ichava-core.registry.strict_conflicts', true);
    expect($clash)->toThrow(IchavaException::class, 'prefix conflict');

    config()->set('ichava.ichava-core.registry.strict_conflicts', false);
    $clash();
    expect($registry->getConflicts()['prefix'])->toHaveKey('kindfix');
});

it('implements IchavaPackageInterface on the pack base provider, through the registry', function (): void {
    app(IconRegistry::class)->fromDirectory(fixturePack('KindPack'), KindFixtureProvider::class);
    $provider = new KindFixtureProvider(app());

    expect($provider->getPackageName())->toBe('ichava-fixtures/kind-pack')
        ->and($provider->getPackageVersion())->toBe('dev')
        ->and($provider->getIconSetName())->toBe('ichava-fixtures/kind-pack')
        ->and($provider->getPackageMetadata()['kind'])->toBe('icon');
});

it('implements IconDriverInterface over a directory', function (): void {
    $driver = app(DirectoryIconDriver::class)->setIconPath(fixturePack('KindPack/files'));

    expect($driver->all())->toContain('glyphs/star', 'scenes/park')
        ->and($driver->has('glyphs/star'))->toBeTrue()
        ->and($driver->has('../config'))->toBeFalse()
        ->and($driver->render('glyphs/star'))->toContain('<svg')
        ->and(fn () => $driver->render('glyphs/missing'))->toThrow(IchavaException::class);
});
