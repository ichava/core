<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Simtabi\Laranail\Ichava\Contracts\IconSetInterface;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Simtabi\Laranail\Ichava\Events\IconRegistrationEvent;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Support\IchavaRegistrar;
use Simtabi\Laranail\Ichava\Support\PathResolver;
use Throwable;

/**
 * IconRegistry - Icon Package Registration & Management
 *
 * Single source of truth for all icon packages registered in the Ichava ecosystem.
 * Manages icon set objects alongside their package metadata, and coordinates
 * deferred registration, conflict detection, and lifecycle events.
 *
 * Responsibilities:
 * - Icon set registration and lookup (used by the renderer and discovery service)
 * - Package metadata storage (used by the browser and stats endpoints)
 * - Fluent `package()` / `fromDirectory()` configuration API
 * - Boot-time deferred registration (waits until `app()->booted()`)
 * - Conflict detection for icon set name, prefix, and Blade component clashes
 *
 * Use `fromDirectory()` for simple single-directory packages, or `package()` for
 * full fluent configuration. Both return a `RegistrationConfig` for chaining.
 *
 * @see RegistrationConfig
 * @see IchavaRegistrar
 */
final class IconRegistry
{
    /**
     * Registered icon sets (IconSetInterface objects)
     *
     * @var array<string, IconSetInterface>
     */
    private array $sets = [];

    /**
     * Package metadata (for browser, stats, etc.)
     *
     * @var array<string, array<string, mixed>>
     */
    private array $packages = [];

    /**
     * Package conflicts (for validation)
     *
     * @var array<string, array<string, string>>
     */
    private array $conflicts = [];

    /**
     * Default icon set name
     */
    private ?string $defaultSet = null;

    /**
     * Pending registrations (lazy loading)
     *
     * @var array<string, RegistrationConfig>
     */
    private array $pending = [];

    /**
     * Bootstrap state
     */
    private bool $bootstrapped = false;

    public function __construct(
        private Application $app,
        private SvgDriver $driver,
        private IchavaLogger $logger,
        private PathResolver $pathResolver,
    ) {
        $this->initializeBootstrap();
    }

    /**
     * Start registering an icon package
     *
     * @example Basic registration
     * IconRegistry::package('my-icons')
     *     ->fromDirectory('/path/to/icons')
     *     ->prefix('my')
     *     ->register();
     * @example With config.json
     * IconRegistry::package('my-icons')
     *     ->fromConfigFile('/path/to/config.json')
     *     ->register();
     */
    public function package(string $name): RegistrationConfig
    {
        $config = new RegistrationConfig($name, $this);
        $this->pending[$name] = $config;

        return $config;
    }

    /**
     * Register multiple icon set directories from a config array.
     *
     * Each entry may be a plain path string or an associative array with a 'path' key.
     * Invalid or empty paths are silently skipped. Useful for registering custom icon
     * sets declared in ichava.custom-icons.sets config.
     *
     * @param  array<int|string, string|array<string, mixed>>  $sets
     *                                                                Example: ['/path/to/set', ['path' => '/other/set', 'label' => 'Custom']]
     */
    public function registerFromConfig(array $sets): void
    {
        foreach ($sets as $set) {
            $path = is_array($set) ? ($set['path'] ?? null) : $set;

            if (is_string($path) && $path !== '') {
                $this->fromDirectory($path);
            }
        }
    }

    /**
     * Auto-register an icon set from a directory that contains a config.json file.
     *
     * Reads `{$path}/config.json` to extract the package name, title, prefix,
     * variants, categories, and metadata. Counts SVG files in `{$path}/files/`
     * to populate the icon total. Immediately registers the set and returns a
     * RegistrationConfig for optional further chaining.
     *
     * @param  string  $path  Absolute path to the icon set directory (must contain config.json)
     * @param  string|null  $providerClass  The service provider that registered this set (for attribution)
     *
     * @throws IchavaException if config.json is missing or malformed
     */
    public function fromDirectory(string $path, ?string $providerClass = null): RegistrationConfig
    {
        // Load config.json using helper method (no ConfigurationService dependency!)
        $configData = $this->loadPackageConfig($path);
        $packageName = $configData['package']['name'];

        // Create icon set
        $iconSet = IconSetBuilder::make($packageName)
            ->setBasePath($path.'/files')
            ->prefix($configData['config']['icon_prefix']);

        // Add variants if present
        if ($this->hasVariants($configData)) {
            $variants = array_keys($this->getVariants($configData));
            if (! empty($variants)) {
                $iconSet->withVariants($variants);
            }
        }

        // Add categories if present
        if ($this->hasCategories($configData)) {
            $iconSet->withCategories(true);
        }

        // Count icons
        $totalIcons = $this->countIconsInDirectory($path.'/files');

        // Build metadata
        $metadata = [
            'package_name' => $packageName,
            'name' => $configData['package']['title'],
            'description' => $configData['package']['description'] ?? '',
            'vendor' => $this->getVendor($configData),
            'version' => $configData['package']['version'],
            'license' => $configData['package']['license'] ?? 'Unknown',
            'homepage' => $configData['metadata']['homepage'] ?? null,
            'repository' => $configData['metadata']['repository'] ?? null,
            'keywords' => $configData['package']['keywords'] ?? [],
            'total' => $totalIcons,
            'base_path' => $path,
            'icon_set_name' => $packageName,
            'provider_class' => $providerClass,
            'prefix' => $configData['config']['icon_prefix'],
        ];

        // Register immediately
        return $this->registerIconSet($packageName, $iconSet, $metadata);
    }

    /**
     * Register an icon set directly with pre-built metadata (advanced use).
     *
     * This is the internal registration path called by fromDirectory() and package().
     * It handles in-memory deduplication (skips if already registered), metadata
     * validation, conflict detection, log deduplication, and event dispatch.
     *
     * @param  string  $name  Unique icon set name (e.g. 'ichava/tabler-icons')
     * @param  IconSetInterface  $set  The icon set object
     * @param  array<string, mixed>  $metadata  Package metadata (package_name, base_path, etc.)
     * @return RegistrationConfig Chainable config (already marked as registered)
     */
    public function registerIconSet(
        string $name,
        IconSetInterface $set,
        array $metadata = [],
    ): RegistrationConfig {
        // Skip if already registered in this request (in-memory check)
        if ($this->hasSet($name)) {
            return (new RegistrationConfig($name, $this))->markRegistered();
        }

        // Validate metadata
        $this->validateMetadata($name, $metadata);

        // Check conflicts
        $this->checkConflicts($name, $metadata);

        // Store icon set
        $this->sets[$name] = $set;

        // Store metadata
        $this->packages[$name] = array_merge([
            'registered_at' => now()->toIso8601String(),
            'package_name' => $name,
        ], $metadata);

        // Set as default if first
        if ($this->defaultSet === null) {
            $this->defaultSet = $name;
        }

        // Log only if not recently logged (cache-based deduplication across requests)
        $this->logRegistrationOnce($name, $metadata);

        // Fire event only if not recently fired (prevents duplicate listeners)
        $this->dispatchRegistrationEventOnce($name, $metadata);

        // Return config for chaining
        $config = new RegistrationConfig($name, $this);
        $config->markRegistered();

        return $config;
    }

    /**
     * Get a registered icon set
     */
    public function set(string $name): IconSetInterface
    {
        if (! isset($this->sets[$name])) {
            throw IchavaException::iconSetNotFound($name);
        }

        return $this->sets[$name];
    }

    /**
     * Get all registered icon set names
     *
     * @return array<int, string>
     */
    public function sets(): array
    {
        return array_keys($this->sets);
    }

    /**
     * Check if an icon set exists
     */
    public function hasSet(string $name): bool
    {
        return isset($this->sets[$name]);
    }

    /**
     * Set the default icon set
     */
    public function setDefault(string $name): static
    {
        if (! isset($this->sets[$name])) {
            throw IchavaException::iconSetNotFound($name);
        }

        $this->defaultSet = $name;

        return $this;
    }

    /**
     * Get the default icon set name
     */
    public function getDefault(): ?string
    {
        return $this->defaultSet;
    }

    /**
     * Check if an icon exists
     */
    public function has(string $name, ?string $variant = null, ?string $category = null): bool
    {
        try {
            $iconPath = $this->pathResolver->parseIconPath($name);
            $set = $this->set($iconPath->set ?? $this->defaultSet);

            return $set->has(
                $iconPath->name,
                $variant ?? $iconPath->variant,
                $category ?? $iconPath->category,
            );
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Resolve and render an icon to an HTML SVG string.
     *
     * Parses the icon path, locates the registered icon set, merges the set's
     * default class and attributes with any passed $attributes, and delegates
     * to SvgDriver. If the icon is not found, attempts the set's configured
     * fallback icon before throwing.
     *
     * @param  string  $name  Icon path (e.g. 'ichava/tabler-icons::outline/home')
     * @param  string|null  $variant  Variant override (e.g. 'outline', 'solid')
     * @param  string|null  $category  Category override
     * @param  array<string, mixed>  $attributes  HTML attributes to inject onto the SVG element
     * @return string Rendered SVG HTML
     *
     * @throws IchavaException if the set or icon is not found and no fallback exists
     */
    public function render(
        string $name,
        ?string $variant = null,
        ?string $category = null,
        array $attributes = [],
    ): string {
        $iconPath = $this->pathResolver->parseIconPath($name);
        $setName = $iconPath->set ?? $this->defaultSet;

        if (! $setName) {
            throw IchavaException::iconNotFound($name, 'No default icon set configured');
        }

        $set = $this->set($setName);
        $icon = $set->get(
            $iconPath->name,
            $variant ?? $iconPath->variant,
            $category ?? $iconPath->category,
        );

        // Try fallback icon if not found
        if (! $icon && $set->config()->fallback) {
            $icon = $set->get($set->config()->fallback);
        }

        if (! $icon) {
            throw IchavaException::iconNotFoundInSet($iconPath->name, $set->name());
        }

        $defaultAttributes = [
            'class' => $set->config()->defaultClass,
            ...$set->config()->defaultAttributes,
        ];

        $attributes = array_merge($defaultAttributes, $attributes);

        return $this->driver->render($icon, $attributes);
    }

    /**
     * Get all registered packages
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->packages;
    }

    /**
     * Get a specific package's metadata
     *
     * @return array<string, mixed>
     */
    public function get(string $packageName): array
    {
        if (! isset($this->packages[$packageName])) {
            throw IchavaException::packageNotRegistered($packageName);
        }

        return $this->packages[$packageName];
    }

    /**
     * Check if a package is registered
     */
    public function isRegistered(string $packageName): bool
    {
        return isset($this->packages[$packageName]);
    }

    /**
     * Unregister a package, its icon sets, and any cached metadata.
     *
     * Dispatches `IconRegistrationEvent::unregistered(...)` so the
     * `AutoUnseedOnUnregistration` listener can clear DB rows and orphaned
     * terms, and the cache layer can flush its manifest. Idempotent: a no-op
     * when the package is not registered.
     *
     * @return bool True when something was actually removed.
     */
    public function unregister(string $packageName): bool
    {
        if (! isset($this->packages[$packageName])) {
            return false;
        }

        $metadata = $this->packages[$packageName];
        $iconSetName = $metadata['icon_set_name'] ?? $packageName;

        unset(
            $this->packages[$packageName],
            $this->sets[$iconSetName],
            $this->pending[$packageName],
            $this->pending[$iconSetName],
        );

        // Drop any conflict markers that referenced this package or its set.
        foreach (array_keys($this->conflicts) as $conflictType) {
            unset(
                $this->conflicts[$conflictType][$packageName],
                $this->conflicts[$conflictType][$iconSetName],
            );
            if (empty($this->conflicts[$conflictType])) {
                unset($this->conflicts[$conflictType]);
            }
        }

        if ($this->defaultSet === $iconSetName) {
            $this->defaultSet = null;
        }

        Event::dispatch(IconRegistrationEvent::unregistered(
            registrarId: $packageName,
            name: $packageName,
            metadata: $metadata,
        ));

        return true;
    }

    /**
     * Get registered packages count
     */
    public function count(): int
    {
        return count($this->packages);
    }

    /**
     * Get package by icon set name
     *
     * @return array<string, mixed>|null
     */
    public function getByIconSet(string $iconSetName): ?array
    {
        foreach ($this->packages as $package) {
            if (($package['icon_set_name'] ?? null) === $iconSetName) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Get conflicts
     *
     * @return array<string, array<string, string>>
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * Count the total number of SVG files in a directory (recursive).
     *
     * Returns 0 if the path does not exist or is not a directory.
     * Used to populate the `total` field in package metadata.
     *
     * @param  string  $path  Directory to scan
     * @return int Total SVG file count
     */
    public function countIconsInDirectory(string $path): int
    {
        if (! File::isDirectory($path)) {
            return 0;
        }

        $count = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && Str::endsWith($file->getFilename(), '.svg')) {
                    $count++;
                }
            }
        } catch (Exception $e) {
            // Non-fatal: return 0 so callers (statistics / diagnostics) keep working.
            // Log at debug level so issues with unreadable paths are discoverable.
            $this->logger->debug('⚠️ countSvgFiles failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $count;
    }

    /**
     * Direct access to the SvgDriver instance (load, process, render SVG files).
     */
    public function driver(): SvgDriver
    {
        return $this->driver;
    }

    /**
     * Log package registration at most once per cache TTL to prevent log spam.
     *
     * Uses a cache key `ichava:registered:{name}` to suppress repeated log entries
     * across requests during the TTL window (default: 300s). A TTL of 0 disables
     * deduplication. Falls back to always logging if the cache is unavailable.
     *
     * @param  string  $name  Icon set name
     * @param  array<string, mixed>  $metadata  Package metadata
     */
    private function logRegistrationOnce(string $name, array $metadata): void
    {
        $ttl = (int) config('ichava.core.logging.deduplication_ttl', 300);

        // TTL of 0 disables deduplication
        if ($ttl === 0) {
            $this->logger->info('📦 Icon package registered', [
                'package' => $name,
                'icon_count' => $metadata['total'] ?? 0,
            ]);

            return;
        }

        // Try cache-based deduplication, fallback to always logging if cache unavailable
        try {
            $cacheKey = "ichava:registered:{$name}";

            if (cache()->has($cacheKey)) {
                return;
            }

            cache()->put($cacheKey, true, $ttl);
        } catch (Throwable) {
            // Cache unavailable, log anyway (first-time setup, Redis down, etc.)
        }

        $this->logger->info('📦 Icon package registered', [
            'package' => $name,
            'icon_count' => $metadata['total'] ?? 0,
        ]);
    }

    /**
     * Dispatch an IconRegistrationEvent at most once per cache TTL.
     *
     * Uses a cache key `ichava:event:{name}` to suppress duplicate events within
     * the TTL window. Falls back to always dispatching if the cache is unavailable.
     *
     * @param  string  $name  Icon set name
     * @param  array<string, mixed>  $metadata  Package metadata
     */
    private function dispatchRegistrationEventOnce(string $name, array $metadata): void
    {
        $ttl = (int) config('ichava.core.logging.deduplication_ttl', 300);

        // TTL of 0 disables deduplication
        if ($ttl === 0) {
            Event::dispatch(IconRegistrationEvent::registered(
                Str::uuid()->toString(),
                $name,
                $metadata,
                0,
            ));

            return;
        }

        // Try cache-based deduplication, fallback to always dispatching if cache unavailable
        try {
            $cacheKey = "ichava:event:{$name}";

            if (cache()->has($cacheKey)) {
                return;
            }

            cache()->put($cacheKey, true, $ttl);
        } catch (Throwable) {
            // Cache unavailable, dispatch anyway
        }

        Event::dispatch(IconRegistrationEvent::registered(
            Str::uuid()->toString(),
            $name,
            $metadata,
            0,
        ));
    }

    /**
     * Validate that the package metadata contains the required fields.
     *
     * Required fields: package_name, icon_set_name, base_path.
     * Also verifies that base_path is an existing directory.
     *
     * @param  string  $packageName  Package being registered (for error messages)
     * @param  array<string, mixed>  $metadata
     *
     * @throws IchavaException if required fields are missing or base_path does not exist
     */
    private function validateMetadata(string $packageName, array $metadata): void
    {
        $required = ['package_name', 'icon_set_name', 'base_path'];

        $missing = [];
        foreach ($required as $field) {
            if (! isset($metadata[$field]) || empty($metadata[$field])) {
                $missing[] = $field;
            }
        }

        if (! empty($missing)) {
            throw IchavaException::missingPackageMetadata($packageName, $missing);
        }

        // Validate base path exists
        if (! File::isDirectory($metadata['base_path'])) {
            throw IchavaException::packagePathNotFound(
                $packageName,
                $metadata['base_path'],
            );
        }
    }

    /**
     * Check for icon set name, prefix, and Blade component conflicts.
     *
     * Conflicts are non-fatal (logged as warnings, stored in $this->conflicts)
     * rather than exceptions, since partial overlap is acceptable in some setups.
     * Three conflict vectors are checked:
     * - icon_set_name, two packages share the same internal set name
     * - prefix       , two packages use the same icon prefix (ambiguous lookups)
     * - blade_component, two packages register the same Blade component alias
     *
     * @param  string  $packageName  Package being registered
     * @param  array<string, mixed>  $metadata
     */
    private function checkConflicts(string $packageName, array $metadata): void
    {
        $iconSetName = $metadata['icon_set_name'] ?? null;
        $prefix = $metadata['prefix'] ?? null;
        $bladeComponent = $metadata['blade_component'] ?? null;

        // Check icon set name conflict
        if ($iconSetName) {
            foreach ($this->packages as $existingPackage => $existingMeta) {
                if ($existingPackage !== $packageName &&
                    ($existingMeta['icon_set_name'] ?? null) === $iconSetName) {

                    $this->conflicts['icon_set_name'][$iconSetName] = [
                        'existing' => $existingPackage,
                        'new' => $packageName,
                    ];

                    $this->logger->warning('⚠️ Icon set name conflict', [
                        'icon_set' => $iconSetName,
                        'packages' => [$existingPackage, $packageName],
                    ]);
                }
            }
        }

        // Check prefix conflict (warning only)
        if ($prefix) {
            $prefixConflicts = [];
            foreach ($this->packages as $existingPackage => $existingMeta) {
                if (($existingMeta['prefix'] ?? null) === $prefix) {
                    $prefixConflicts[] = $existingPackage;
                }
            }

            if (! empty($prefixConflicts)) {
                $prefixConflicts[] = $packageName;
                $this->conflicts['prefix'][$prefix] = $prefixConflicts;

                $this->logger->warning('⚠️ Prefix conflict', [
                    'prefix' => $prefix,
                    'packages' => $prefixConflicts,
                ]);
            }
        }

        // Check blade component conflict (warning only)
        if ($bladeComponent) {
            $componentConflicts = [];
            foreach ($this->packages as $existingPackage => $existingMeta) {
                if (($existingMeta['blade_component'] ?? null) === $bladeComponent) {
                    $componentConflicts[] = $existingPackage;
                }
            }

            if (! empty($componentConflicts)) {
                $componentConflicts[] = $packageName;
                $this->conflicts['blade_component'][$bladeComponent] = $componentConflicts;

                $this->logger->warning('⚠️ Blade component conflict', [
                    'component' => $bladeComponent,
                    'packages' => $componentConflicts,
                ]);
            }
        }
    }

    /**
     * Read and parse the config.json file from an icon set directory.
     *
     * Delegates to Helpers::loadConfigJson(). Throws if the file is missing
     * or cannot be parsed as valid JSON.
     *
     * @param  string  $directoryPath  Absolute path to the icon set directory
     * @return array<string, mixed> Parsed config data
     *
     * @throws IchavaException via Helpers::loadConfigJson() on missing/invalid config
     */
    private function loadPackageConfig(string $directoryPath): array
    {
        return Helpers::loadConfigJson($directoryPath);
    }

    /**
     * Whether the parsed config.json declares any icon variants.
     *
     * @param  array<string, mixed>  $config  Parsed config.json data
     */
    private function hasVariants(array $config): bool
    {
        return isset($config['variants']) && is_array($config['variants']) && ! empty($config['variants']);
    }

    /**
     * Extract the variants map from parsed config.json data.
     *
     * @param  array<string, mixed>  $config  Parsed config.json data
     * @return array<string, mixed> Variants keyed by variant name
     */
    private function getVariants(array $config): array
    {
        return $config['variants'] ?? [];
    }

    /**
     * Whether the parsed config.json declares any icon categories.
     *
     * @param  array<string, mixed>  $config  Parsed config.json data
     */
    private function hasCategories(array $config): bool
    {
        return isset($config['categories']) && is_array($config['categories']) && ! empty($config['categories']);
    }

    /**
     * Extract the vendor name from the package name in config.json.
     *
     * Delegates to Helpers::getVendorFromPackage() which splits on `/`.
     *
     * @param  array<string, mixed>  $config  Parsed config.json data
     * @return string Vendor name (e.g. 'ichava' from 'ichava/tabler-icons')
     */
    private function getVendor(array $config): string
    {
        $packageName = $config['package']['name'] ?? '';

        return Helpers::getVendorFromPackage($packageName);
    }

    /**
     * Register the app booted callback that flushes pending registrations.
     *
     * Called once by the constructor. Idempotent, subsequent calls are no-ops.
     * Pending registrations (queued via package()) are executed after all service
     * providers have booted to prevent boot-order dependency issues.
     */
    private function initializeBootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->app->booted(function () {
            $this->executePendingRegistrations();
        });

        $this->bootstrapped = true;
    }

    /**
     * Execute all RegistrationConfig instances that have not yet been registered.
     *
     * Called by the app booted callback set up in initializeBootstrap().
     * Clears the pending queue after execution.
     */
    private function executePendingRegistrations(): void
    {
        foreach ($this->pending as $name => $config) {
            if (! $config->isRegistered()) {
                $config->execute();
            }
        }

        $this->pending = [];
    }
}
