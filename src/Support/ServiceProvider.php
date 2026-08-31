<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Providers\IchavaServiceProvider;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;

/**
 * Abstract base that every Ichava icon package extends.
 *
 * Provides the Blade-component registration convention and enforces the
 * correct boot order relative to `IchavaServiceProvider` (which owns the
 * log channels). See README § "Creating Custom Icon Packages" for the full
 * lifecycle contract and an example implementation.
 *
 * @see IchavaServiceProvider
 * @see IconRegistry
 */
abstract class ServiceProvider extends PackageServiceProvider
{
    /**
     * The Ichava icon ecosystem identifier.
     *
     * Used throughout the ecosystem for namespacing and identification.
     * Do not change this value in child packages.
     */
    public const string ICON_ECOSYSTEM_NAME = 'ichava';

    /**
     * Called before the package's bindings are registered.
     *
     * The core Ichava services (IconRegistry, IchavaLogger, SvgDriver, etc.) are NOT
     * yet bound at this point, they are registered by IchavaServiceProvider, which
     * runs first due to auto-discovery order in composer.json.
     *
     * Rules for child icon packages:
     * - Do NOT register log channels here (they belong to IchavaServiceProvider).
     * - Do NOT call app()->make(IconRegistry::class) here, it is not yet bound.
     * - Do NOT log anything in this hook.
     *
     * Most icon packages do not need to override this method at all.
     */
    public function registeringPackage(): void {}

    /**
     * Called immediately after the package's config file is merged into the app.
     *
     * Config values from `config/{package}.php` are accessible here via config().
     * Use this hook if you need to read config during the registration phase
     * (e.g. to conditionally bind extra services based on a flag).
     *
     * Most icon packages do not need to override this method.
     */
    public function packageRegistered(): void {}

    /**
     * Register a Blade component using the Ichava naming convention.
     *
     * Registers the component under the tag `<x-{packageName}-icon name="..." />`.
     * Call this from bootingPackage() in your child service provider.
     *
     * Example:
     * ```php
     * // Registers: <x-tabler-icons-icon name="outline/home" />
     * $this->loadBladeComponent(TablerIconComponent::class, 'tabler-icons');
     * ```
     *
     * @param string $componentClass Fully qualified component class name
     * @param string $packageName Package identifier in kebab-case (e.g. 'tabler-icons')
     *
     * @throws IchavaException if $packageName is empty
     */
    protected function loadBladeComponent(string $componentClass, string $packageName): void
    {
        if (empty($packageName)) {
            throw IchavaException::invalidConfiguration('Package name cannot be empty for Blade component registration');
        }

        $packageName = Str::lower($packageName);

        // Register as: <x-{package}-icon name="..." />
        // Example: <x-tabler-icons-icon name="home" />
        Blade::component("{$packageName}-icon", $componentClass);
    }

    /**
     * Convenience wrapper: register an SVG icon set directory with the global IconRegistry.
     *
     * Delegates to IconRegistry::fromDirectory() with the current provider class as the
     * attribution source. The directory must contain a valid `config.json` file.
     *
     * Typical usage in bootingPackage():
     * ```php
     * public function bootingPackage(): void
     * {
     *     $this->loadBladeComponent(TablerIconComponent::class, 'tabler-icons');
     *     $this->registerIconDirectory($this->package->basePath('resources/assets/svg'));
     * }
     * ```
     *
     * @param string $path Absolute path to the icon set directory (must contain config.json)
     *
     * @throws IchavaException if config.json is missing or malformed
     */
    protected function registerIconDirectory(string $path): void
    {
        $this->app->make(IconRegistry::class)->fromDirectory($path, static::class);
    }

    /**
     * Bulk-register multiple icon set sub-directories using IchavaRegistrar.
     *
     * Designed for large bundles (e.g. 70+ icon sets). Each key in $iconSets must be
     * a sub-directory name under $basePath; sub-directories that do not exist are
     * silently skipped. Returns the IchavaRegistrar so you can chain
     * trackStatistics() and enableLogging() afterward.
     *
     * Typical usage in bootingPackage():
     * ```php
     * $this->registerBulkIconSets(
     *     basePath: $this->package->basePath('resources/assets/svg'),
     *     iconSets: $this->iconSets,         // ['fontawesome' => [...], 'bootstrap' => [...]]
     *     vendor:   'Icons Bundle',
     * )->trackStatistics()->enableLogging();
     * ```
     *
     * @param string $basePath Absolute path that contains the icon set sub-directories
     * @param array<string, array<string, mixed>> $iconSets Map of dirName => metadata
     * @param string $vendor Human-readable vendor label (used in log output only)
     *
     * @return IchavaRegistrar Fluent registrar (chain trackStatistics()/enableLogging())
     */
    protected function registerBulkIconSets(string $basePath, array $iconSets, string $vendor = ''): IchavaRegistrar
    {
        return IchavaRegistrar::register($this->package->name ?? static::class)
            ->basePath($basePath)
            ->providerClass(static::class)
            ->vendor($vendor)
            ->configure($iconSets);
    }

    /**
     * Return the standard SVG assets path for this package.
     *
     * Resolves to `{package-root}/resources/assets/svg` by default, which is the
     * conventional location for icon SVG files in the Ichava ecosystem.
     * Override in child providers only if your package uses a non-standard layout.
     *
     * @param string $subPath Optional sub-directory to append (e.g. 'outline', 'solid')
     *
     * @return string Absolute filesystem path
     */
    protected function svgAssetsPath(string $subPath = ''): string
    {
        $base = $this->package->basePath('resources/assets/svg');

        return $subPath
            ? rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($subPath, '/\\')
            : $base;
    }
}
