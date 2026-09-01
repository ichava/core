<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Facades;

use Illuminate\Support\Facades\Facade;
use Simtabi\Laranail\Ichava\Ichava;

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
 * Ichava::render('ichava/tabler-icons::outline/home')->class('w-5 h-5')->render();
 * Ichava::packages()->count();          // total registered packages
 * Ichava::search('arrow', 20);           // first 20 icons matching 'arrow'
 * Ichava::clearCache();                  // flush all icon caches
 * ```
 *
 *
 * @method static \Simtabi\Laranail\Ichava\Support\IconRenderer render(string $name)
 * @method static \Simtabi\Laranail\Ichava\Support\IchavaRegistrar register(string $name)
 * @method static string defs()
 * @method static \Illuminate\Support\Collection packages()
 * @method static \Illuminate\Support\Collection icons(string $package)
 * @method static \Illuminate\Support\Collection search(string $query, int $limit = 50)
 * @method static mixed config(string $key, mixed $default = null)
 * @method static bool manifestExists()
 * @method static array|null manifestStats()
 * @method static bool clearCache()
 * @method static \Simtabi\Laranail\Ichava\Services\IconBrowserService browser()
 * @method static \Simtabi\Laranail\Ichava\Services\IconCacheService cacheService()
 * @method static \Simtabi\Laranail\Ichava\Services\IconPreferenceService preferencesService()
 * @method static \Simtabi\Laranail\Ichava\Services\IconDiscoveryService discoveryService()
 * @method static \Simtabi\Laranail\Ichava\Services\IconRegistry registryService()
 * @method static \Simtabi\Laranail\Ichava\Services\IconsManifest manifestService()
 * @method static \Simtabi\Laranail\Ichava\Services\IchavaLogger logger()
 * @method static \Simtabi\Laranail\Ichava\Interfaces\IconSetInterface set(string $name)
 * @method static array sets()
 * @method static bool has(string $name, ?string $variant = null, ?string $category = null)
 * @method static \Simtabi\Laranail\Ichava\Drivers\SvgDriver driver()
 * @method static \Simtabi\Laranail\Ichava\Ichava setDefaultSet(string $name)
 * @method static void registerFromConfig(array $sets)
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
