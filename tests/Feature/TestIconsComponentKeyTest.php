<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\View\ComponentPrefixes;

/*
 * `components.test_icons` had no reader. Core now answers it, through the
 * same object that owns the rest of its Blade surface, so icon-browser (which
 * registers the test-icons component) reads core's key instead of its own,
 * and `components.enabled` false turns it off with everything else.
 */

it('reads components.test_icons', function (bool $value): void {
    config()->set('ichava.ichava-core.components.test_icons', $value);

    expect(app(ComponentPrefixes::class)->testIconsEnabled())->toBe($value);
})->with([true, false]);

it('turns the test-icons component off with the rest of the Blade surface', function (): void {
    config()->set('ichava.ichava-core.components.test_icons', true);
    config()->set('ichava.ichava-core.components.enabled', false);

    expect(app(ComponentPrefixes::class)->testIconsEnabled())->toBeFalse();
});
