<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;

/**
 * B7 through the model: the opt-in pass runs on the read path every consumer
 * uses, and only when the host has turned it on.
 */
beforeEach(function () {
    Icon::query()->delete();

    $this->dir = sys_get_temp_dir() . '/ichava-svg-mono-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);

    $this->plant = function (string $filename, string $svg): Icon {
        $abs = $this->dir . '/' . $filename;
        File::put($abs, $svg);

        $icon = Icon::create([
            'package'    => 'ichava/test-pack',
            'name'       => pathinfo($filename, PATHINFO_FILENAME),
            'path'       => $abs,
            'file_hash'  => md5($svg),
            'keywords'   => [],
            'tags'       => [],
            'attributes' => [],
            'metadata'   => [],
        ]);

        $icon->forceFill(['path' => $abs])->save();

        return $icon->fresh();
    };

    $this->mono = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#000000" d="M4 4h16v16H4z"/></svg>';
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('serves black paint unchanged while the flag is off', function () {
    config()->set('ichava.ichava-core.svg.monochrome_to_current_color', false);

    $content = ($this->plant)('mono-off.svg', $this->mono)->svg_content;

    expect($content)->toContain('#000000')->not->toContain('currentColor');
});

it('serves a monochrome icon with currentColor when the flag is on', function () {
    config()->set('ichava.ichava-core.svg.monochrome_to_current_color', true);

    $content = ($this->plant)('mono-on.svg', $this->mono)->svg_content;

    expect($content)->toContain('fill="currentColor"')
        ->toContain('<path')
        ->not->toContain('#000000');
});

it('serves a multicolour icon unchanged when the flag is on', function () {
    config()->set('ichava.ichava-core.svg.monochrome_to_current_color', true);

    $content = ($this->plant)('multi.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#000" d="M0 0h12v24H0z"/><path fill="#ff0000" d="M12 0h12v24H12z"/></svg>')->svg_content;

    expect($content)->toContain('#ff0000')->toContain('#000')->not->toContain('currentColor');
});
