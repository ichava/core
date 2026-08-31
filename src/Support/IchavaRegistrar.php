<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;

/**
 * Fluent helper for registering multiple icon-set sub-directories from one
 * shared base path, intended for large bundles. Single-package consumers
 * should use `IconRegistry::fromDirectory()` directly.
 *
 * See README § "IchavaRegistrar, Bulk Registration Helper" for usage.
 *
 * @see IconRegistry
 * @see ServiceProvider
 */
class IchavaRegistrar
{
    protected string $name;

    protected string $basePath = '';

    protected string $packagePrefix = '';

    protected string $iconSetSuffix = '';

    protected string $bladeNamespace = '';

    protected string $providerClass = '';

    protected string $vendor = '';

    protected array $iconSets = [];

    protected bool $trackStats = false;

    protected bool $logging = false;

    protected int $registered = 0;

    protected int $skipped = 0;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Start building a registrar for the given bundle name.
     *
     * @param string $name A unique identifier for this icon bundle (e.g. 'icons-bundle').
     */
    public static function register(string $name): static
    {
        return new static($name);
    }

    /**
     * Absolute path to the directory that contains the icon-set sub-directories.
     *
     * Use PathResolver::svgPathFromIconSet() to resolve this from a relative path:
     *
     *   ->basePath(PathResolver::svgPathFromIconSet('platform/icons-bundle/assets'))
     */
    public function basePath(string $path): static
    {
        $this->basePath = $path;

        return $this;
    }

    /**
     * Optional prefix for icon set namespacing (e.g. 'icons-bundle').
     *
     * Extensibility hook: stored on the registrar and available inside a custom
     * registerIconSet() override. Not used by the default implementation.
     */
    public function packagePrefix(string $prefix): static
    {
        $this->packagePrefix = $prefix;

        return $this;
    }

    /**
     * Optional suffix appended to each icon set namespace (e.g. '-bundle').
     *
     * Extensibility hook: stored on the registrar and available inside a custom
     * registerIconSet() override. Not used by the default implementation.
     */
    public function iconSetSuffix(string $suffix): static
    {
        $this->iconSetSuffix = $suffix;

        return $this;
    }

    /**
     * Blade namespace prefix for icon components (e.g. 'icons-bundle').
     *
     * Extensibility hook: stored on the registrar and available inside a custom
     * registerIconSet() override. Not used by the default implementation, which
     * only calls IconRegistry::fromDirectory(). Override registerIconSet() to
     * act on this value (e.g. to call Blade::component() per icon set).
     */
    public function bladeNamespace(string $namespace): static
    {
        $this->bladeNamespace = $namespace;

        return $this;
    }

    /**
     * The FQCN of the ServiceProvider calling this registrar.
     * Passed to IconRegistry for source-tracking. Defaults to IchavaRegistrar::class.
     *
     *   ->providerClass(self::class)
     */
    public function providerClass(string $class): static
    {
        $this->providerClass = $class;

        return $this;
    }

    /**
     * Human-readable vendor label (used in logging output).
     */
    public function vendor(string $vendor): static
    {
        $this->vendor = $vendor;

        return $this;
    }

    /**
     * Provide the icon sets array and trigger registration immediately.
     *
     * Array format: [ 'dir-name' => ['name' => '...', 'prefix' => '...'], ... ]
     *
     * Each key is a sub-directory name under basePath. Missing directories
     * are skipped automatically.
     *
     * This MUST be called to trigger processing, call it after all
     * configuration methods, then chain trackStatistics()/enableLogging().
     */
    public function configure(array $iconSets): static
    {
        $this->iconSets = $iconSets;
        $this->process();

        return $this;
    }

    /**
     * Enable registration statistics (registered count, skipped count).
     * Totals are included in the log output when enableLogging() is also called.
     */
    public function trackStatistics(): static
    {
        $this->trackStats = true;

        return $this;
    }

    /**
     * Log registration results to the 'ichava' log channel.
     * Call after configure() so the counts are already populated.
     */
    public function enableLogging(): static
    {
        $this->logging = true;

        if ($this->registered > 0 || $this->skipped > 0) {
            $message = sprintf(
                'IchavaRegistrar [%s] (%s): registered %d set(s), skipped %d.',
                $this->name,
                $this->vendor ?: 'unknown vendor',
                $this->registered,
                $this->skipped,
            );

            app(IchavaLogger::class)->debug($message);
        }

        return $this;
    }

    /**
     * Iterate over configured icon sets and register each valid directory.
     */
    protected function process(): void
    {
        if (! File::isDirectory($this->basePath)) {
            return;
        }

        foreach ($this->iconSets as $dirName => $meta) {
            $path = rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . $dirName;

            if (! File::isDirectory($path)) {
                $this->skipped++;

                continue;
            }

            $this->registerIconSet($path);
            $this->registered++;
        }
    }

    /**
     * Delegate a single directory to IconRegistry::fromDirectory().
     * No-ops gracefully if IconRegistry is not yet bound (should not happen
     * when called from boot(), but safe-guards against mis-use in register()).
     */
    protected function registerIconSet(string $path): void
    {
        if (! app()->bound(IconRegistry::class)) {
            return;
        }

        app(IconRegistry::class)->fromDirectory(
            $path,
            $this->providerClass ?: static::class,
        );
    }
}
