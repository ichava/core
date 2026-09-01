<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava;

use Illuminate\Support\Collection;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Facades\IchavaFacade;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;
use Simtabi\Laranail\Ichava\Services\IconCacheService;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;
use Simtabi\Laranail\Ichava\Services\IconPreferenceService;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconsManifest;
use Simtabi\Laranail\Ichava\Services\RegistrationConfig;
use Simtabi\Laranail\Ichava\Support\DeferredIconsRegistry;
use Simtabi\Laranail\Ichava\Support\IconRenderer;
use Throwable;

/**
 * Backing class for the `Ichava` facade and `ichava()` helper.
 *
 * Wires the registry, browser, cache, discovery, preference, manifest, and
 * deferred-icons services behind a single injectable entry point. See README
 * for the full API surface and usage examples.
 *
 * @see IchavaFacade
 * @see IconRenderer
 */
final class Ichava
{
    public function __construct(
        private IconRegistry $registry,
        private IconBrowserService $browser,
        private IconCacheService $cache,
        private IconPreferenceService $preferences,
        private IconDiscoveryService $discovery,
        private IconsManifest $manifest,
        private IchavaLogger $logger,
        private DeferredIconsRegistry $deferredRegistry,
        private IconRenderer $iconRenderer,
    ) {}

    /**
     * Start a fluent icon render chain.
     *
     * Returns an IconRenderer pre-loaded with the given path so you can chain
     * class(), size(), aria(), etc. before calling render().
     *
     * @param  string  $name  Icon path in `vendor/package::category/name` format
     * @return IconRenderer Fluent renderer instance
     */
    public function render(string $name): IconRenderer
    {
        return $this->iconRenderer->name($name);
    }

    /**
     * Start a fluent icon package registration chain.
     *
     * Returns a RegistrationConfig so you can chain fromDirectory(), prefix(),
     * bladeComponent(), etc. before calling register().
     *
     * @param  string  $name  Unique package name (e.g. 'ichava/tabler-icons')
     */
    public function register(string $name): RegistrationConfig
    {
        return $this->registry->package($name);
    }

    /**
     * Render all queued deferred icon definitions as an SVG `<defs>` block.
     *
     * Call this once per page, typically just before `</body>`, or use the
     * `@ichava_defs` Blade directive which calls this automatically.
     *
     * @return string SVG `<defs>` block containing all `<symbol>` definitions
     */
    public function defs(): string
    {
        return $this->deferredRegistry->renderDefinitions();
    }

    /**
     * Get metadata for all registered icon packages as a collection.
     *
     * Each item contains package_name, title, description, vendor, version,
     * total icon count, base_path, prefix, and registration timestamp.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function packages(): Collection
    {
        return collect($this->registry->all());
    }

    /**
     * Get the icon list for a specific registered package.
     *
     * Delegates to IconDiscoveryService. Returns an empty collection if the
     * package is not found rather than throwing.
     *
     * @param  string  $package  Package name (e.g. 'ichava/tabler-icons')
     */
    public function icons(string $package): Collection
    {
        $packageData = $this->discovery->getPackage($package);

        return collect($packageData['icons'] ?? []);
    }

    /**
     * Search icon names across all registered packages.
     *
     * Delegates to IconDiscoveryService. Results are sorted by relevance;
     * use $limit to cap the response size for autocomplete / typeahead UIs.
     *
     * @param  string  $query  Search term (partial icon name match)
     * @param  int  $limit  Maximum number of results to return (default: 50)
     */
    public function search(string $query, int $limit = 50): Collection
    {
        $results = $this->discovery->searchIcons($query);

        return collect($results['data'] ?? [])->take($limit);
    }

    /**
     * Get a value from the ichava config, auto-prefixed with `ichava.`.
     *
     * Equivalent to `config("ichava.core.{$key}", $default)`.
     *
     * @param  string  $key  Config key relative to the ichava namespace
     * @param  mixed  $default  Value to return when the key is not set
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return config("ichava.core.{$key}", $default);
    }

    /**
     * Check whether a readable icon manifest file exists at the configured path.
     */
    public function manifestExists(): bool
    {
        return $this->manifest->exists();
    }

    /**
     * Get statistics from the icon manifest file.
     *
     * Returns null if no manifest exists. Stats include total icon count,
     * per-package breakdowns, and the manifest generation timestamp.
     *
     * @return array<string, mixed>|null
     */
    public function manifestStats(): ?array
    {
        if (! $this->manifestExists()) {
            return null;
        }

        return $this->manifest->getStats();
    }

    /**
     * Flush all Ichava icon caches.
     *
     * Returns true on success, false if the cache driver throws (e.g. Redis down).
     * Safe to call from Artisan commands, scheduled tasks, or deploy hooks.
     */
    public function clearCache(): bool
    {
        try {
            $this->cache->flush();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Direct access to the IconBrowserService (paginated icon browsing, filtering).
     */
    public function browser(): IconBrowserService
    {
        return $this->browser;
    }

    /**
     * Direct access to the IconCacheService (get/put/flush individual icon cache entries).
     */
    public function cacheService(): IconCacheService
    {
        return $this->cache;
    }

    /**
     * Direct access to the IconPreferenceService (user icon-set preferences).
     */
    public function preferencesService(): IconPreferenceService
    {
        return $this->preferences;
    }

    /**
     * Direct access to the IconDiscoveryService (icon search, package metadata lookup).
     */
    public function discoveryService(): IconDiscoveryService
    {
        return $this->discovery;
    }

    /**
     * Direct access to the IconRegistry (package registration, icon set management).
     */
    public function registryService(): IconRegistry
    {
        return $this->registry;
    }

    /**
     * Direct access to the IconsManifest (read/write the icon manifest file).
     */
    public function manifestService(): IconsManifest
    {
        return $this->manifest;
    }

    /**
     * Direct access to the IchavaLogger instance.
     */
    public function logger(): IchavaLogger
    {
        return $this->logger;
    }

    /**
     * Get a registered IconSetInterface object by name.
     *
     * @param  string  $name  Icon set name (e.g. 'ichava/tabler-icons')
     *
     * @throws IchavaException if the set is not registered
     */
    public function set(string $name): mixed
    {
        return $this->registry->set($name);
    }

    /**
     * Get an array of all registered icon set names.
     *
     * @return array<int, string>
     */
    public function sets(): array
    {
        return $this->registry->sets();
    }

    /**
     * Check whether an icon exists in the registry.
     *
     * Accepts the same path format as render(): `vendor/package::category/name`.
     * Returns false rather than throwing if the set or icon is not found.
     *
     * @param  string  $name  Icon path (e.g. 'ichava/tabler-icons::outline/home')
     * @param  string|null  $variant  Optional variant override
     * @param  string|null  $category  Optional category override
     */
    public function has(string $name, ?string $variant = null, ?string $category = null): bool
    {
        return $this->registry->has($name, $variant, $category);
    }

    /**
     * Direct access to the SvgDriver (load and render raw SVG files).
     */
    public function driver(): mixed
    {
        return $this->registry->driver();
    }

    /**
     * Register multiple icon set directories from a config array.
     *
     * Delegates to IconRegistry::registerFromConfig(). Each entry may be a plain
     * path string or an associative array with a 'path' key.
     *
     * @param  array<int|string, string|array<string, mixed>>  $sets  Config entries
     */
    public function registerFromConfig(array $sets): void
    {
        $this->registry->registerFromConfig($sets);
    }

    /**
     * Set the default icon set used when no package is specified in the icon path.
     *
     * @param  string  $name  Registered icon set name
     *
     * @throws IchavaException if the set is not registered
     */
    public function setDefaultSet(string $name): self
    {
        $this->registry->setDefault($name);

        return $this;
    }
}
