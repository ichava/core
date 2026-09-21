<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Actions;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

/**
 * Every `ichava/*` package Composer has actually installed, read from the lock.
 *
 * `composer.lock` rather than `composer.json`, because the lock records what is
 * installed and at which version; the manifest records what was asked for.
 *
 * **`Filesystem` is injected rather than reaching for the `File::` facade.**
 * That is the whole reason this is an action: the lock file is the only input,
 * so with the filesystem substitutable this is testable against a fixture with
 * no application, no cache and no database. Inside `IconDiscoveryService` it
 * was reachable only through a cache wrapper.
 *
 * Caching stays with the caller. An action that decides when its own result is
 * stale is two things again.
 */
final readonly class DiscoverInstalledPackages
{
    public function __construct(private Filesystem $files) {}

    /**
     * @return list<array{name: string, version: string, description: string, homepage: string|null, type: string, time: string|null}>
     */
    public function __invoke(string $composerLockPath): array
    {
        if (! $this->files->exists($composerLockPath)) {
            return [];
        }

        $lockData = json_decode($this->files->get($composerLockPath), true);

        // A malformed lock is not an error worth throwing over: the caller wants
        // a list of packages and there are none it can prove.
        if (! is_array($lockData) || ! isset($lockData['packages']) || ! is_array($lockData['packages'])) {
            return [];
        }

        $packages = [];

        foreach ($lockData['packages'] as $package) {
            if (! is_array($package)) {
                continue;
            }

            $name = $package['name'] ?? '';

            if (! is_string($name) || ! Str::startsWith($name, 'ichava/')) {
                continue;
            }

            $packages[] = [
                'name'        => $name,
                'version'     => $package['version'] ?? 'unknown',
                'description' => $package['description'] ?? '',
                'homepage'    => $package['homepage'] ?? null,
                'type'        => $package['type'] ?? 'library',
                'time'        => $package['time'] ?? null,
            ];
        }

        return $packages;
    }
}
