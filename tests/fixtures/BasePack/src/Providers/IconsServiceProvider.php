<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Fixtures\BasePack\Providers;

use Simtabi\Laranail\Ichava\View\Components\IconComponent;
use Simtabi\Laranail\Ichava\Support\IconPackServiceProvider;
use Simtabi\Laranail\Ichava\Tests\Fixtures\BasePack\Constants\IconsConstants;

/**
 * A pack provider written the way every estate pack now is: two class names
 * and nothing else. Laid out as `<base>/src/Providers/` so the base resolves
 * the pack root from this file exactly as it does for a real pack.
 */
final class IconsServiceProvider extends IconPackServiceProvider
{
    protected function constantsClass(): string
    {
        return IconsConstants::class;
    }

    protected function componentClass(): string
    {
        return IconComponent::class;
    }
}
