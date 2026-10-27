<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;
use Simtabi\Laranail\Ichava\Tests\Support\TestIconsPackComponent;

/*
 * The strings the icon browser hands a user to paste -- `icon_path`, the
 * `ichava()` helper and `<x-ichava::icon>` -- have to render. `icon_path` used to
 * end in `:variant`, which the registry refuses, so every tabler icon's snippet
 * threw; and the shared renderer let one helper call's classes leak into the next.
 */

it('keys a stored path the way the registry does', function (string $path, string $key): void {
    expect(Icon::registryKey($path))->toBe($key);
})->with([
    'variant folder'      => ['files/outline/home.svg', 'outline/home'],
    'nested pack folders' => ['files/iconpark/brand/github.svg', 'iconpark/brand/github'],
    'no files/ root'      => ['outline/home.svg', 'outline/home'],
    'absolute-ish'        => ['/srv/pack/svg/files/flags/4x3/ke.svg', 'flags/4x3/ke'],
    'backslashes'         => ['files\\outline\\home.SVG', 'outline/home'],
]);

it('builds icon_path from the stored path, with no variant suffix', function (): void {
    $icon = new Icon(['package' => 'ichava/icon-sets-tabler', 'name' => 'home', 'path' => 'files/outline/home.svg']);

    expect($icon->icon_path)->toBe('ichava/icon-sets-tabler::outline/home');
});

it('gives every seeded icon a path the registry and the Blade component resolve', function (): void {
    test()->artisan('ichava::ichava-core.database', ['action' => 'seed', '--sync' => true])->run();

    $icons = Icon::query()->where('package', 'ichava/test-icons')->get();
    $registry = app(IconRegistry::class);

    expect($icons)->not->toBeEmpty();

    foreach ($icons as $icon) {
        expect($registry->has($icon->icon_path))->toBeTrue("registry misses {$icon->icon_path}")
            ->and(Blade::render('<x-ichava::icon name="' . $icon->icon_path . '" />'))->toContain('<svg');
    }
});

it('renders every snippet the browser service hands out', function (): void {
    test()->artisan('ichava::ichava-core.database', ['action' => 'seed', '--sync' => true])->run();
    Blade::component('test-icons-icon', TestIconsPackComponent::class);
    $service = app(IconBrowserService::class);
    $icons = Icon::query()->where('package', 'ichava/test-icons')->get();

    expect($icons)->not->toBeEmpty();

    foreach ($icons as $icon) {
        $clean = $service->generateBladeComponent($icon);
        expect($clean)->toStartWith('<x-test-icons-icon ');

        foreach ([$clean, $service->generateBladeComponent($icon, false), $service->generateHelperCode($icon)] as $snippet) {
            $this->assertStringContainsString('<svg', Blade::render($snippet), "{$snippet} did not render");
        }
    }
});

it('applies the attributes passed to the helper', function (): void {
    $html = Blade::render("{!! ichava('ichava/test-icons::test-icons/circle', ['class' => 'size-5']) !!}");

    expect($html)->toContain('class="size-5"');
});

it('starts every helper call from a clean renderer', function (): void {
    $first = Blade::render("{!! ichava('ichava/test-icons::test-icons/circle')->class('leaky') !!}");
    $second = Blade::render("{!! ichava('ichava/test-icons::test-icons/circle') !!}");

    expect($first)->toContain('leaky')
        ->and($second)->not->toContain('leaky');
});
