<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Fixtures\TranslatedPack\Providers;

use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\ServiceProvider;

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
        $this->app->make(IconRegistry::class)->fromDirectory(
            $this->package->basePath('resources/assets/svg'),
            self::class,
        );
    }
}
