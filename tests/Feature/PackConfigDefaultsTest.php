<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;

/*
 * P-06. A pack's default attributes lived in its IconComponent subclass, so
 * they reached `<x-{slug}-icon>` and not `<x-ichava::icon>` or render(), while
 * `config.defaults` in config.json had no reader. `config.defaults` is now the
 * one source, applied when the set registers, so every way of rendering the
 * set draws the same attributes.
 */

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/ichava-defaults-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir . '/files');
    File::put($this->dir . '/files/line.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 12h16"/></svg>');
    File::put($this->dir . '/config.json', json_encode([
        'package' => ['name' => 'acme/stroked', 'title' => 'Stroked'],
        'config'  => [
            'icon_prefix' => 'stroked',
            'defaults'    => ['class' => 'stroked-icon', 'stroke_width' => 2, 'attributes' => ['stroke-linecap' => 'round']],
        ],
    ]));
    app(IconRegistry::class)->register(PackBuilder::fromDirectory($this->dir)->build());
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('applies config.defaults when the registry renders', function (): void {
    $svg = app(IconRegistry::class)->render('acme/stroked::line');

    expect($svg)->toContain('stroke-width="2"')
        ->toContain('stroke-linecap="round"')
        ->toContain('stroked-icon');
});

it('applies config.defaults through the generic Blade tag', function (): void {
    $html = Blade::render('<x-ichava-core::icon name="acme/stroked::line" />');

    expect($html)->toContain('stroke-width="2"')->toContain('stroke-linecap="round"');
});

it('lets a fluent default override the file one', function (): void {
    app()->forgetInstance(IconRegistry::class);
    app(IconRegistry::class)->register(PackBuilder::fromDirectory($this->dir)->defaultAttributes(['stroke-linecap' => 'square'])->build());

    expect(app(IconRegistry::class)->render('acme/stroked::line'))->toContain('stroke-linecap="square"');
});
