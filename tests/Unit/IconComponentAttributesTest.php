<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconSetBuilder;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;

beforeEach(function () {
    $dir = sys_get_temp_dir() . '/ichava-blade-test-' . uniqid();
    File::makeDirectory($dir . '/outline', 0755, true);
    File::put($dir . '/outline/check.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>');
    $this->bladeTestDir = $dir;

    $set = IconSetBuilder::make('blade/test-set')
        ->setBasePath($dir)
        ->prefix('bt');

    app(IconRegistry::class)->registerIconSet('blade/test-set', $set, [
        'package_name'  => 'blade/test-set',
        'icon_set_name' => 'blade/test-set',
        'base_path'     => $dir,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->bladeTestDir);
});

it('merges the Blade class attribute into the rendered svg', function () {
    $html = Blade::render('<x-ichava::icon name="blade/test-set::outline/check" class="size-5" />');

    expect($html)->toContain('size-5');
    expect($html)->toContain('<svg');
});

it('passes arbitrary Blade attributes through to the rendered svg', function () {
    $html = Blade::render('<x-ichava::icon name="blade/test-set::outline/check" data-tip="hello" />');

    expect($html)->toContain('data-tip="hello"');
});

it('renders via renderNow after withAttributes for direct callers', function () {
    $component = app(IconComponent::class, ['name' => 'blade/test-set::outline/check']);
    $component->withAttributes(['class' => 'size-5']);

    expect($component->renderNow())->toContain('size-5');
    expect($component->render()->toHtml())->toContain('size-5');
});
