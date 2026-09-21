<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

describe('SvgDriver containment', function () {
    beforeEach(function () {
        $this->base = sys_get_temp_dir() . '/ichava-driver-' . uniqid();
        $this->outside = sys_get_temp_dir() . '/ichava-driver-outside-' . uniqid() . '.svg';

        File::makeDirectory($this->base . '/files', 0755, true);
        File::put($this->base . '/files/ok.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>');
        File::put($this->outside, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 1h22v22H1z"/></svg>');

        $this->driver = app(SvgDriver::class);
    });

    afterEach(function () {
        File::deleteDirectory($this->base);
        @unlink($this->outside);
    });

    it('rejects loads outside the given base directory', function () {
        expect(fn () => $this->driver->load($this->outside, [], $this->base))
            ->toThrow(IchavaException::class);
    });

    it('rejects traversal loads against the given base directory', function () {
        $traversal = $this->base . '/files/../../' . basename($this->outside);

        expect(fn () => $this->driver->load($traversal, [], $this->base))
            ->toThrow(IchavaException::class);
    });

    it('rejects renders outside the given base directory', function () {
        $icon = new IconData(name: 'evil', path: $this->outside, variant: null, category: null, set: 'test/set');

        expect(fn () => $this->driver->render($icon, [], $this->base))
            ->toThrow(IchavaException::class);
    });

    it('loads files inside the given base directory', function () {
        $content = $this->driver->load($this->base . '/files/ok.svg', [], $this->base);

        expect($content)->toContain('<svg');
    });
});
