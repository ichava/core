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

    private ?array $manifest = null;

    public function __construct(Filesystem $filesystem, string $manifestPath)
    {
        $this->filesystem = $filesystem;
        $this->manifestPath = $manifestPath;
    }

    /**
     * Build manifest from all registered icon sets
     */
    public function build(IconRegistry $registry): array
    {
        $compiled = [];
        $stats = [
            'total_icons' => 0,
            'total_sets' => 0,
            'built_at' => now()->toIso8601String(),
            'sets' => [],
        ];

        foreach ($registry->sets() as $setName) {
            try {
                $set = $registry->set($setName);
                $config = $set->config();

                $icons = [
                    'name' => $config->name,
                    'prefix' => $config->prefix ?? $setName,
                    'path' => $config->path,
                    'variants' => $config->variants,
                    'categories' => [],
                    'icons' => [],
                ];

                // Discover all icons
                $allIcons = $set->all();

                foreach ($allIcons as $icon) {
                    $iconKey = $this->formatIconKey($icon->name, $icon->variant, $icon->category);
                    $icons['icons'][$iconKey] = [
                        'name' => $icon->name,
                        'path' => $icon->path,
                        'variant' => $icon->variant,
                        'category' => $icon->category,
                        'set' => $icon->set,
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
                    'count' => $iconCount,
                    'variants' => count($config->variants),
                    'categories' => count($icons['categories']),
                ];

                $compiled[$setName] = $icons;
            } catch (Exception $e) {
                // Skip sets that fail to load
                continue;
            }
        }

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
        $manifest = $this->build($registry);

        $this->ensureDirectoryExists();

        $content = "<?php\n\ndeclare(strict_types=1);\n\n";
        $content .= "// Ichava Icon Manifest\n";
        $content .= "// Generated: {$manifest['_stats']['built_at']}\n";
        $content .= "// Total Icons: {$manifest['_stats']['total_icons']}\n";
        $content .= "// Total Sets: {$manifest['_stats']['total_sets']}\n\n";
        $content .= 'return '.var_export($manifest, true).";\n";

        $tempPath = $this->manifestPath.'.tmp.'.bin2hex(random_bytes(4));

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
            $this->manifest = $this->filesystem->getRequire($this->manifestPath);

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
     */
    public function getStats(): ?array
    {
        $manifest = $this->load();

        return $manifest['_stats'] ?? null;
    }

    /**
     * Get icons for a specific set
     */
    public function getSet(string $setName): ?array
    {
        $manifest = $this->load();

        return $manifest[$setName] ?? null;
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
     */
    public function getIcon(string $setName, string $name, ?string $variant = null, ?string $category = null): ?array
    {
        $set = $this->getSet($setName);

        if (! $set) {
            return null;
        }

        $iconKey = $this->formatIconKey($name, $variant, $category);

        return $set['icons'][$iconKey] ?? null;
    }

    /**
     * Check if icon exists in manifest
     */
    public function hasIcon(string $setName, string $name, ?string $variant = null, ?string $category = null): bool
    {
        return $this->getIcon($setName, $name, $variant, $category) !== null;
    }

    /**
     * Format icon key for manifest storage
     */
    protected function formatIconKey(string $name, ?string $variant, ?string $category): string
    {
        $key = $name;

        if ($category) {
            $key = "{$category}/{$key}";
        }

        if ($variant) {
            $key = "{$key}:{$variant}";
        }

        return $key;
    }

    /**
     * Ensure manifest directory exists
     */
    protected function ensureDirectoryExists(): void
    {
        $directory = dirname($this->manifestPath);

        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0755, true);
        }
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
}
