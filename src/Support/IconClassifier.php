<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Services\IconRegistry;

/**
 * Decides what a pack file is before it is written to the database: its kind,
 * whether it is a theme twin of another file, and which twins it has.
 *
 * One rule for both writers. The seeder and the file watcher each turn files
 * into `ichava_icons` rows, and a rule written in only one of them would make
 * the two disagree about the same file on the next sync.
 *
 * Everything is read from the registry, which read the pack's config.json at
 * registration, so there is no second reader of that file.
 *
 * Theme twins: a category that declares `"theme_variants": {"dark": "-dark"}`
 * ships `scene.svg` and `scene-dark.svg`. The dark file is then a variant of
 * the light one, recorded on its row, and not an item of its own. A suffixed
 * file whose light sibling does not exist stays an ordinary item: there is
 * nothing to pair it with, and dropping it would lose an asset.
 */
final readonly class IconClassifier
{
    public function __construct(
        private IconRegistry $registry,
    ) {}

    /**
     * The category a stored path files under: the first folder after `files/`,
     * slugged, else the first folder at all.
     *
     * `files/fontawesome/solid/icon.svg` → `fontawesome`;
     * `files/test-icons/icon.svg` → `test-icons`.
     */
    public static function categoryOf(string $relativePath): ?string
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $relativePath))));

        if ($parts === []) {
            return null;
        }

        $filesIndex = array_search('files', $parts, true);

        if ($filesIndex !== false && isset($parts[$filesIndex + 1])) {
            $categoryPart = $parts[$filesIndex + 1];

            if (pathinfo($categoryPart, PATHINFO_EXTENSION) !== 'svg') {
                return Str::slug($categoryPart);
            }
        }

        foreach ($parts as $part) {
            if (pathinfo($part, PATHINFO_EXTENSION) !== 'svg') {
                return Str::slug($part);
            }
        }

        return null;
    }

    /** The kind of a stored path in a pack. */
    public function kind(string $package, string $relativePath): IconKind
    {
        return $this->registry->kindFor($package, self::categoryOf($relativePath));
    }

    /**
     * The stored path of the light file this one is a theme twin of, or null
     * when it is an item in its own right.
     */
    public function twinOf(string $package, string $basePath, string $relativePath): ?string
    {
        $stem = pathinfo($relativePath, PATHINFO_FILENAME);

        foreach ($this->suffixes($package, $relativePath) as $suffix) {
            if ($stem === $suffix || ! str_ends_with($stem, $suffix)) {
                continue;
            }

            $sibling = self::sibling($relativePath, substr($stem, 0, -strlen($suffix)));

            if (File::exists(self::absolute($basePath, $sibling))) {
                return $sibling;
            }
        }

        return null;
    }

    /**
     * The theme twins present on disk for a light file.
     *
     * Each carries its own file hash, so a changed twin changes the light
     * file's row and its render cache key with it.
     *
     * @return array<string, array{path: string, file_hash: string}> theme => twin
     */
    public function themeVariants(string $package, string $basePath, string $relativePath): array
    {
        if ($this->twinOf($package, $basePath, $relativePath) !== null) {
            return [];
        }

        $stem = pathinfo($relativePath, PATHINFO_FILENAME);
        $variants = [];

        foreach ($this->suffixes($package, $relativePath) as $theme => $suffix) {
            $twin = self::sibling($relativePath, $stem . $suffix);
            $absolute = self::absolute($basePath, $twin);

            if (File::exists($absolute)) {
                $variants[$theme] = ['path' => $twin, 'file_hash' => (string) md5_file($absolute)];
            }
        }

        ksort($variants);

        return $variants;
    }

    private static function sibling(string $relativePath, string $stem): string
    {
        $directory = pathinfo($relativePath, PATHINFO_DIRNAME);
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $file = $stem . ($extension === '' ? '' : '.' . $extension);

        return $directory === '.' || $directory === '' ? $file : $directory . '/' . $file;
    }

    private static function absolute(string $basePath, string $relativePath): string
    {
        return rtrim($basePath, '/\\') . '/' . ltrim($relativePath, '/\\');
    }

    /** @return array<string, string> theme => suffix */
    private function suffixes(string $package, string $relativePath): array
    {
        return $this->registry->themeVariantsFor($package, self::categoryOf($relativePath));
    }
}
