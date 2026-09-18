<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\IconWatcherService;

describe('Watcher file guards', function () {
    beforeEach(function () {
        $this->dir = sys_get_temp_dir() . '/ichava-watch-' . uniqid();
        File::makeDirectory($this->dir, 0755, true);
        File::put($this->dir . '/ok.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>');

        $this->extract = function (string $filename) {
            $method = new ReflectionMethod(IconWatcherService::class, 'extractIconData');
            $method->setAccessible(true);

            return $method->invoke(
                app(IconWatcherService::class),
                new SplFileInfo($this->dir . '/' . $filename, '', $filename),
                'test/pkg',
                $this->dir,
            );
        };
    });

    afterEach(function () {
        File::deleteDirectory($this->dir);
    });

    it('rejects symlinked files during extraction', function () {
        symlink($this->dir . '/ok.svg', $this->dir . '/link.svg');

        expect(fn () => ($this->extract)('link.svg'))->toThrow(IchavaException::class);
    });

    it('rejects oversized files during extraction', function () {
        File::put($this->dir . '/big.svg', str_repeat('<path d="M0 0h1"/>', 100_000));

        expect(fn () => ($this->extract)('big.svg'))->toThrow(IchavaException::class);
    });

    it('extracts normal files', function () {
        $data = ($this->extract)('ok.svg');

        expect($data['name'])->toBe('ok');
    });
});
