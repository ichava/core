<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;

/*
 * buildIconTree() reads the taxonomy from the database (core#104). These pin
 * what it reports against what is seeded, per category, not just per pack.
 */

function seedTreeIcon(string $package, string $name, array $terms): Icon
{
    $icon = Icon::create(['package' => $package, 'name' => $name, 'path' => "{$name}.svg"]);

    foreach ($terms as [$type, $slug]) {
        $term = IconTerm::firstOrCreate(
            ['type' => $type, 'slug' => $slug, 'package' => $package],
            ['name' => ucfirst($slug)],
        );
        $icon->terms()->attach($term->id);
    }

    return $icon;
}

beforeEach(function (): void {
    // Two categories. Only the three outline icons carry the "rounded" variant.
    foreach (['a', 'b', 'c'] as $n) {
        seedTreeIcon('acme/icons', "out-{$n}", [['category', 'outline'], ['variant', 'rounded']]);
    }
    seedTreeIcon('acme/icons', 'fill-a', [['category', 'filled']]);
    seedTreeIcon('acme/icons', 'fill-b', [['category', 'filled']]);

    app(IconBrowserService::class)->clearCache();
});

it('counts each category and the pack from the seeded rows', function (): void {
    $tree = app(IconBrowserService::class)->buildIconTree();

    expect($tree)->toHaveCount(1);
    $pack = $tree[0];
    $cats = collect($pack['cats'])->keyBy('name');

    expect($pack['count'])->toBe(5)
        ->and($cats['outline']['count'])->toBe(3)
        ->and($cats['filled']['count'])->toBe(2);
});

it('lists under a category only the variants its own icons carry', function (): void {
    $cats = collect(app(IconBrowserService::class)->buildIconTree()[0]['cats'])->keyBy('name');

    // outline: all three icons are rounded.
    expect($cats['outline']['sub'] ?? [])->toBe([['slug' => 'rounded', 'name' => 'Rounded', 'count' => 3]]);

    // filled: none of its icons is rounded, so it has no variant level at all.
    expect($cats['filled'])->not->toHaveKey('sub');
});

it('drops a category level whose only variant repeats the category', function (): void {
    // tabler's shape: the variant slug is the category slug, so a second level adds nothing.
    seedTreeIcon('acme/mirror', 'm-a', [['category', 'outline'], ['variant', 'outline']]);
    app(IconBrowserService::class)->clearCache();

    $mirror = collect(app(IconBrowserService::class)->buildIconTree())->firstWhere('pack', 'acme/mirror');

    expect($mirror['cats'][0])->not->toHaveKey('sub');
});

it('shows a pack seeded after the tree was cached once the icon cache is invalidated', function (): void {
    $service = app(IconBrowserService::class);
    expect(collect($service->buildIconTree())->pluck('pack')->all())->toBe(['acme/icons']);

    seedTreeIcon('acme/late', 'late-a', [['category', 'misc']]);

    // Cached: the new pack is not visible yet, which is what makes the next step a test.
    expect(collect($service->buildIconTree())->pluck('pack')->all())->toBe(['acme/icons']);

    event(Simtabi\Laranail\Ichava\Events\IconCacheEvent::changed('acme/late'));

    expect(collect($service->buildIconTree())->pluck('pack')->sort()->values()->all())
        ->toBe(['acme/icons', 'acme/late']);
});
