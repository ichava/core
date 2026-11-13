<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Support;

use Simtabi\Laranail\Ichava\View\Components\IconComponent;

/**
 * A pack component for the bundled `ichava/test-icons` set, registered the way a pack's
 * `loadBladeComponent()` does, so the pack-tag snippet can be rendered rather than read.
 */
final class TestIconsPackComponent extends IconComponent
{
    protected function getVendorPackage(): string
    {
        return 'ichava/test-icons';
    }
}
