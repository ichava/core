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

    it('rejects a file reached through a symlinked directory', function () {
        // The symlink is on a *directory*, so the file itself is not a link and
        // isLink() says nothing. realpath() resolves the link and the resolved
        // path lands outside the base, which is what refuses it.
        $outside = $this->dir . '-outside';
        File::makeDirectory($outside, 0755, true);
        File::put($outside . '/secret.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0h1"/></svg>');
        symlink($outside, $this->dir . '/escape');

        try {
            expect(fn () => ($this->extract)('escape/secret.svg'))
                ->toThrow(IchavaException::class, 'Path escapes its directory');
        } finally {
            File::deleteDirectory($outside);
        }
    });

    it('rejects a path that traverses out of the base directory', function () {
        $sibling = $this->dir . '-sibling';
        File::makeDirectory($sibling, 0755, true);
        File::put($sibling . '/elsewhere.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0h1"/></svg>');

        try {
            expect(fn () => ($this->extract)('../' . basename($sibling) . '/elsewhere.svg'))
                ->toThrow(IchavaException::class, 'Path escapes its directory');
        } finally {
            File::deleteDirectory($sibling);
        }
    });

    it('does not descend a symlinked directory during a scan', function () {
        // Containment is defence in depth rather than a fix for a live escape:
        // Finder's followLinks is off, so the scan never yields the file above.
        // If someone turns it on, this test changes and the guard starts earning
        // its keep -- which is the point of having both.
        $outside = $this->dir . '-scan-outside';
        File::makeDirectory($outside, 0755, true);
        File::put($outside . '/secret.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0h1"/></svg>');
        symlink($outside, $this->dir . '/escape-scan');

        try {
            $found = array_map(
                fn ($f) => $f->getPathname(),
                File::allFiles($this->dir),
            );

            expect($found)->not->toContain($outside . '/secret.svg');
        } finally {
            File::deleteDirectory($outside);
        }
    });

    it('extracts normal files', function () {
        $data = ($this->extract)('ok.svg');

        expect($data['name'])->toBe('ok');
    });
});
