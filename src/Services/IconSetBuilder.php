<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Data\IconSetConfig;
use Simtabi\Laranail\Ichava\Support\PathResolver;
use Simtabi\Laranail\Ichava\Contracts\IconSetInterface;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * IconSetBuilder - Universal Icon Set Builder & Base Class
 *
 * Merged from IchavaSet + IconSetBuilder for simplified architecture.
 * Provides both fluent API configuration and core icon discovery functionality.
 *
 * All icon sets should extend this class. It serves as:
 * - Base class for generated icon packages
 * - Fluent builder for dynamic icon set configuration
 * - Core icon discovery and caching engine
 */
class IconSetBuilder implements IconSetInterface
{
    // From IchavaSet (base functionality)
    protected Filesystem $files;

    protected IconCacheService $cache;

    protected IconSetConfig $config;

    protected array $discoveredIcons = [];

    // From IconSetBuilder (fluent configuration)
    protected string $name;

    protected string|array|null $paths = null;

    protected ?string $prefix = null;

    protected ?string $defaultVariant = null;

    protected array $variants = [];

    protected bool $supportsCategories = false;

    protected string $defaultClass = 'icon';

    protected array $defaultAttributes = [];

    protected ?string $fallback = null;

    protected bool $enableCache = false;

    /** @var IconSetInterface[] Merged icon sets */
    protected array $mergedSets = [];

    /** @var bool Whether this is a merged set */
    protected bool $isMerged = false;

    /** @var bool Whether the parent has been initialized */
    protected bool $initialized = false;

    /**
     * Create new icon set
     */
    public function __construct(
        string|Filesystem $nameOrFiles = '',
        ?IconCacheService $cache = null,
        ?Filesystem $files = null,
    ) {
        // Support two constructor modes:
        // 1. Fluent builder: new IconSetBuilder('name')
        // 2. Base class: new IconSetBuilder($files, $cache)

        if ($nameOrFiles instanceof Filesystem) {
            // Base class mode (for generated packages)
            $this->files = $nameOrFiles;

            if (! $cache) {
                throw IchavaException::dependencyNotInjected('IconCacheService', static::class);
            }
            $this->cache = $cache;
            $this->config = $this->buildConfig();
        } else {
            // Fluent builder mode
            $this->name = $nameOrFiles;

            if (! $files) {
                throw IchavaException::dependencyNotInjected('Filesystem', static::class);
            }
            $this->files = $files;

            if (! $cache) {
                throw IchavaException::dependencyNotInjected('IconCacheService', static::class);
            }
            $this->cache = $cache;
        }
    }

    /**
     * Fluent builder factory method
     *
     * @param string $name Icon set name
     */
    public static function make(string $name): static
    {
        return new static(
            $name,
            app(IconCacheService::class),
            app(Filesystem::class),
        );
    }

    /**
     * Create from service provider class reference
     *
     * Automatically resolves the path from the calling class location.
     * Perfect for use in service providers.
     *
     * @param string $name Icon set name
     * @param string $class Caller class (usually self::class from service provider)
     * @param int $levelsUp Levels to go up from class (default: 3)
     * @param string $append Path to append (e.g., 'resources/assets/svg/icons')
     *
     * @example In Service Provider
     * ```php
     * IconSet::makeFromClass('my-icons', self::class, 3, 'resources/assets/svg/icons')
     *     ->prefix('my')
     *     ->withVariants(['outline', 'filled']);
     * ```
     */
    public static function makeFromClass(
        string $name,
        string $class,
        int $levelsUp = 3,
        string $append = '',
    ): static {
        $instance = new static($name);

        if ($append) {
            $path = PathResolver::resolvePackagePath($class, $levelsUp, $append);
        } else {
            $path = PathResolver::svgPathFromIconSet($class, $levelsUp);
        }

        $instance->path($path);

        return $instance;
    }

    /**
     * Set icon path
     *
     * @param string $path Path to icons
     *
     * @return $this
     */
    public function path(string $path): static
    {
        // Normalize path:
        // 1. Replace multiple consecutive slashes with single slash
        // 2. Remove trailing slashes
        $path = preg_replace('#/+#', '/', $path); // Replace // with /
        $path = preg_replace('#\\\\+#', '\\\\', $path); // Replace \\\\ with \\
        $path = rtrim($path, '/\\');

        // Convert relative paths to absolute
        if (! Str::startsWith($path, '/') && ! preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
            $path = base_path($path);
        }

        $this->paths = $path;

        return $this;
    }

    /**
     * Set base path (alias for path() for consistency)
     * Intelligently handles path construction:
     * - Removes trailing slashes from input
     * - Ensures proper directory separator when appending
     *
     * Examples (all produce same result):
     *   ->setBasePath('/path/to/icons/files')
     *   ->setBasePath('/path/to/icons/' . '/files')
     *   ->setBasePath('/path/to/icons' . '/files')
     *   ->setBasePath('/path/to/icons/files/')
     *
     * @param string $basePath Path to icons (with or without trailing slash)
     *
     * @return $this
     */
    public function setBasePath(string $basePath): static
    {
        return $this->path($basePath);
    }

    /**
     * Set multiple icon paths
     *
     * @param array $paths Array of paths
     *
     * @return $this
     */
    public function paths(array $paths): static
    {
        $this->paths = $paths;

        return $this;
    }

    /**
     * Set icon prefix
     *
     * @param string $prefix Icon prefix
     *
     * @return $this
     */
    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Set default variant
     *
     * @param string $variant Default variant name
     *
     * @return $this
     */
    public function defaultVariant(string $variant): static
    {
        $this->defaultVariant = $variant;

        return $this;
    }

    /**
     * Set available variants
     *
     * @param array $variants Variant names
     *
     * @return $this
     */
    public function withVariants(array $variants): static
    {
        $this->variants = $variants;

        return $this;
    }

    /**
     * Set whether categories are supported
     *
     * @param bool $supports Support categories
     *
     * @return $this
     */
    public function withCategories(bool $supports = true): static
    {
        $this->supportsCategories = $supports;

        return $this;
    }

    /**
     * Set default CSS class
     *
     * @param string $class Default class
     *
     * @return $this
     */
    public function defaultClass(string $class): static
    {
        $this->defaultClass = $class;

        return $this;
    }

    /**
     * Set default attributes
     *
     * @param array $attributes Default attributes
     *
     * @return $this
     */
    public function defaultAttributes(array $attributes): static
    {
        $this->defaultAttributes = $attributes;

        return $this;
    }

    /**
     * Set fallback icon
     *
     * @param string|null $icon Fallback icon name
     *
     * @return $this
     */
    public function fallback(?string $icon): static
    {
        $this->fallback = $icon;

        return $this;
    }

    /**
     * Enable/disable caching
     *
     * @param bool $enabled Cache enabled
     *
     * @return $this
     */
    public function cache(bool $enabled = true): static
    {
        $this->enableCache = $enabled;

        return $this;
    }

    /**
     * Get config
     */
    public function config(): IconSetConfig
    {
        if (! isset($this->config)) {
            $this->config = $this->buildConfig();
        }

        return $this->config;
    }

    /**
     * Get base path
     */
    public function basePath(): string
    {
        return $this->config()->basePath;
    }

    /**
     * Get name
     */
    public function name(): string
    {
        return $this->name ?? $this->config()->name;
    }

    /**
     * Check if icon exists
     */
    public function has(string $name, ?string $variant = null, ?string $category = null): bool
    {
        if ($this->isMerged) {
            foreach ($this->mergedSets as $set) {
                if ($set->has($name, $variant, $category)) {
                    return true;
                }
            }

            return false;
        }

        return $this->discoverIcon($name, $variant, $category) !== null;
    }

    /**
     * Get icon with caching
     */
    public function get(string $name, ?string $variant = null, ?string $category = null): ?IconData
    {
        if ($this->isMerged) {
            foreach ($this->mergedSets as $set) {
                if ($icon = $set->get($name, $variant, $category)) {
                    return $icon;
                }
            }

            return null;
        }

        $cacheKey = $this->getCacheKey($name, $variant, $category);

        return $this->cache->remember($cacheKey, function () use ($name, $variant, $category) {
            return $this->discoverIcon($name, $variant, $category);
        });
    }

    /**
     * Get all icons
     */
    public function all(?string $variant = null, ?string $category = null): array
    {
        if ($this->isMerged) {
            $allIcons = [];
            foreach ($this->mergedSets as $set) {
                $icons = $set->all($variant, $category);
                $allIcons = array_merge($allIcons, $icons);
            }

            return $allIcons;
        }

        $cacheKey = $this->getCacheKey('all', $variant, $category);

        return $this->cache->remember($cacheKey, function () use ($variant, $category) {
            return $this->discoverAllIcons($variant, $category);
        });
    }

    /**
     * Get categories
     */
    public function categories(?string $variant = null): array
    {
        if (! $this->config()->supportsCategories) {
            return [];
        }

        if ($this->isMerged) {
            $categories = [];
            foreach ($this->mergedSets as $set) {
                $setCategories = $set->categories($variant);
                $categories = array_merge($categories, $setCategories);
            }

            return array_unique($categories);
        }

        return $this->discoverCategories($variant);
    }

    /**
     * Get the merged sets (if applicable)
     *
     * @return IconSetInterface[]
     */
    public function getMergedSets(): array
    {
        return $this->mergedSets;
    }

    /**
     * Check if this is a merged set
     */
    public function isMerged(): bool
    {
        return $this->isMerged;
    }

    /**
     * Get icon variants
     */
    public function variants(): array
    {
        return $this->config->variants;
    }

    /**
     * Build the icon set configuration
     * Can be overridden by extending classes
     */
    protected function buildConfig(): IconSetConfig
    {
        // If this is a merged set, use first set's config as base
        if ($this->isMerged && ! empty($this->mergedSets)) {
            return $this->buildMergedConfig();
        }

        // Get base path (first path if multiple)
        $basePath = is_array($this->paths) ? $this->paths[0] : $this->paths;

        if ($basePath === null) {
            throw IchavaException::invalidConfiguration("Icon set '{$this->name}' requires a path");
        }

        return new IconSetConfig(
            name: $this->name,
            prefix: $this->prefix ?? '',
            basePath: $basePath,
            defaultVariant: $this->defaultVariant,
            variants: $this->variants,
            supportsCategories: $this->supportsCategories,
            defaultClass: $this->defaultClass,
            defaultAttributes: $this->defaultAttributes,
            fallback: $this->fallback,
        );
    }

    /**
     * Build merged configuration from multiple sets
     */
    protected function buildMergedConfig(): IconSetConfig
    {
        $baseConfig = $this->mergedSets[0]->config();

        // Collect all paths
        $paths = [];
        foreach ($this->mergedSets as $set) {
            if (method_exists($set, 'paths')) {
                $paths = array_merge($paths, $set->paths());
            } else {
                $paths[] = $set->path();
            }
        }

        // Merge variants
        $variants = [];
        foreach ($this->mergedSets as $set) {
            $variants = array_merge($variants, $set->variants());
        }

        // Merge attributes
        $attributes = [];
        foreach ($this->mergedSets as $set) {
            $attributes = array_merge($attributes, $set->defaultAttributes());
        }

        // Check if any set supports categories
        $supportsCategories = false;
        foreach ($this->mergedSets as $set) {
            if ($set->supportsCategories()) {
                $supportsCategories = true;
                break;
            }
        }

        return new IconSetConfig(
            name: $this->name,
            prefix: $baseConfig->prefix,
            basePath: $baseConfig->basePath,
            defaultVariant: $baseConfig->defaultVariant,
            variants: array_unique($variants),
            supportsCategories: $supportsCategories,
            defaultClass: $baseConfig->defaultClass,
            defaultAttributes: $attributes,
            fallback: $baseConfig->fallback,
        );
    }

    /**
     * Discover a single SVG icon
     */
    protected function discoverIcon(string $name, ?string $variant, ?string $category): ?IconData
    {
        $path = $this->buildIconPath($name, $variant, $category);

        if (! $this->files->exists($path)) {
            return null;
        }

        if (! $this->files->isReadable($path)) {
            throw IchavaException::filesystemFailure('read', $path);
        }

        if (! $this->isSvgFile($path)) {
            return null;
        }

        if ($this->files->size($path) === 0) {
            throw IchavaException::invalidSvg("File is empty: {$path}");
        }

        return new IconData(
            name: $name,
            path: $path,
            variant: $variant ?? $this->config->defaultVariant,
            category: $category,
            set: $this->name(),
        );
    }

    /**
     * Discover all SVG icons
     */
    protected function discoverAllIcons(?string $variant, ?string $category): array
    {
        $searchPath = $this->buildSearchPath($variant, $category);
        $files = $this->files->glob($searchPath . '/*.svg');

        $icons = [];
        foreach ($files as $file) {
            if (! $this->isSvgFile($file)) {
                continue;
            }

            $name = pathinfo($file, PATHINFO_FILENAME);
            $icons[$name] = new IconData(
                name: $name,
                path: $file,
                variant: $variant ?? $this->config->defaultVariant,
                category: $category,
                set: $this->name(),
            );
        }

        return $icons;
    }

    /**
     * Build SVG icon file path
     */
    protected function buildIconPath(string $name, ?string $variant, ?string $category): string
    {
        $parts = [$this->basePath()];

        if ($variant) {
            $parts[] = $variant;
        }

        if ($category && $this->config->supportsCategories) {
            $parts[] = $category;
        }

        $parts[] = $name . '.svg';

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Build search path for discovery
     */
    protected function buildSearchPath(?string $variant, ?string $category): string
    {
        $parts = [$this->basePath()];

        if ($variant) {
            $parts[] = $variant;
        }

        if ($category && $this->config->supportsCategories) {
            $parts[] = $category;
        }

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Discover categories
     */
    protected function discoverCategories(?string $variant): array
    {
        $searchPath = $this->buildSearchPath($variant, null);

        if (! $this->files->isDirectory($searchPath)) {
            return [];
        }

        return Arr::map(
            $this->files->directories($searchPath),
            fn ($dir) => basename($dir),
        );
    }

    /**
     * Verify file is an SVG
     */
    protected function isSvgFile(string $path): bool
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension !== 'svg') {
            return false;
        }

        $content = $this->files->get($path);

        return Str::contains($content, '<svg');
    }

    /**
     * Generate cache key
     */
    protected function getCacheKey(string $name, ?string $variant, ?string $category): string
    {
        return implode(':', Arr::where([
            $this->name(),
            $variant,
            $category,
            $name,
        ], fn ($value) => $value !== null));
    }
}
