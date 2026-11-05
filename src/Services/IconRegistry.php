<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Simtabi\Laranail\Ichava\Contracts\IconDriverInterface;
use Exception;
use Throwable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Data\BulkResult;
use Simtabi\Laranail\Ichava\Data\BulkOptions;
use Simtabi\Laranail\Ichava\Enums\KindSource;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Illuminate\Contracts\Foundation\Application;
use Simtabi\Laranail\Ichava\Data\KindResolution;
use Simtabi\Laranail\Ichava\Data\PackDefinition;
use Simtabi\Laranail\Ichava\Support\IconAliasMap;
use Simtabi\Laranail\Ichava\Support\KindResolver;
use Simtabi\Laranail\Ichava\Support\PathResolver;
use Simtabi\Laranail\Ichava\Support\SvgFileCounter;
use Simtabi\Laranail\Ichava\Support\IchavaRegistrar;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;
use Simtabi\Laranail\Ichava\Contracts\IconSetInterface;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Events\IconRegistrationEvent;

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
     * Blade component aliases, keyed by the provider class that registered one.
     *
     * @var array<string, string>
     */
    private array $bladeComponents = [];

    /**
     * Package metadata (for browser, stats, etc.)
     *
     * @var array<string, array<string, mixed>>
     */
    private array $packages = [];

    /**
     * Package conflicts (for validation): `icon_set_name` maps a set to its
     * `existing`/`new` pair, `prefix` and `blade_component` map the clashing
     * value to every package claiming it.
     *
     * @var array<string, array<string, array<string, string>|list<string>>>
     */
    private array $conflicts = [];

    /**
     * Default icon set name
     */
    private ?string $defaultSet = null;

    /**
     * The definition each set was registered from.
     *
     * @var array<string, PackDefinition>
     */
    private array $definitions = [];

    /** The registrar id of the registerMany() run in progress, so its sets share it. */
    private ?string $bulkRegistrarId = null;

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
        private IconDriverInterface $driver,
        private IchavaLogger $logger,
        private PathResolver $pathResolver,
        private IconAliasMap $aliases,
        private IconCacheService $cache,
        private Filesystem $files,
        private SvgFileCounter $svgCounter,
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
     * Register the sets `ichava.ichava-core.custom-icons.sets` declares.
     *
     * Each entry is a path, or an array of options keyed by the entry's name:
     *
     * - `path` (required): a directory holding a config.json, or a directory
     *   of SVGs with none;
     * - `enabled` (default true): false skips the entry;
     * - `package`: the `vendor/package` a set without a config.json registers
     *   as (default `app/{entry key}`);
     * - `bulk` with `only`: register every config-bearing sub-directory of
     *   `path`, or only those named, through registerMany();
     * - every key {@see PackBuilder::withOptions()} reads (`prefix`,
     *   `default_class`, `default_attributes`, `fallback`, `variants`,
     *   `default_variant`, `supports_categories`, `blade_namespace`,
     *   `blade_component`, ...), applied over the set's config.json.
     *
     * @param array<int|string, string|array<string, mixed>> $sets
     */
    public function registerFromConfig(array $sets): void
    {
        foreach ($sets as $key => $set) {
            $options = is_array($set) ? $set : ['path' => $set];
            $path = $options['path'] ?? null;

            if (($options['enabled'] ?? true) === false || ! is_string($path) || $path === '') {
                continue;
            }

            if (($options['bulk'] ?? false) === true) {
                $only = array_values(array_filter(is_array($options['only'] ?? null) ? $options['only'] : [], is_string(...)));

                $this->registerMany(
                    array_map(fn (PackBuilder $pack): PackBuilder => $pack->withOptions($options), PackBuilder::discover($path, $only)),
                    new BulkOptions(name: 'custom-icons.' . $key),
                );

                continue;
            }

            $pack = is_file(rtrim($path, '/\\') . '/config.json')
                ? PackBuilder::fromDirectory($path)
                : PackBuilder::make(is_string($options['package'] ?? null) ? $options['package'] : 'app/' . Str::slug((string) $key), $path);

            $this->register($pack->withOptions($options)->build());
        }
    }

    /**
     * Auto-register an icon set from a directory that contains a config.json file.
     *
     * Reads `{$path}/config.json` and counts the SVGs under `{$path}/files/`.
     * The same path as register(PackBuilder::fromDirectory($path)->build()),
     * which is what this does; the returned RegistrationConfig is already
     * registered, and chaining on it changes nothing.
     *
     * @param string $path Absolute path to the icon set directory (must contain config.json)
     * @param string|null $providerClass The service provider that registered this set (for attribution)
     *
     * @throws IchavaException if config.json is missing or malformed
     */
    public function fromDirectory(string $path, ?string $providerClass = null): RegistrationConfig
    {
        $definition = PackBuilder::fromDirectory($path)
            ->when($providerClass !== null, fn (PackBuilder $pack) => $pack->providedBy((string) $providerClass))
            ->build();

        $this->register($definition);

        return (new RegistrationConfig($definition->name, $this))->markRegistered();
    }

    /**
     * Register one icon set. Every way into the registry -- fromDirectory(),
     * package(), the bulk registrar and `custom-icons` -- ends here, so a set
     * carries the same metadata whichever door it came through.
     *
     * @param IconSetInterface|null $set a prebuilt set; null builds one from the definition
     */
    public function register(PackDefinition $definition, ?IconSetInterface $set = null): void
    {
        if ($set === null) {
            $set = new IconSetBuilder($definition->name, $this->cache, $this->files)
                ->setBasePath($definition->filesPath)
                ->prefix($definition->prefix);

            $variants = $definition->variantNames();

            if ($variants !== []) {
                $set->withVariants($variants);
            }

            if ($definition->hasCategories) {
                $set->withCategories(true);
            }

            // `config.defaults` is the one source of a set's default class,
            // attributes and fallback, so `<x-ichava::icon>`, a pack's own tag
            // and render() draw the same markup (P-06).
            $set->defaultAttributes($definition->defaultAttributes());

            if ($definition->defaultClass() !== null) {
                $set->defaultClass((string) $definition->defaultClass());
            }

            if ($definition->fallback() !== null) {
                $set->fallback($definition->fallback());
            }

            if ($definition->defaultVariant !== null) {
                $set->defaultVariant($definition->defaultVariant);
            }
        }

        $noted = $definition->providerClass === null ? null : ($this->bladeComponents[$definition->providerClass] ?? null);

        $this->registerIconSet(
            $definition->name,
            $set,
            $definition->toMetadata($this->countIconsInDirectory($definition->filesPath), $noted),
        );

        $this->definitions[$definition->name] ??= $definition;

        $this->loadAliasFile($definition->name, $definition->basePath);

        if ($definition->aliases !== []) {
            $this->aliases->register($definition->name, $definition->aliases);
        }
    }

    /**
     * Register several sets as one run (Proposal 3).
     *
     * Each item is a definition, or a builder built here so a broken
     * config.json fails inside the run. Every set goes through register(), so
     * a set registered in bulk is the same as one registered alone. One
     * registrar id covers the run: `started`, then each set's `processing` and
     * `registered` (or `failed`), then one `completed` with the counts. By
     * default a broken set is reported and the rest register; a builder whose
     * directory does not exist is skipped.
     *
     * @param iterable<PackDefinition|PackBuilder> $definitions
     */
    public function registerMany(iterable $definitions, ?BulkOptions $options = null): BulkResult
    {
        $options ??= new BulkOptions;
        $items = is_array($definitions) ? array_values($definitions) : iterator_to_array($definitions, false);
        $registrarId = Str::uuid()->toString();
        $startedAt = microtime(true);
        $announce = ! $this->recentlyAnnounced('bulk:' . $options->name);
        $registered = $skipped = $failed = [];

        if ($announce) {
            Event::dispatch(IconRegistrationEvent::started($registrarId, $options->name, count($items), 'bulk'));
        }

        $previous = $this->bulkRegistrarId;
        $this->bulkRegistrarId = $registrarId;

        try {
            foreach ($items as $item) {
                $label = $item instanceof PackBuilder ? $item->basePath() : $item->name;

                if ($item instanceof PackBuilder && ! is_dir($item->basePath())) {
                    $skipped[] = $label;

                    continue;
                }

                $definition = $item instanceof PackDefinition ? $item : null;

                try {
                    $definition ??= $item instanceof PackBuilder ? $item->build() : $item;
                    $this->register($definition);
                    $registered[] = $definition->name;
                } catch (Throwable $e) {
                    if ($definition === null) {
                        // A set that failed to build never reached
                        // registerIconSet(), which announces its own failures.
                        Event::dispatch(IconRegistrationEvent::failed($registrarId, $label, [], $e));
                    }

                    if ($options->failFast) {
                        throw $e;
                    }

                    $failed[$definition->name ?? $label] = $e->getMessage();
                    $this->logger->warning("Bulk registration [{$options->name}] skipped a set that failed", ['set' => $label, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            $this->bulkRegistrarId = $previous;
        }

        $result = new BulkResult($registered, $skipped, $failed, round((microtime(true) - $startedAt) * 1000, 2));

        if ($announce) {
            $this->markAnnounced('bulk:' . $options->name);
            Event::dispatch(IconRegistrationEvent::completed($registrarId, $options->name, $result->counts(), $result->durationMs));
        }

        if ($options->log) {
            $this->logger->info(sprintf(
                'Bulk registration [%s]: registered %d set(s), skipped %d, failed %d.',
                $options->name,
                ...array_values($result->counts()),
            ));
        }

        return $result;
    }

    /**
     * The definition a set was registered from, or null when it was registered
     * through registerIconSet() with prebuilt metadata.
     */
    public function definition(string $packageName): ?PackDefinition
    {
        return $this->definitions[$packageName] ?? null;
    }

    /**
     * The definition registered from the set directory $basePath, or null.
     */
    public function definitionAt(string $basePath): ?PackDefinition
    {
        $basePath = rtrim($basePath, '/\\');

        foreach ($this->definitions as $definition) {
            if (rtrim($definition->basePath, '/\\') === $basePath) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Let old icon names in a set resolve to icons kept under other names.
     *
     * Each key is a path within the set as written after `::`, and each value
     * is the path of the icon it now renders, e.g.
     * `['shapes/circle' => 'shapes/dot']`. An alias is only consulted when no
     * icon exists under the requested name, and resolves in one hop.
     *
     * A pack registered with fromDirectory() can ship the same map as
     * `aliases.json` beside its `config.json`.
     *
     * @param array<string, string> $aliases old path => kept path
     *
     * @throws IchavaException when an entry names itself or chains to another alias
     */
    public function alias(string $set, array $aliases): static
    {
        $this->aliases->register($set, $aliases);

        return $this;
    }

    /**
     * The aliases registered for a set.
     *
     * @return array<string, string> old path => kept path
     */
    public function aliases(string $set): array
    {
        return $this->aliases->for($set);
    }

    /**
     * Register an icon set directly with pre-built metadata (advanced use).
     *
     * This is the internal registration path called by fromDirectory() and package().
     * It handles in-memory deduplication (skips if already registered), metadata
     * validation, conflict detection, log deduplication, and event dispatch.
     *
     * @param string $name Unique icon set name (e.g. 'ichava/icon-sets-tabler')
     * @param IconSetInterface $set The icon set object
     * @param array<string, mixed> $metadata Package metadata (package_name, base_path, etc.)
     *
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

        // The lifecycle a listener sees: processing, then registered and
        // completed, or failed. Announced at most once per deduplication
        // window, as `registered` always was: the registry is rebuilt on every
        // request, and a listener must not hear the same pack every time.
        $registrarId = $this->bulkRegistrarId ?? Str::uuid()->toString();
        $announce = ! $this->recentlyAnnounced($name);
        $startedAt = microtime(true);

        if ($announce) {
            Event::dispatch(IconRegistrationEvent::processing($registrarId, $name, $metadata, 1, 1));
        }

        try {
            // Validate metadata
            $this->validateMetadata($name, $metadata);

            // Check conflicts
            $this->checkConflicts($name, $metadata);
        } catch (Throwable $e) {
            // A failure is announced every time: it is never routine.
            Event::dispatch(IconRegistrationEvent::failed($registrarId, $name, $metadata, $e));

            throw $e;
        }

        // Store icon set
        $this->sets[$name] = $set;

        // Store metadata
        $this->packages[$name] = array_merge([
            'registered_at' => now()->toIso8601String(),
            'package_name'  => $name,
        ], $metadata);

        // Set as default if first
        if ($this->defaultSet === null) {
            $this->defaultSet = $name;
        }

        // Log only if not recently logged (cache-based deduplication across requests)
        $this->logRegistrationOnce($name, $metadata);

        // Fire events only if not recently fired (prevents duplicate listeners)
        if ($announce) {
            $this->markAnnounced($name);
            Event::dispatch(IconRegistrationEvent::registered($registrarId, $name, $metadata, 0));
        }

        // A bulk run announces one `completed` for all its sets.
        if ($announce && $this->bulkRegistrarId === null) {
            Event::dispatch(IconRegistrationEvent::completed(
                $registrarId,
                $name,
                ['registered' => 1, 'failed' => 0],
                round((microtime(true) - $startedAt) * 1000, 2),
            ));
        }

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
            $setName = $iconPath->set ?? $this->defaultSet;

            // No set named and no default: nothing can hold the icon. `set()`
            // takes a string, so passing null threw a TypeError, which the
            // `catch (Exception)` below never caught.
            if ($setName === null) {
                return false;
            }

            $set = $this->set($setName);

            $variant ??= $iconPath->variant;
            $category ??= $iconPath->category;

            return $set->has($iconPath->name, $variant, $category)
                || $this->aliasedIcon($setName, $set, $iconPath->name, $variant, $category) !== null;
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
     * @param string $name Icon path (e.g. 'ichava/icon-sets-tabler::outline/home')
     * @param string|null $variant Variant override (e.g. 'outline', 'solid')
     * @param string|null $category Category override
     * @param array<string, mixed> $attributes HTML attributes to inject onto the SVG element
     *
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
        $variant ??= $iconPath->variant;
        $category ??= $iconPath->category;

        $icon = $set->get($iconPath->name, $variant, $category)
            ?? $this->aliasedIcon($setName, $set, $iconPath->name, $variant, $category);

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

        $metadata = $this->packages[$setName] ?? [];
        $baseDir = $metadata['base_path'] ?? $metadata['path'] ?? null;

        // The file-size ceiling follows the kind, so an illustration is not
        // refused by a limit sized for icons. Resolved by the same rows the
        // seeder stored, so render and the row agree.
        $maxBytes = $this->kindResolver()->resolve($setName, $iconPath->fullPath ?? $iconPath->name)->kind->maxFileSize();

        return $this->driver->render($icon, $attributes, is_string($baseDir) ? $baseDir : null, $maxBytes);
    }

    /**
     * The kind of the SVGs in one category of a pack.
     *
     * Resolution order: the category's own `kind`, then the pack's
     * `package.kind`, then {@see IconKind::Icon}. An unregistered pack, or one
     * registered without a config.json, is all icons.
     */
    public function kindFor(string $packageName, ?string $categorySlug = null): IconKind
    {
        $metadata = $this->packages[$packageName] ?? [];
        $categoryKinds = is_array($metadata['category_kinds'] ?? null) ? $metadata['category_kinds'] : [];

        if ($categorySlug !== null && isset($categoryKinds[$categorySlug])) {
            $kind = IconKind::fromConfig($categoryKinds[$categorySlug]);

            if ($kind !== null) {
                return $kind;
            }
        }

        return IconKind::fromConfig($metadata['kind'] ?? null) ?? IconKind::Icon;
    }

    /**
     * The kind of the icon a renderable path names, e.g.
     * `vendor/pack::illustrations/scene`.
     *
     * Delegates to {@see KindResolver}, so render-time aspect and size budget
     * follow every row the seeder stored, not only the category and pack.
     * Never throws: a path that does not parse is reported as an icon, and
     * rendering reports the real error.
     */
    public function kindOf(string $name): IconKind
    {
        return $this->kindResolution($name)->kind;
    }

    /**
     * The kind of the icon a renderable path names, and which row decided it.
     *
     * A path that does not parse, or names no set, resolves from the default
     * row.
     */
    public function kindResolution(string $name): KindResolution
    {
        try {
            $iconPath = $this->pathResolver->parseIconPath($name);
        } catch (Throwable) {
            return new KindResolution(IconKind::Icon, KindSource::Default);
        }

        $setName = $iconPath->set ?? $this->defaultSet;

        if ($setName === null) {
            return new KindResolution(IconKind::Icon, KindSource::Default);
        }

        return $this->kindResolver()->resolve($setName, $iconPath->fullPath ?? $iconPath->name);
    }

    /**
     * The theme twins a pack's category declares, as theme => filename suffix.
     *
     * @return array<string, string>
     */
    public function themeVariantsFor(string $packageName, ?string $categorySlug): array
    {
        if ($categorySlug === null) {
            return [];
        }

        $metadata = $this->packages[$packageName] ?? [];
        $variants = is_array($metadata['theme_variants'] ?? null) ? $metadata['theme_variants'] : [];
        $forCategory = $variants[$categorySlug] ?? [];

        if (! is_array($forCategory)) {
            return [];
        }

        $typed = [];

        foreach ($forCategory as $theme => $suffix) {
            if (is_string($theme) && is_string($suffix) && $suffix !== '') {
                $typed[$theme] = $suffix;
            }
        }

        return $typed;
    }

    /**
     * The renderable id of an icon's theme twin, or null when it has none.
     *
     * The twin is the file the icon's category declares for that theme in
     * `theme_variants` (`scenes/park` with `{"dark": "-dark"}` is
     * `scenes/park-dark`), and only when both files are registered: a
     * suffixed file with no light sibling is an item of its own, not a twin.
     * Never throws: a path that does not parse has no twin.
     */
    public function themeTwin(string $name, string $theme = 'dark'): ?string
    {
        try {
            $iconPath = $this->pathResolver->parseIconPath($name);
        } catch (Throwable) {
            return null;
        }

        $setName = $iconPath->set ?? $this->defaultSet;

        if ($setName === null) {
            return null;
        }

        $itemPath = $iconPath->fullPath ?? $iconPath->name;
        $suffix = $this->themeVariantsFor($setName, KindResolver::categoryOf($itemPath))[$theme] ?? null;

        if ($suffix === null || str_ends_with($itemPath, $suffix)) {
            return null;
        }

        $twin = $setName . '::' . $itemPath . $suffix;

        return $this->has($name) && $this->has($twin) ? $twin : null;
    }

    /**
     * Get all registered packages
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(fn (array $metadata): array => $this->localise($metadata), $this->packages);
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

        return $this->localise($this->packages[$packageName]);
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
        $iconSetName = $metadata['icon_set_name'] ?? null;

        if (! is_string($iconSetName)) {
            $iconSetName = $packageName;
        }

        unset(
            $this->packages[$packageName],
            $this->sets[$iconSetName],
            $this->definitions[$packageName],
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

        $this->aliases->forget($iconSetName);

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
     * Record the Blade component alias a package actually registered.
     *
     * `checkConflicts()` has always had a `blade_component` detector, and it
     * has never fired: nothing wrote the key, so the guard read null every time
     * and the branch was unreachable. That is the collision the global standard
     * calls the headline risk -- Blade keeps component aliases in a flat map, so
     * a second package claiming one silently replaces the first.
     *
     * Keyed by provider class rather than package name because that is what
     * `fromDirectory()` is given, and because a provider registers its component
     * before it registers its icon directory -- both happen in `bootingPackage()`,
     * in that order.
     *
     * Deriving the alias instead of recording it was the alternative and is
     * worse: the alias comes from the short name a pack passes to
     * `loadBladeComponent()`, which nothing else in the metadata carries. A
     * derived value would agree with the real registration only by luck.
     */
    public function noteBladeComponent(string $providerClass, string $alias): void
    {
        $this->bladeComponents[$providerClass] = $alias;
    }

    /**
     * Get conflicts
     *
     * @return array<string, array<string, array<string, string>|list<string>>>
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
     * @param string $path Directory to scan
     *
     * @return int Total SVG file count
     */
    public function countIconsInDirectory(string $path): int
    {
        // One counter for the whole package. This method used to carry its own
        // copy, catching Exception where SvgFileCounter learned to catch
        // Throwable -- so the failure that action was fixed for still escaped here.
        return $this->svgCounter->recursively($path);
    }

    /**
     * Direct access to the SvgDriver instance (load, process, render SVG files).
     */
    public function driver(): IconDriverInterface
    {
        return $this->driver;
    }

    /**
     * The first folder of an in-pack path, which is the category the seeder
     * files the icon under, or null for an icon at the root of the pack.
     *
     * Unused since render() and kindOf() resolve through KindResolver, which
     * applies every row rather than the category and pack alone. Kept, not
     * removed: removal is a separate, per-item owner decision (2026-11-04:
     * "don't remove"). Checked again for the dark-twin lookup in themeTwin(),
     * the one new caller that reads a first folder: it needs the slugged
     * category theme_variants are keyed by, which KindResolver::categoryOf()
     * gives and this does not, so wiring it there would be a bug.
     *
     * @phpstan-ignore method.unused (kept by owner decision; no caller needs an unslugged first folder)
     */
    private static function topFolder(string $relativePath): ?string
    {
        $relativePath = trim($relativePath, '/');

        return str_contains($relativePath, '/') ? Str::before($relativePath, '/') : null;
    }

    /**
     * The resolver over this registry. Built per call: it is a readonly view
     * of what this registry holds, and cannot be injected into the object it
     * reads from.
     */
    private function kindResolver(): KindResolver
    {
        return new KindResolver($this);
    }

    /**
     * The icon an old name now points at, once the name itself has missed.
     */
    private function aliasedIcon(string $setName, IconSetInterface $set, string $name, ?string $variant, ?string $category): ?IconData
    {
        $requested = implode('/', array_filter([$variant, $category, $name], fn (?string $part): bool => $part !== null && $part !== ''));
        $target = $this->aliases->resolve($setName, $requested);

        if ($target === null) {
            return null;
        }

        $kept = $this->pathResolver->parseIconPath($setName . '::' . $target);

        return $set->get($kept->name, $kept->variant, $kept->category);
    }

    /**
     * Register a pack's `aliases.json`, when it ships one.
     *
     * @throws IchavaException when the file is not a JSON object or holds an invalid alias
     */
    private function loadAliasFile(string $set, string $directoryPath): void
    {
        $aliasPath = Str::finish($directoryPath, '/') . 'aliases.json';

        if (! File::exists($aliasPath)) {
            return;
        }

        $aliases = json_decode(File::get($aliasPath), true);

        if (! is_array($aliases)) {
            throw IchavaException::invalidConfig('aliases.json must be a JSON object of old path => kept path', $aliasPath);
        }

        $this->aliases->register($set, $aliases);
    }

    /**
     * Apply a pack's translations over its stored metadata.
     *
     * **Applied on read, never at registration.** Two separate reasons, and the
     * first one bit during development:
     *
     * - `fromDirectory()` runs from a pack's `bootingPackage()`, which
     *   `package-tools` fires *before* `bootPackageTranslations()`. Calling
     *   `trans()` there does not merely miss -- `Translator::load()` caches the
     *   empty result under `$loaded[$namespace][$group][$locale]`, `isLoaded()`
     *   answers true from then on, and the namespace registered moments later is
     *   never consulted again. One premature lookup poisons the key for the rest
     *   of the request.
     * - The registry is a singleton built once at boot, while locale is a
     *   per-request thing. Resolving eagerly would serve whichever locale
     *   happened to be active during boot to every request afterwards.
     *
     * `config.json` stays canonical and translations are an overlay on top. That
     * ordering is why the English files carry no `name` or `description`: every
     * pack that shipped both had let them drift, because nothing read the
     * translation and nothing compared the two.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function localise(array $metadata): array
    {
        $packageName = $metadata['package_name'] ?? null;

        if (! is_string($packageName) || $packageName === '') {
            return $metadata;
        }

        foreach (['name', 'description'] as $key) {
            $translated = $this->line("{$packageName}::icons.{$key}");

            if (is_string($translated)) {
                $metadata[$key] = $translated;
            }
        }

        $labels = [];

        // `*_descriptions` are the packs' translated descriptions, the
        // localised counterpart of config.json's English `description`.
        foreach (['variants', 'categories', 'sets', 'variant_descriptions', 'category_descriptions', 'set_descriptions'] as $group) {
            $translated = $this->line("{$packageName}::icons.{$group}");

            if (is_array($translated) && $translated !== []) {
                $labels[$group] = $translated;
            }
        }

        $metadata['labels'] = $labels;

        return $metadata;
    }

    /**
     * A translation line, or null when the pack does not define it.
     *
     * `trans()` hands back the key it was given on a miss, which is the only
     * miss signal it offers.
     *
     * @return string|array<array-key, mixed>|null
     */
    private function line(string $key): string|array|null
    {
        $translated = trans($key);

        return $translated === $key ? null : $translated;
    }

    /**
     * Log package registration at most once per cache TTL to prevent log spam.
     *
     * Uses a cache key `ichava:registered:{name}` to suppress repeated log entries
     * across requests during the TTL window (default: 300s). A TTL of 0 disables
     * deduplication. Falls back to always logging if the cache is unavailable.
     *
     * @param string $name Icon set name
     * @param array<string, mixed> $metadata Package metadata
     */
    private function logRegistrationOnce(string $name, array $metadata): void
    {
        $ttl = $this->deduplicationTtl();

        // TTL of 0 disables deduplication
        if ($ttl === 0) {
            $this->logger->info('📦 Icon package registered', [
                'package'    => $name,
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
            'package'    => $name,
            'icon_count' => $metadata['total'] ?? 0,
        ]);
    }

    /**
     * Whether this package's registration was announced within the cache TTL.
     *
     * Reads `ichava:event:{name}`; markAnnounced() writes it once the
     * registration succeeded, so a failed attempt never suppresses the next
     * one. A TTL of 0 disables deduplication, and an unavailable cache
     * announces every time rather than never.
     */
    private function recentlyAnnounced(string $name): bool
    {
        if ($this->deduplicationTtl() === 0) {
            return false;
        }

        try {
            return cache()->has("ichava:event:{$name}");
        } catch (Throwable) {
            return false;
        }
    }

    private function markAnnounced(string $name): void
    {
        $ttl = $this->deduplicationTtl();

        if ($ttl === 0) {
            return;
        }

        try {
            cache()->put("ichava:event:{$name}", true, $ttl);
        } catch (Throwable) {
            // Cache unavailable: the next registration announces again.
        }
    }

    /**
     * Validate that the package metadata contains the required fields.
     *
     * Required fields: package_name, icon_set_name, base_path.
     * Also verifies that base_path is an existing directory.
     *
     * @param string $packageName Package being registered (for error messages)
     * @param array<string, mixed> $metadata
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
        $basePath = $metadata['base_path'];

        if (! is_string($basePath) || ! File::isDirectory($basePath)) {
            throw IchavaException::packagePathNotFound(
                $packageName,
                is_string($basePath) ? $basePath : get_debug_type($basePath),
            );
        }
    }

    /**
     * Check for icon set name, prefix, and Blade component conflicts.
     *
     * Conflicts are non-fatal by default (logged as warnings, stored in
     * $this->conflicts), since partial overlap is acceptable in some setups;
     * `registry.strict_conflicts` turns each into its named exception.
     * Three conflict vectors are checked:
     * - icon_set_name, two packages share the same internal set name
     * - prefix       , two packages use the same icon prefix (ambiguous lookups)
     * - blade_component, two packages register the same Blade component alias
     *
     * @param string $packageName Package being registered
     * @param array<string, mixed> $metadata
     */
    private function checkConflicts(string $packageName, array $metadata): void
    {
        $iconSetName = $metadata['icon_set_name'] ?? null;
        $prefix = $metadata['prefix'] ?? null;
        $bladeComponent = $metadata['blade_component'] ?? null;

        // Check icon set name conflict
        if (is_string($iconSetName) && ! empty($iconSetName)) {
            foreach ($this->packages as $existingPackage => $existingMeta) {
                if ($existingPackage !== $packageName &&
                    ($existingMeta['icon_set_name'] ?? null) === $iconSetName) {

                    $this->conflicts['icon_set_name'][$iconSetName] = [
                        'existing' => $existingPackage,
                        'new'      => $packageName,
                    ];

                    $this->logger->warning('⚠️ Icon set name conflict', [
                        'icon_set' => $iconSetName,
                        'packages' => [$existingPackage, $packageName],
                    ]);

                    if ($this->strictConflicts()) {
                        throw IchavaException::iconSetAlreadyRegistered($iconSetName);
                    }
                }
            }
        }

        // Check prefix conflict (warning only)
        if (is_string($prefix) && ! empty($prefix)) {
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
                    'prefix'   => $prefix,
                    'packages' => $prefixConflicts,
                ]);

                if ($this->strictConflicts()) {
                    throw IchavaException::prefixConflict($prefix, $prefixConflicts);
                }
            }
        }

        // Check blade component conflict (warning only)
        if (is_string($bladeComponent) && ! empty($bladeComponent)) {
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
                    'packages'  => $componentConflicts,
                ]);

                if ($this->strictConflicts()) {
                    throw IchavaException::bladeComponentConflict($bladeComponent, $componentConflicts);
                }
            }
        }
    }

    /**
     * `registry.strict_conflicts`: throw on a conflict instead of warning.
     *
     * Off by default, which keeps the warning-and-record behaviour. On, a
     * clash fails the registration loudly: a silently shared prefix or Blade
     * alias is the flat-map replacement the naming rule warns about.
     */
    private function strictConflicts(): bool
    {
        return (bool) config('ichava.ichava-core.registry.strict_conflicts', false);
    }

    /**
     * How long registration logs and events are de-duplicated, in seconds.
     *
     * A non-numeric value falls back to the shipped default rather than
     * casting to 0, which would silently disable de-duplication.
     */
    private function deduplicationTtl(): int
    {
        $ttl = config('ichava.ichava-core.logging.deduplication_ttl', 300);

        return is_numeric($ttl) ? (int) $ttl : 300;
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
