<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\SecurityNonce;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;

/*
 * The `dark` prop on `<x-ichava::icon>` (V-B10).
 *
 * The fixture is core's KindPack: `scenes` declares a `-dark` theme twin and
 * ships `park.svg` with `park-dark.svg` (fill `#023047`); `glyphs/star` has no
 * twin. Without `dark`, and with the auto twin off (the default), the output
 * must be byte-identical to what the component rendered before the prop
 * existed, so every assertion on that path compares bytes.
 */
const DARK_PACK = 'ichava-fixtures/kind-pack';

beforeEach(function (): void {
    app(IconRegistry::class)->fromDirectory(dirname(__DIR__) . '/fixtures/KindPack');
});

function renderIcon(string $name, ?string $dark = null): string
{
    return (new IconComponent(
        name: $name,
        dark: $dark,
        iconRegistry: app(IconRegistry::class),
        svgProcessor: app(SvgProcessingService::class),
        securityNonce: app(SecurityNonce::class),
    ))->render()->toHtml();
}

it('finds a theme twin the pack declares, and only one that exists', function (): void {
    $registry = app(IconRegistry::class);

    expect($registry->themeTwin(DARK_PACK . '::scenes/park'))->toBe(DARK_PACK . '::scenes/park-dark')
        ->and($registry->themeTwin(DARK_PACK . '::scenes/park', 'sepia'))->toBeNull()
        // moon-dark.svg has no light sibling, and glyphs declares no twin.
        ->and($registry->themeTwin(DARK_PACK . '::scenes/moon'))->toBeNull()
        ->and($registry->themeTwin(DARK_PACK . '::glyphs/star'))->toBeNull()
        ->and($registry->themeTwin('not a path'))->toBeNull();
});

function golden(string $file): string
{
    // Captured from the component as it was before the `dark` prop existed.
    return (string) file_get_contents(dirname(__DIR__) . '/fixtures/golden/' . $file);
}

it('renders exactly the pre-dark output when no dark is given and the auto twin is off', function (): void {
    expect(config('ichava.ichava-core.theme_variants.auto_dark'))->toBeFalse()
        ->and(renderIcon(DARK_PACK . '::scenes/park'))->toBe(golden('icon-component-park.svg'))
        ->and(renderIcon(DARK_PACK . '::glyphs/star'))->toBe(golden('icon-component-star.svg'));
});

it('renders both twins when dark names an icon', function (): void {
    $html = renderIcon(DARK_PACK . '::scenes/park', DARK_PACK . '::scenes/park-dark');

    expect($html)->toStartWith('<span class="ichava-themed">')
        ->and(substr_count($html, '<svg'))->toBe(2)
        ->and($html)->toContain('ichava-theme-light')
        ->and($html)->toContain('ichava-theme-dark')
        ->and($html)->toContain('#023047')
        ->and($html)->toContain('prefers-color-scheme:dark');
});

it('works through the Blade tag, bare and scoped', function (): void {
    $tag = 'icon name="' . DARK_PACK . '::scenes/park" dark="' . DARK_PACK . '::scenes/park-dark" class="w-40" />';
    $bare = Blade::render('<x-ichava::' . $tag);

    expect(substr_count($bare, '<svg'))->toBe(2)
        ->and(substr_count($bare, 'w-40'))->toBe(2)
        ->and(Blade::render('<x-ichava-core::' . $tag))->toBe($bare);
});

it('renders the pack twin when the auto twin is on, and leaves an icon without one unchanged', function (): void {
    config()->set('ichava.ichava-core.theme_variants.auto_dark', true);

    expect(substr_count(renderIcon(DARK_PACK . '::scenes/park'), '<svg'))->toBe(2)
        ->and(renderIcon(DARK_PACK . '::scenes/park'))->toContain('#023047')
        ->and(renderIcon(DARK_PACK . '::glyphs/star'))->toBe(golden('icon-component-star.svg'));
});

it('falls back to the light icon alone when dark names nothing', function (): void {
    expect(renderIcon(DARK_PACK . '::scenes/park', DARK_PACK . '::scenes/missing'))
        ->toBe(renderIcon(DARK_PACK . '::scenes/park'));
});

it('toggles on a .dark ancestor when the host asks for class mode', function (): void {
    config()->set('ichava.ichava-core.theme_variants.dark_mode', 'class');

    $html = renderIcon(DARK_PACK . '::scenes/park', DARK_PACK . '::scenes/park-dark');

    expect($html)->toContain('.dark .ichava-themed>.ichava-theme-light{display:none}')
        ->and($html)->not->toContain('prefers-color-scheme');
});

it('carries the request CSP nonce on its style', function (): void {
    $html = renderIcon(DARK_PACK . '::scenes/park', DARK_PACK . '::scenes/park-dark');

    expect($html)->toContain('<style' . app(SecurityNonce::class)->attribute() . '>');
});
