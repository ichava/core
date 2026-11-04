<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Enums\IconKind;

/**
 * S2 (2026-11-04 security audit) -- the serve path read the file with a bare
 * `File::get()` and no size check, so the per-kind byte budget enforced at
 * seed time was never re-checked when an icon was actually served. A file
 * swapped or grown on disk after seeding was read and parsed whatever its size.
 *
 * The budget is now checked before the read, and an oversized file fails
 * closed to an empty string, exactly as a file the sanitiser rejects does.
 */
beforeEach(function () {
    Icon::query()->delete();

    $this->dir = sys_get_temp_dir() . '/ichava-svg-cap-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);

    $this->plant = function (string $filename, string $svg, IconKind $kind = IconKind::Icon): Icon {
        $abs = $this->dir . '/' . $filename;
        File::put($abs, $svg);

        $icon = Icon::create([
            'package'    => 'ichava/test-pack',
            'name'       => pathinfo($filename, PATHINFO_FILENAME),
            'path'       => $abs,
            'file_hash'  => md5($svg),
            'kind'       => $kind,
            'keywords'   => [],
            'tags'       => [],
            'attributes' => [],
            'metadata'   => [],
        ]);

        $icon->forceFill(['path' => $abs])->save();

        return $icon->fresh();
    };

    $this->svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>';
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('serves an icon exactly at its kind byte budget', function () {
    config()->set('ichava.ichava-core.max_file_size', strlen($this->svg));

    expect(($this->plant)('at-cap.svg', $this->svg)->svg_content)->toContain('<path');
});

it('refuses to serve an icon over its kind byte budget', function () {
    config()->set('ichava.ichava-core.max_file_size', strlen($this->svg) - 1);

    expect(($this->plant)('over-cap.svg', $this->svg)->svg_content)->toBe('');
});

it('gives an illustration its own, larger byte budget', function () {
    config()->set('ichava.ichava-core.max_file_size', strlen($this->svg) - 1);
    config()->set('ichava.ichava-core.kinds.max_file_size.illustration', strlen($this->svg));

    expect(($this->plant)('scene.svg', $this->svg, IconKind::Illustration)->svg_content)->toContain('<path');
});
