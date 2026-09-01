<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Constants;

use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Services\ConfigurationService;
use Simtabi\Laranail\Ichava\Support\Helpers;

/**
 * Abstract base for icon-package metadata classes that derive their values
 * from a per-package `config.json` rather than hard-coded constants.
 *
 * Extend, implement `getConfigPath()`, and the package-name / vendor /
 * version / variant / category / prefix accessors become available with
 * lazy-loaded, per-class-cached reads.
 *
 * @see ConfigurationService
 */
abstract class JsonConfigConstants
{
    /**
     * Cached config data per class
     *
     * @var array<string, array>
     */
    private static array $configs = [];

    /**
     * Clear cached config (useful for testing)
     */
    final public static function clearCache(): void
    {
        unset(self::$configs[static::class]);
    }

    /**
     * Get vendor name from package.name (vendor/package → vendor)
     */
    public static function getVendorName(): string
    {
        return Helpers::getVendorFromPackage(static::config()['package']['name']);
    }

    /**
     * Get package name from package.name (vendor/package → package)
     */
    public static function getPackageName(): string
    {
        return Helpers::getPackageFromIdentifier(static::config()['package']['name']);
    }

    /**
     * Get full vendor/package name
     */
    public static function getVendorPackage(): string
    {
        return static::config()['package']['name'];
    }

    /**
     * Get icon prefix from config.icon_prefix
     */
    public static function getPrefix(): string
    {
        return static::config()['config']['icon_prefix'];
    }

    /**
     * Get categories from metadata.data.categories
     */
    public static function getCategories(): array
    {
        return array_keys(static::config()['metadata']['data']['categories'] ?? []);
    }

    /**
     * Get variants from metadata.data.variants (keys only)
     */
    public static function getVariants(): array
    {
        return array_keys(static::config()['metadata']['data']['variants'] ?? []);
    }

    /**
     * Get all variants with full metadata
     */
    public static function getVariantsWithMetadata(): array
    {
        return static::config()['metadata']['data']['variants'] ?? [];
    }

    /**
     * Get default variant
     */
    public static function getDefaultVariant(): ?string
    {
        $variants = static::getVariantsWithMetadata();

        foreach ($variants as $slug => $data) {
            if ($data['default'] ?? false) {
                return $slug;
            }
        }

        return array_key_first($variants);
    }

    /**
     * Get default category from metadata.data.categories (first entry flagged default,
     * else first listed). Returns null when no categories are defined.
     */
    public static function getDefaultCategory(): ?string
    {
        $categories = static::config()['metadata']['data']['categories'] ?? [];

        foreach ($categories as $slug => $data) {
            if (is_array($data) && ($data['default'] ?? false)) {
                return (string) $slug;
            }
        }

        $firstKey = array_key_first($categories);

        return $firstKey === null ? null : (string) $firstKey;
    }

    /**
     * Get variant metadata
     */
    public static function getVariantMetadata(string $variant): ?array
    {
        return static::getVariantsWithMetadata()[$variant] ?? null;
    }

    /**
     * Get variant display name
     */
    public static function getVariantName(string $variant): string
    {
        return static::getVariantMetadata($variant)['name'] ?? Str::ucfirst($variant);
    }

    /**
     * Get variant description
     */
    public static function getVariantDescription(string $variant): string
    {
        return static::getVariantMetadata($variant)['description'] ?? '';
    }

    /**
     * Get variant attributes (for SVG rendering)
     */
    public static function getVariantAttributes(string $variant): array
    {
        return static::getVariantMetadata($variant)['attributes'] ?? [];
    }

    /**
     * Check if variant exists
     */
    public static function hasVariant(string $variant): bool
    {
        return isset(static::getVariantsWithMetadata()[$variant]);
    }

    /**
     * Get variants sorted by display order
     */
    public static function getVariantsSorted(): array
    {
        $variants = static::getVariantsWithMetadata();

        uasort($variants, function ($a, $b) {
            return ($a['display_order'] ?? 999) <=> ($b['display_order'] ?? 999);
        });

        return array_keys($variants);
    }

    /**
     * Get variant icon suffix (e.g., "-outline", "-filled")
     */
    public static function getVariantSuffix(string $variant): string
    {
        return static::getVariantMetadata($variant)['icon_suffix'] ?? '';
    }

    /**
     * Get variant preview icon name
     */
    public static function getVariantPreviewIcon(string $variant): ?string
    {
        return static::getVariantMetadata($variant)['preview_icon'] ?? null;
    }

    /**
     * Get variant color scheme
     */
    public static function getVariantColorScheme(string $variant): ?string
    {
        return static::getVariantMetadata($variant)['color_scheme'] ?? null;
    }

    /**
     * Get package title from package.title
     */
    public static function getTitle(): string
    {
        return static::config()['package']['title'] ?? static::getPackageName();
    }

    /**
     * Get package description from package.description
     */
    public static function getDescription(): string
    {
        return static::config()['package']['description'] ?? '';
    }

    /**
     * Get package version from package.version
     */
    public static function getVersion(): string
    {
        return static::config()['package']['version'] ?? '1.0.0';
    }

    /**
     * Get package license from package.license
     */
    public static function getLicense(): string
    {
        return static::config()['package']['license'] ?? 'MIT';
    }

    /**
     * Get package type (single, multi, bulk) from package.type
     */
    public static function getType(): string
    {
        return static::config()['package']['type'] ?? 'single';
    }

    /**
     * Get package homepage URL from metadata.homepage
     */
    public static function getHomepage(): string
    {
        return static::config()['metadata']['homepage'] ?? '';
    }

    /**
     * Get package repository URL from metadata.repository
     */
    public static function getRepository(): string
    {
        return static::config()['metadata']['repository'] ?? '';
    }

    /**
     * Get GitHub repository in vendor/repo format from metadata.repository
     *
     * Extracts the vendor/repo portion from a full GitHub URL.
     * Example: https://github.com/tabler/tabler-icons → tabler/tabler-icons
     */
    public static function getGitHubRepo(): string
    {
        $repository = static::getRepository();

        if (empty($repository)) {
            return '';
        }

        // Extract vendor/repo from GitHub URL
        if (preg_match('#github\.com/([^/]+/[^/]+)#', $repository, $matches)) {
            return rtrim($matches[1], '.git');
        }

        // Already in vendor/repo format
        if (preg_match('#^[\w-]+/[\w-]+$#', $repository)) {
            return $repository;
        }

        return '';
    }

    /**
     * Get the base SVG path (where config.json is located)
     */
    public static function getBaseSvgPath(): string
    {
        return static::getConfigPath();
    }

    /**
     * Get the files directory path (where SVG files are stored)
     */
    public static function getFilesPath(): string
    {
        return static::getConfigPath().DIRECTORY_SEPARATOR.'files';
    }

    /**
     * Get path to a specific category/variant folder
     *
     * @param  string|null  $subfolder  Optional subfolder (category or variant name)
     * @return string Full path to the SVG files directory or subfolder
     */
    public static function getSvgPath(?string $subfolder = null): string
    {
        $basePath = static::getFilesPath();

        if ($subfolder === null) {
            return $basePath;
        }

        return $basePath.DIRECTORY_SEPARATOR.$subfolder;
    }

    /**
     * Get all category paths
     *
     * @return array<string, string> Map of category slug to full path
     */
    public static function getCategoryPaths(): array
    {
        $paths = [];
        $basePath = static::getFilesPath();

        foreach (static::getCategories() as $category) {
            $paths[$category] = $basePath.DIRECTORY_SEPARATOR.$category;
        }

        return $paths;
    }

    /**
     * Get all variant paths
     *
     * @return array<string, string> Map of variant slug to full path
     */
    public static function getVariantPaths(): array
    {
        $paths = [];
        $basePath = static::getFilesPath();

        foreach (static::getVariants() as $variant) {
            $paths[$variant] = $basePath.DIRECTORY_SEPARATOR.$variant;
        }

        return $paths;
    }

    /**
     * Get default CSS class for icons
     */
    public static function getDefaultClass(): string
    {
        return static::config()['config']['defaults']['class'] ?? '';
    }

    /**
     * Get default stroke width (for outline icons)
     */
    public static function getDefaultStrokeWidth(): int
    {
        return static::config()['config']['defaults']['stroke_width'] ?? 2;
    }

    /**
     * Get default attributes for icons
     */
    public static function getDefaultAttributes(): array
    {
        return static::config()['config']['defaults']['attributes'] ?? [];
    }

    /**
     * Whether the pack declares an upstream tracking block.
     */
    public static function hasUpstream(): bool
    {
        return ! empty(static::config()['upstream'] ?? null);
    }

    /**
     * Return the full upstream block (or empty array if absent).
     *
     * Shape (see documentation/icon-pack-upstream-tracking.md for the
     * full schema):
     *
     *   - source.type        "npm" | "github" | "github-tag" | "packagist" | "url"
     *   - source.package     npm package id (type=npm) -- e.g. "@twemoji/svg"
     *   - source.owner/repo  GitHub coordinates (type=github | github-tag)
     *   - source.vendor/package  Packagist coordinates (type=packagist)
     *   - source.version_field   dot-path into JSON response (type=url)
     *   - source.release_url_template  templated release URL (type=url)
     *   - current_version    pack-vendored release tag (e.g. "17.0.0")
     *   - version_check_url  URL the checker GETs for the latest version
     *   - license            asset licence (informational)
     *   - cdn                { provider => "https://.../{version}/{name}.svg" }
     *   - update_command     { type, path, args[] } -- how to refresh assets
     *   - additional_sources [{ name, type, ... }] -- secondary trackers
     */
    public static function getUpstream(): array
    {
        return static::config()['upstream'] ?? [];
    }

    /**
     * The version the pack was last refreshed from. Returns null when
     * the pack hasn't opted into upstream tracking.
     */
    public static function getUpstreamCurrentVersion(): ?string
    {
        return static::getUpstream()['current_version'] ?? null;
    }

    /**
     * URL the update checker hits to discover the latest upstream
     * release. Typically a registry endpoint (npm/packagist) or a
     * GitHub releases / tags API.
     */
    public static function getUpstreamVersionCheckUrl(): ?string
    {
        return static::getUpstream()['version_check_url'] ?? null;
    }

    /**
     * CDN URL templates as `{ provider => template }`.
     *
     * Each template MAY contain `{version}`, `{codepoint}`, `{name}`,
     * `{variant}`, `{ratio}`, etc. placeholders that callers substitute.
     */
    public static function getUpstreamCdnUrls(): array
    {
        return static::getUpstream()['cdn'] ?? [];
    }

    /**
     * Return the `update_command` block consumed by the maintainer-side
     * sync toolkit (see https://github.com/ichava/maintainer-toolkit).
     * Returns null when the pack doesn't advertise a refresh recipe.
     *
     * Recognised shapes (the toolkit dispatches on `type`; see
     * documentation/icon-pack-maintainer-sync.md):
     *
     *   { "type": "npm",            "package": "...",     "source_path": "..." }
     *   { "type": "github-archive", "archive_url": "...", "source_path": "..." }
     *   { "type": "recipe",         "recipe": "emoji-sets" }
     *   { "type": "url",            "version_field": "version" }
     *
     * @return array<string, mixed>|null
     */
    public static function getUpstreamUpdateCommand(): ?array
    {
        $command = static::getUpstream()['update_command'] ?? null;

        return is_array($command) ? $command : null;
    }

    /**
     * Secondary trackers a pack wants the checker to poll alongside the
     * primary `source`. Each entry mirrors the `source` shape but adds
     * a `name` field used for event correlation.
     *
     * @return list<array<string, mixed>>
     */
    public static function getUpstreamAdditionalSources(): array
    {
        $sources = static::getUpstream()['additional_sources'] ?? [];

        return is_array($sources) ? array_values($sources) : [];
    }

    /**
     * Load and cache config data from config.json for the calling child class.
     */
    final protected static function config(): array
    {
        $class = static::class;

        if (! isset(self::$configs[$class])) {
            $service = resolve(ConfigurationService::class);
            $path = static::getConfigPath();
            self::$configs[$class] = $service->rememberPackageConfig($path);
        }

        return self::$configs[$class];
    }

    /**
     * Get path to config.json directory (must be implemented by child)
     *
     * @return string Absolute path to directory containing config.json
     */
    abstract protected static function getConfigPath(): string;
}
