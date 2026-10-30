<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Facades;

use Simtabi\Laranail\Ichava\Ichava;
use Illuminate\Support\Facades\Facade;

/**
 * IchavaFacade - Static Facade for the Ichava Ecosystem
 *
 * Provides static proxy access to the Ichava backing class registered in the
 * service container as `'ichava'`. All @method annotations below correspond
 * directly to public methods on \Simtabi\Laranail\Ichava\Ichava.
 *
 * Typical usage:
 * ```php
 * use Simtabi\Laranail\Ichava\Facades\IchavaFacade as Ichava;
 *
 * Ichava::render('ichava/icon-sets-tabler::outline/home')->class('w-5 h-5')->render();
 * Ichava::packages()->count();          // total registered packages
 * Ichava::search('arrow', 20);           // first 20 icons matching 'arrow'
 * Ichava::clearCache();                  // flush all icon caches
 * ```
 *
 *
 * @method static \Simtabi\Laranail\Ichava\Support\IconRenderer render(string $name)
 * @method static \Simtabi\Laranail\Ichava\Support\IchavaRegistrar register(string $name)
 * @method static string defs()
 * @method static \Illuminate\Support\Collection<string, array<string, mixed>> packages()
 * @method static \Illuminate\Support\Collection<array-key, mixed> icons(string $package)
 * @method static \Illuminate\Support\Collection<array-key, mixed> search(string $query, int $limit = 50)
 * @method static mixed config(string $key, mixed $default = null)
 * @method static bool manifestExists()
 * @method static array<string, mixed>|null manifestStats()
 * @method static bool clearCache()
 * @method static \Simtabi\Laranail\Ichava\Services\IconBrowserService browser()
 * @method static \Simtabi\Laranail\Ichava\Services\IconCacheService cacheService()
 * @method static \Simtabi\Laranail\Ichava\Services\IconPreferenceService preferencesService()
 * @method static \Simtabi\Laranail\Ichava\Services\IconDiscoveryService discoveryService()
 * @method static \Simtabi\Laranail\Ichava\Services\IconRegistry registryService()
 * @method static \Simtabi\Laranail\Ichava\Services\IconsManifest manifestService()
 * @method static \Simtabi\Laranail\Ichava\Services\IchavaLogger logger()
 * @method static \Simtabi\Laranail\Ichava\Contracts\IconSetInterface set(string $name)
 * @method static array<int, string> sets()
 * @method static bool has(string $name, ?string $variant = null, ?string $category = null)
 * @method static \Simtabi\Laranail\Ichava\Drivers\SvgDriver driver()
 * @method static \Simtabi\Laranail\Ichava\Ichava setDefaultSet(string $name)
 * @method static void registerFromConfig(array<int|string, string|array<string, mixed>> $sets)
 * @method static \Simtabi\Laranail\Ichava\Ichava resolveUserUsing(\Closure $resolver)
 * @method static \Simtabi\Laranail\Ichava\Ichava presentUserUsing(\Closure $presenter)
 * @method static \Illuminate\Contracts\Auth\Authenticatable|null user()
 * @method static \Simtabi\Laranail\Ichava\Data\UserData|null currentUser()
 * @method static \Simtabi\Laranail\Ichava\Data\StatusData status()
 *
 * @see Ichava
 */
class IchavaFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Ichava::class;
    }
}
