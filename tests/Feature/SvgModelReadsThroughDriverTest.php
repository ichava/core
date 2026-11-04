<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;

/*
 * C-12. "Read an SVG safely" has one implementation, SvgDriver::load(). The
 * model's serve path had its own copy, with no minimum size and no explicit
 * symlink refusal. It now reads through the driver, and a refusal still fails
 * closed to '' with an audit line, as before. Measured before the change: 0 of
 * 246,854 SVGs under the packs' resources/ are below MIN_SVG_FILE_SIZE, and 0
 * are symlinks, so no shipped icon stops rendering.
 */

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/ichava-svg-driver-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);

    $this->icon = function (string $abs): Icon {
        $icon = Icon::create([
            'package'   => 'ichava/test-pack', 'name' => pathinfo($abs, PATHINFO_FILENAME), 'path' => $abs,
            'file_hash' => md5($abs), 'keywords' => [], 'tags' => [], 'attributes' => [], 'metadata' => [],
        ]);
        $icon->forceFill(['path' => $abs])->save();

        return $icon->fresh();
    };
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

it('refuses a file below the minimum SVG size, as the driver does', function (): void {
    File::put($this->dir . '/tiny.svg', '<svg/>');

    expect(($this->icon)($this->dir . '/tiny.svg')->svg_content)->toBe('');
});

it('refuses a symlinked SVG, as the driver does', function (): void {
    File::put($this->dir . '/real.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>');
    symlink($this->dir . '/real.svg', $this->dir . '/link.svg');

    expect(($this->icon)($this->dir . '/link.svg')->svg_content)->toBe('')
        ->and(($this->icon)($this->dir . '/real.svg')->svg_content)->toContain('<path');
});
