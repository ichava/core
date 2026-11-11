<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Services\IconSvgReader;

/*
 * C-11. The served-SVG pipeline the Icon model ran through four container
 * reach-ins is a service with its collaborators injected; the model's
 * accessor forwards to it. Read directly here, with no model row.
 */

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/ichava-reader-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('serves a sanitised SVG and refuses a script', function (): void {
    File::put($this->dir . '/a.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>alert(1)</script><path d="M4 4h16v16H4z"/></svg>');

    $svg = app(IconSvgReader::class)->read($this->dir . '/a.svg', 'a.svg', 'reader:' . bin2hex(random_bytes(4)), IconKind::Icon, 'acme/reader', $this->dir);

    expect($svg)->toContain('<path')->not->toContain('<script');
});

it('fails closed on a file outside the pack directory', function (): void {
    File::put($this->dir . '/a.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>');

    expect(app(IconSvgReader::class)->read($this->dir . '/a.svg', 'a.svg', 'reader:' . bin2hex(random_bytes(4)), IconKind::Icon, 'acme/reader', $this->dir . '/elsewhere'))->toBe('');
});
