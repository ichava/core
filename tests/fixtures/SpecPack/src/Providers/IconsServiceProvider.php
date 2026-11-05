<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Fixtures\SpecPack\Providers;

use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Support\Pack\PackSpec;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;
use Simtabi\Laranail\Ichava\Providers\IconPackServiceProvider;
use Simtabi\Laranail\Ichava\Tests\Support\TestIconsPackComponent;
use Simtabi\Laranail\Ichava\Tests\Fixtures\SpecPack\Constants\IconsConstants;

/**
 * A pack on the advanced path: its pack() hook adds a kind rule, a theme twin
 * and an alias to the default set, a second set, every set under a directory,
 * an extra component, and a config file under another name.
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

    protected function pack(PackSpec $pack): void
    {
        $pack
            ->set(fn (PackBuilder $set) => $set
                ->kindRule('illustrations/**', IconKind::Illustration)
                ->alias('shapes/ring', 'shapes/dot'))
            ->addSet('resources/assets/svg-brands', fn (PackBuilder $set) => $set->prefix('brand'))
            ->sets('resources/assets/svg-sets', only: ['two'])
            ->component('badge', TestIconsPackComponent::class)
            ->configFile('spec-custom');
    }
}
