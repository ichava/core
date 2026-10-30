<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;

/*
 * An icon's subcategory (`sub`): the nested category it sits in, below its
 * top-level one. `subs[]` already filters on it; the grid needs it on each
 * icon so a consumer can show it. `getIcons()` loads the terms' `parent_id`,
 * which is what tells a nested category from a top-level one.
 */

const NESTED_PACK = 'ichava-fixtures/nested-shape';

beforeEach(function (): void {
    $dir = dirname(__DIR__) . '/fixtures/PackConfigShapes/nested';
    app(IconRegistry::class)->fromDirectory($dir);
    app(IchavaSeeder::class)->seedPackage(NESTED_PACK, $dir, useQueue: false);
    app(IconBrowserService::class)->clearCache();
});

it('loads each term’s parent with the grid page', function (): void {
    $page = app(IconBrowserService::class)->getIcons(['packages' => [NESTED_PACK]], perPage: 100);

    expect($page->total())->toBeGreaterThan(0);

    foreach ($page->items() as $icon) {
        foreach ($icon->terms as $term) {
            $this->assertArrayHasKey('parent_id', $term->getAttributes(), "term {$term->slug} was loaded without parent_id");
        }
    }
});

it('reports the nested category as sub, and null at the top level', function (): void {
    $browser = app(IconBrowserService::class);
    $page = collect($browser->getIcons(['packages' => [NESTED_PACK]], perPage: 100)->items());

    $nested = $page->first(fn (Icon $icon): bool => $icon->terms->contains(
        fn (IconTerm $t): bool => $t->type === IconTerm::TYPE_CATEGORY && $t->parent_id !== null,
    ));
    $flat = $page->first(fn (Icon $icon): bool => ! $icon->terms->contains(
        fn (IconTerm $t): bool => $t->type === IconTerm::TYPE_CATEGORY && $t->parent_id !== null,
    ));

    expect($nested)->not->toBeNull('the fixture has an icon in a nested category')
        ->and($flat)->not->toBeNull('the fixture has an icon with no nested category');

    $child = $nested->terms->first(fn (IconTerm $t): bool => $t->type === IconTerm::TYPE_CATEGORY && $t->parent_id !== null);

    expect($nested->primary_subcategory?->slug)->toBe($child->slug)
        ->and($browser->transformIcon($nested)['sub'])->toBe($child->slug)
        ->and($flat->primary_subcategory)->toBeNull()
        ->and($browser->transformIcon($flat)['sub'])->toBeNull();
});

it('finds the subcategory on an icon loaded without its terms', function (): void {
    $child = IconTerm::query()->where('type', IconTerm::TYPE_CATEGORY)->whereNotNull('parent_id')
        ->where('package', NESTED_PACK)->firstOrFail();
    $id = collect(app(IconBrowserService::class)->getIcons(['subs' => [$child->slug]], perPage: 100)->items())->first()?->id;

    expect($id)->not->toBeNull()
        ->and(Icon::query()->findOrFail($id)->relationLoaded('terms'))->toBeFalse()
        ->and(Icon::query()->findOrFail($id)->primary_subcategory?->slug)->toBe($child->slug);
});

it('keeps every key transformIcon returned before, adding sub', function (): void {
    $icon = collect(app(IconBrowserService::class)->getIcons(['packages' => [NESTED_PACK]], perPage: 1)->items())->first();

    expect(array_keys(app(IconBrowserService::class)->transformIcon($icon)))->toContain(
        'id',
        'package',
        'name',
        'kind',
        'themes',
        'category',
        'variant',
        'path',
        'svg_content',
        'svg_url',
        'viewbox',
        'width',
        'height',
        'icon_path',
        'file_path',
        'set',
        'blade_clean',
        'blade_generic',
        'helper',
        'sub',
    );
});
