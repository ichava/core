<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Fixtures\TranslatedPack\Providers;

use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\ServiceProvider;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;

/**
 * A stand-in for a real icon pack.
 *
 * Deliberately laid out as `<base>/src/Providers/` and autoloaded from there,
 * rather than stubbing `getPackageBaseDir()`. That method resolves by
 * reflection on the provider's own file and steps out of `Providers/` and then
 * `src/`, and the default-on translation check in ServiceProvider::newPackage()
 * depends on it landing on the package root. Overriding it would have made the
 * test agree with itself instead of with the mechanism.
 *
 * Note what this class does NOT do: it never calls `hasTranslations()`. That is
 * the point -- a pack gets translations by existing, not by remembering.
 */
class IconsServiceProvider extends ServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->setName('ichava/fixture-pack')
            ->setPathFrom(source: $this, levelsUp: 2);
    }

    public function bootingPackage(): void
    {
        // Register a Blade component before the icon directory, which is the
        // order every real pack uses and the order the registry depends on to
        // have an alias recorded by the time metadata is built.
        $this->loadBladeComponent(componentClass: IconComponent::class, packageName: 'fixture-pack');

        $this->app->make(IconRegistry::class)->fromDirectory(
            $this->package->basePath('resources/assets/svg'),
            self::class,
        );
    }
}
