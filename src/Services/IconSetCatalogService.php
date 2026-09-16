<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Throwable;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * IconSetCatalogService - High-level catalog of available Ichava icon sets.
 *
 * Reads the static `icon-sets.json` snapshot at the package root and
 * enriches each entry with local runtime state: whether the composer
 * package is installed and whether its icons have been seeded into the
 * database. Zero network calls — the snapshot (title, description,
 * license, icon count, variants, latest version) is refreshed daily by
 * `.github/workflows/sync-icon-sets.yml` via `bin/sync-icon-sets.php`.
 *
 * To offer a new icon set, append key/package/repository to
 * `icon-sets.json` and let the workflow fill in the snapshot.
 */
class IconSetCatalogService
{
    public function __construct(
        protected Filesystem $files,
        protected IconRegistry $registry,
        protected ?string $catalogPath = null,
    ) {}

    public function catalogPath(): string
    {
        return $this->catalogPath ?? dirname(__DIR__, 2) . '/icon-sets.json';
    }

    /**
     * Catalog entries from `icon-sets.json`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws IchavaException on missing or malformed catalog
     */
    public function load(): array
    {
        $path = $this->catalogPath();

        if (! $this->files->exists($path)) {
            throw IchavaException::invalidConfig('Icon set catalog not found', $path);
        }

        $decoded = json_decode($this->files->get($path), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw IchavaException::invalidConfig(
                'JSON parse error: ' . json_last_error_msg(),
                $path,
            );
        }

        $sets = $decoded['sets'] ?? null;

        if (! is_array($sets)) {
            throw IchavaException::invalidConfig(
                'Catalog must contain a "sets" array',
                $path,
            );
        }

        foreach ($sets as $index => $set) {
            $missing = [];
            foreach (['key', 'package', 'repository', 'title', 'icon_count', 'variants'] as $field) {
                if (! isset($set[$field]) || $set[$field] === '' || $set[$field] === []) {
                    $missing[] = $field;
                }
            }

            if ($missing !== []) {
                throw IchavaException::invalidConfig(
                    "Set at index {$index} is missing required fields: " . implode(', ', $missing),
                    $path,
                );
            }
        }

        return array_values($sets);
    }

    /**
     * Catalog entries enriched with installed + seeded state.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(fn (array $set) => $this->enrich($set), $this->load());
    }

    /**
     * Find a set by catalog key or composer package name.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $keyOrPackage): ?array
    {
        foreach ($this->all() as $set) {
            if ($set['key'] === $keyOrPackage || $set['package'] === $keyOrPackage) {
                return $set;
            }
        }

        return null;
    }

    /**
     * Latest synced release version for a composer package.
     *
     * Returns null when the snapshot carries no version so callers can
     * fall back to an unconstrained require.
     */
    public function latestTag(string $package): ?string
    {
        foreach ($this->load() as $set) {
            if ($set['package'] !== $package) {
                continue;
            }

            $version = $set['latest_version'] ?? null;

            if (! is_string($version) || $version === '') {
                return null;
            }

            $trimmed = ltrim($version, 'vV');

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    /**
     * Composer require target for a package, pinned to the synced release
     * when known, unconstrained otherwise (composer then installs the
     * newest stable release itself).
     */
    public function requireTarget(string $package): string
    {
        $latest = $this->latestTag($package);

        return $latest !== null ? "{$package}:^{$latest}" : $package;
    }

    /**
     * @param array<string, mixed> $set
     *
     * @return array<string, mixed>
     */
    protected function enrich(array $set): array
    {
        $package = (string) $set['package'];

        $set['installed'] = $this->isInstalled($package);
        $set['installed_version'] = $this->installedVersion($package);

        [$seeded, $seededCount] = $this->seededState($package);
        $set['seeded'] = $seeded;
        $set['seeded_count'] = $seededCount;

        return $set;
    }

    protected function isInstalled(string $package): bool
    {
        if ($this->registry->isRegistered($package)) {
            return true;
        }

        try {
            return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
        } catch (Throwable) {
            return false;
        }
    }

    protected function installedVersion(string $package): ?string
    {
        try {
            if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($package)) {
                return null;
            }

            return InstalledVersions::getPrettyVersion($package);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: bool|null, 1: int} seeded flag (null = unknown, tables missing) + seeded row count
     */
    protected function seededState(string $package): array
    {
        try {
            if (! Schema::hasTable('ichava_icons')) {
                return [null, 0];
            }

            $count = Icon::where('package', $package)->count();

            return [$count > 0, $count];
        } catch (Throwable) {
            return [null, 0];
        }
    }
}
