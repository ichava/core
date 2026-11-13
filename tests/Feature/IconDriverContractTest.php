<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Contracts\IconDriverInterface;

/*
 * C-17. IconDriverInterface had no implementor in use: the driver core runs,
 * SvgDriver, has different signatures. The interface now states SvgDriver's
 * real ones (render an IconData, load a path safely), SvgDriver implements it,
 * the provider binds it, and the registry and the served-SVG reader depend on
 * it, so a host substitutes the driver without subclassing.
 */

it('binds the interface to the SvgDriver singleton', function (): void {
    expect(app(IconDriverInterface::class))->toBe(app(SvgDriver::class))
        ->and(app(IconRegistry::class)->driver())->toBeInstanceOf(IconDriverInterface::class);
});

it('renders through whatever driver the interface is bound to', function (): void {
    app()->forgetInstance(IconRegistry::class);
    app()->instance(IconDriverInterface::class, new class implements IconDriverInterface
    {
        public function render(IconData $icon, array $attributes = [], ?string $baseDir = null, ?int $maxBytes = null): string
        {
            return '<svg data-fake="' . $icon->name . '"/>';
        }

        public function load(string $path, array $options = [], ?string $baseDir = null, ?int $maxBytes = null): string
        {
            return '<svg/>';
        }
    });

    $registry = app(IconRegistry::class);
    $registry->fromDirectory(dirname(__DIR__, 2) . '/resources/assets/svg/test-icons');

    expect($registry->render('ichava/test-icons::test-icons/check'))->toBe('<svg data-fake="check"/>');
});
