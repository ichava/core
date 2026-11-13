<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Models\Icon;

/*
 * viewbox, width and height read the `attributes` JSON column. They used to read
 * Eloquent's `$this->attributes` -- the row's raw columns -- so they always returned null,
 * and writing one threw or merged every column into the JSON.
 */
it('reads and writes the SVG attributes stored in the attributes column', function (): void {
    $icon = Icon::create([
        'package'    => 'acme/icons', 'name' => 'x', 'path' => 'files/x.svg',
        'attributes' => ['viewbox' => '0 0 24 24', 'width' => 24, 'height' => 24],
    ]);
    $icon = Icon::find($icon->id);

    expect($icon->viewbox)->toBe('0 0 24 24')
        ->and($icon->width)->toBe('24')
        ->and($icon->height)->toBe('24')
        ->and($icon->getSvgAttribute('viewbox'))->toBe('0 0 24 24');

    $icon->viewbox = '0 0 16 16';
    $icon->setSvgAttribute('width', 16);
    $icon->save();

    // Compared as a map: MySQL's JSON type reorders keys on write.
    $stored = json_decode((string) Icon::find($icon->id)->getRawOriginal('attributes'), true);
    ksort($stored);
    expect($stored)->toBe(['height' => 24, 'viewbox' => '0 0 16 16', 'width' => 16]);
});

it('gives null for an icon with no SVG attributes stored', function (): void {
    $icon = Icon::create(['package' => 'acme/icons', 'name' => 'y', 'path' => 'files/y.svg']);

    expect(Icon::find($icon->id)->viewbox)->toBeNull();
});
