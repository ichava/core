<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Exception;
use Illuminate\Filesystem\Filesystem;

/**
 * Icon manifest builder and manager
 *
 * Pre-compiles icon discovery for production performance
 */
final class IconsManifest
{
    private Filesystem $filesystem;

    private string $manifestPath;

    /** @var array<array-key, mixed>|null */
    private ?array $manifest = null;

    public function __construct(Filesystem $filesystem, string $manifestPath)
    {
        $this->filesystem = $filesystem;
        $this->manifestPath = $manifestPath;
    }

    /**
     * Build manifest from all registered icon sets
     *
     * @return array<string, array<string, mixed>> Each set's entry, keyed by set name, plus `_stats`
     */
    public function build(IconRegistry $registry): array
    {
        [$compiled, $stats] = $this->compile($registry);

        $compiled['_stats'] = $stats;

        return $compiled;
    }

    /**
     * Write manifest to cache file using an atomic temp-file + rename so a
     * concurrent reader never observes a half-written file (which would crash
     * `getRequire()` when load() is called mid-write).
     */
    public function write(IconRegistry $registry): bool
    {
        [$manifest, $stats] = $this->compile($registry);
        $manifest['_stats'] = $stats;

        $this->ensureDirectoryExists();

        $content = "<?php\n\ndeclare(strict_types=1);\n\n";
        $content .= "// Ichava Icon Manifest\n";
        $content .= "// Generated: {$stats['built_at']}\n";
        $content .= "// Total Icons: {$stats['total_icons']}\n";
        $content .= "// Total Sets: {$stats['total_sets']}\n\n";
        $content .= 'return ' . var_export($manifest, true) . ";\n";

        $tempPath = $this->manifestPath . '.tmp.' . bin2hex(random_bytes(4));

        if (! $this->filesystem->put($tempPath, $content)) {
            return false;
        }

        // rename() is atomic on POSIX when source and target are on the same
        // filesystem. ensureDirectoryExists() guarantees that.
        if (! @rename($tempPath, $this->manifestPath)) {
            @unlink($tempPath);

            return false;
        }

        return true;
    }

    /**
     * Load manifest from cache
     *
     * @return array<array-key, mixed>|null
     */
    public function load(): ?array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (! $this->exists()) {
            return null;
        }

        try {
            $loaded = $this->filesystem->getRequire($this->manifestPath);

            // A file that does not return an array is not a manifest.
            $this->manifest = is_array($loaded) ? $loaded : null;

            return $this->manifest;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Delete manifest file
     */
    public function delete(): bool
    {
        if (! $this->exists()) {
            return true;
        }

        return $this->filesystem->delete($this->manifestPath);
    }

    /**
     * Check if manifest exists
     */
    public function exists(): bool
    {
        return $this->filesystem->exists($this->manifestPath);
    }

    /**
     * Get manifest path
     */
    public function getPath(): string
    {
        return $this->manifestPath;
    }

    /**
     * Get manifest statistics
     *
     * The generator writes `_stats` as a map keyed by name, so only string
     * keys are returned.
     *
     * @return array<string, mixed>|null
     */
    public function getStats(): ?array
    {
        $stats = $this->load()['_stats'] ?? null;

        if (! is_array($stats)) {
            return null;
        }

        $named = [];

        foreach ($stats as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            }
        }

        return $named;
    }

    /**
     * Get icons for a specific set
     *
     * @return array<array-key, mixed>|null
     */
    public function getSet(string $setName): ?array
    {
        $set = $this->load()[$setName] ?? null;

        return is_array($set) ? $set : null;
    }

    /**
     * Check if set exists in manifest
     */
    public function hasSet(string $setName): bool
    {
        return $this->getSet($setName) !== null;
    }

    /**
     * Get icon from manifest
     *
     * @return array<array-key, mixed>|null
     */
    public function getIcon(string $setName, string $name, ?string $variant = null, ?string $category = null): ?array
    {
        $set = $this->getSet($setName);

        if (! $set) {
            return null;
        }

        $iconKey = $this->formatIconKey($name, $variant, $category);

        $icons = $set['icons'] ?? null;
        $icon = is_array($icons) ? ($icons[$iconKey] ?? null) : null;

        return is_array($icon) ? $icon : null;
    }

    /**
     * Check if icon exists in manifest
     */
    public function hasIcon(string $setName, string $name, ?string $variant = null, ?string $category = null): bool
    {
        return $this->getIcon($setName, $name, $variant, $category) !== null;
    }

    /**
     * Get manifest size in bytes
     */
    public function getSize(): int
    {
        if (! $this->exists()) {
            return 0;
        }

        return $this->filesystem->size($this->manifestPath);
    }

    /**
     * Get manifest age in seconds
     */
    public function getAge(): int
    {
        if (! $this->exists()) {
            return 0;
        }

        return time() - $this->filesystem->lastModified($this->manifestPath);
    }

    /**
     * Check if manifest is stale (older than specified seconds)
     */
    public function isStale(int $maxAge = 3600): bool
    {
        if (! $this->exists()) {
            return true;
        }

        return $this->getAge() > $maxAge;
    }

    /**
     * Every set's entry, and the statistics written beside them as `_stats`.
     *
     * @return array{
     *     0: array<string, array<string, mixed>>,
     *     1: array{total_icons: int, total_sets: int, built_at: string, sets: array<string, array{count: int, variants: int, categories: int}>}
     * }
     */
    private function compile(IconRegistry $registry): array
    {
        $compiled = [];
        $stats = [
            'total_icons' => 0,
            'total_sets'  => 0,
            'built_at'    => now()->toIso8601String(),
            'sets'        => [],
        ];

        foreach ($registry->sets() as $setName) {
            try {
                $set = $registry->set($setName);
                $config = $set->config();

                $icons = [
                    'name'   => $config->name,
                    'prefix' => $config->prefix,
                    // IconSetConfig has no `path`; reading it wrote null into every manifest.
                    'path'       => $config->basePath,
                    'variants'   => $config->variants,
                    'categories' => [],
                    'icons'      => [],
                ];

                // Discover all icons
                $allIcons = $set->all();

                foreach ($allIcons as $icon) {
                    $iconKey = $this->formatIconKey($icon->name, $icon->variant, $icon->category);
                    $icons['icons'][$iconKey] = [
                        'name'     => $icon->name,
                        'path'     => $icon->path,
                        'variant'  => $icon->variant,
                        'category' => $icon->category,
                        'set'      => $icon->set,
                    ];

                    // Track categories
                    if ($icon->category && ! in_array($icon->category, $icons['categories'])) {
                        $icons['categories'][] = $icon->category;
                    }
                }

                $iconCount = count($icons['icons']);
                $icons['count'] = $iconCount;

                $stats['total_icons'] += $iconCount;
                $stats['total_sets']++;
                $stats['sets'][$setName] = [
                    'count'      => $iconCount,
                    'variants'   => count($config->variants),
                    'categories' => count($icons['categories']),
                ];

                $compiled[$setName] = $icons;
            } catch (Exception $e) {
                // Skip sets that fail to load
                continue;
            }
        }

        return [$compiled, $stats];
    }

    /**
     * Format icon key for manifest storage
     */
    private function formatIconKey(string $name, ?string $variant, ?string $category): string
    {
        $key = $name;

        if ($category !== null && $category !== '') {
            $key = "{$category}/{$key}";
        }

        if ($variant !== null && $variant !== '') {
            $key = "{$key}:{$variant}";
        }

        return $key;
    }

    /**
     * Ensure manifest directory exists
     */
    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->manifestPath);

        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0755, true);
        }
    }
}
