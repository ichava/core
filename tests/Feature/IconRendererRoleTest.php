<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\IconRenderer;
use Simtabi\Laranail\Ichava\Services\IconRegistry;

/*
 * `role(null)` drops the role attribute; the default stays `role="img"`.
 *
 * The fixture is core's KindPack, whose `glyphs/star.svg` carries no role of
 * its own, so any `role=` in the output came from the renderer.
 */
const ROLE_ICON = 'ichava-fixtures/kind-pack::glyphs/star';

beforeEach(function (): void {
    app(IconRegistry::class)->fromDirectory(dirname(__DIR__) . '/fixtures/KindPack');
});

it('renders role="img" by default, exactly as an explicit role("img") does', function (): void {
    $default = IconRenderer::make(ROLE_ICON)->render();

    expect($default)->toContain('<svg')
        ->and(substr_count($default, 'role="img"'))->toBe(1)
        ->and($default)->toBe(IconRenderer::make(ROLE_ICON)->role('img')->render());
});

it('omits the role attribute when role(null) is called', function (): void {
    $svg = IconRenderer::make(ROLE_ICON)->role(null)->render();

    expect($svg)->toContain('<svg')
        ->and($svg)->not->toContain('role=');
});

it('still accepts a string role', function (): void {
    expect(IconRenderer::make(ROLE_ICON)->role('presentation')->render())
        ->toContain('role="presentation"')
        ->not->toContain('role="img"');
});

it('reports the dropped role as null in toArray()', function (): void {
    expect(IconRenderer::make(ROLE_ICON)->role(null)->toArray()['role'])->toBeNull()
        ->and(IconRenderer::make(ROLE_ICON)->toArray()['role'])->toBe('img');
});
