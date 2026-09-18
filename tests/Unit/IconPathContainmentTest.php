<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconSetBuilder;

describe('Icon path containment', function () {
    beforeEach(function () {
        $this->package = 'test/contained-' . uniqid();
        $this->base = sys_get_temp_dir() . '/ichava-contain-' . uniqid();
        $this->outsideSvg = sys_get_temp_dir() . '/ichava-outside-' . uniqid() . '.svg';
        $this->outsideText = sys_get_temp_dir() . '/ichava-secret-' . uniqid() . '.txt';

        File::makeDirectory($this->base . '/files', 0755, true);
        File::put($this->base . '/files/ok.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>');
        File::put($this->outsideSvg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 1h22v22H1z"/></svg>');
        File::put($this->outsideText, 'TOP-SECRET-marker');

        $set = IconSetBuilder::make($this->package)->setBasePath($this->base);

        app(IconRegistry::class)->registerIconSet($this->package, $set, [
            'package_name'  => $this->package,
            'icon_set_name' => $this->package,
            'base_path'     => $this->base,
        ]);
    });

    afterEach(function () {
        File::deleteDirectory($this->base);
        @unlink($this->outsideSvg);
        @unlink($this->outsideText);
    });

    it('does not serve files outside the package directory via traversal', function () {
        $icon = Icon::create([
            'package' => $this->package,
            'name'    => 'evil',
            'path'    => 'files/../../' . basename($this->outsideSvg),
        ]);

        expect($icon->svg_content)->toBeNull()
            ->and($icon->file_size)->toBeNull();
    });

    it('does not disclose outside files through the size oracle', function () {
        $icon = Icon::create([
            'package' => $this->package,
            'name'    => 'sneaky',
            'path'    => 'files/../../' . basename($this->outsideText),
        ]);

        expect($icon->file_size)->toBeNull()
            ->and($icon->svg_content)->toBeNull();
    });

    it('does not honour absolute stored paths outside the package directory', function () {
        $icon = Icon::create([
            'package' => $this->package,
            'name'    => 'absolute',
            'path'    => $this->outsideSvg,
        ]);

        expect($icon->svg_content)->toBeNull()
            ->and($icon->file_size)->toBeNull();
    });

    it('still serves files inside the package directory', function () {
        $icon = Icon::create([
            'package' => $this->package,
            'name'    => 'ok',
            'path'    => 'files/ok.svg',
        ]);

        expect($icon->svg_content)->toContain('<svg')
            ->and($icon->file_size)->toBeGreaterThan(0);
    });
});
