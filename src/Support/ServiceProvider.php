<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Illuminate\Support\Facades\Blade;
use Simtabi\Laranail\Package\Tools\Package;
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
     * Build the Package with every resource type it ships already switched on.
     *
     * `package-tools` defaults all of them to false, so a pack got nothing
     * unless it remembered a call. That is how every pack in this family
     * shipped `resources/lang` nothing ever registered -- the keys were
     * unreachable for the whole life of the files, which is why one pack could
     * carry another pack's translations, and a wrong licence string, without
     * anything failing. A pack should get its resources by existing.
     *
     * This used to hand-roll the directory check for translations alone, which
     * is exactly why views and configs were never covered: written as a special
     * case, it did not generalise. `loadAllResources()` is the upstream API for
     * this and covers six resource types; ichava does not re-implement it.
     *
     * Three things make this the right hook rather than `packageRegistered()`:
     *
     * - It runs before `configurePackage()`, so a pack that genuinely wants to
     *   opt out can still unset a flag there.
     * - No pack overrides it, whereas `packageRegistered()` is a documented
     *   extension point -- a child overriding that without calling `parent::`
     *   would silently lose its resources, which is the same class of quiet
     *   failure this exists to end.
     * - `getPackageBaseDir()` resolves by reflection on `static::class`, so it
     *   works here even though the framework's own `setPathFrom()` has not run.
     *
     * **This covers the packs, and not `ichava/core` itself.** Core's
     * `IchavaServiceProvider` extends `PackageServiceProvider` directly rather
     * than this class, so it inherits none of the above and must declare each
     * resource explicitly in its own `configurePackage()`. The asymmetry is
     * deliberate -- core is not an icon pack -- but it means "a pack gets its
     * resources by existing" is true of packs only. Anything added here has to
     * be applied to core separately, and that gap has already produced one
     * defect class in this family.
     */
    public function newPackage(): Package
    {
        $package = parent::newPackage();
        $base = $this->getPackageBaseDir();

        // Prime the path. `registerPackage()` sets it on the very next line,
        // but `loadAllResources()` resolves paths NOW and `Package::$basePath`
        // is '' until then -- so every check would test "/resources/..." from
        // the filesystem root, find nothing, register nothing, and return
        // fluently. No exception and no warning: a green build over a feature
        // that does not exist. Idempotent; the framework's own call repeats it.
        $package->setPathFrom($base);

        $package->loadAllResources(['configs', 'translations']);

        // The packs still call `hasConfigFile('icon-sets-<vendor>')` in their own
        // configurePackage(). That is now redundant -- autoLoadConfigs() globs
        // `config/*.php` and calls the same method -- and it is deliberately
        // kept. Upstream de-duplicates by name (`in_array` before append), so
        // the second call is a true no-op rather than a double merge; checked,
        // not assumed. Keeping it means a pack's manifest still states its
        // config file where a reader looks for it, and a pack that renames the
        // file gets a loud mismatch instead of silently following the rename.
        // Removing them across six repos to save a no-op is churn with a
        // failure mode; if they ever go, they go in one pass with a test that
        // the key still resolves.

        // Views are deliberately NOT left to loadAllResources(). Upstream's
        // autoLoadViews() registers on directory presence, and every pack in
        // this family ships `resources/views/components/.gitkeep` with no
        // templates at all -- a placeholder kept by an explicit decision. On
        // presence alone that registers a namespace which resolves nothing,
        // which is how a later reader concludes views are broken. Require a
        // template. Proposed upstream; diverging here until it lands.
        if (self::shipsABladeTemplate($base . '/resources/views')) {
            $package->hasViews();
        }

        return $package;
    }

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
     * // Registers: <x-icon-sets-tabler-icon name="outline/home" />
     * $this->loadBladeComponent(IconComponent::class, 'icon-sets-tabler');
     * ```
     *
     * @param string $componentClass Fully qualified component class name
     * @param string $packageName Package identifier in kebab-case (e.g. 'icon-sets-tabler')
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
        // Example: <x-icon-sets-tabler-icon name="home" />
        $alias = "{$packageName}-icon";

        Blade::component($alias, $componentClass);

        // Tell the registry what was actually registered, so its conflict
        // detector has something to compare. It reads `blade_component` from
        // package metadata, and until now nothing ever put it there.
        $this->app->make(IconRegistry::class)->noteBladeComponent(static::class, $alias);
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
     *     $this->loadBladeComponent(IconComponent::class, 'icon-sets-tabler');
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

    /**
     * Whether a views directory contains at least one Blade template.
     *
     * Recursive, because a pack's templates live under `components/` rather
     * than at the root of `resources/views`.
     */
    private static function shipsABladeTemplate(string $viewsPath): bool
    {
        if (! is_dir($viewsPath)) {
            return false;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($viewsPath, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                return true;
            }
        }

        return false;
    }
}
