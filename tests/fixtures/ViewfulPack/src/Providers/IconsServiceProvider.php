<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Fixtures\ViewfulPack\Providers;

use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Ichava\Support\ServiceProvider;

/**
 * A pack that ships a real Blade template.
 *
 * The counterpart to TranslatedPack, which ships `resources/views/components/`
 * with nothing in it -- the shape all five real packs have. Between them they
 * pin both sides of the rule: a directory alone registers nothing, a template
 * registers the namespace.
 *
 * Like TranslatedPack it never calls hasViews(), hasConfigFile() or
 * hasTranslations(). That is the mechanism under test.
 */
class IconsServiceProvider extends ServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->setName('ichava/viewful-pack')
            ->setPathFrom(source: $this, levelsUp: 2);
    }
}
