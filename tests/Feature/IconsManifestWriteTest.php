<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconsManifest;

/**
 * Regression coverage for IconsManifest::write().
 *
 * Why: write() previously called build($manager) with an undefined variable,
 * so any caller hit a TypeError. Nobody noticed because the canonical writer
 * was bypassed in favour of an ad-hoc shape in CacheOperationsService. This
 * pins the fix and locks in the on-disk format that load() expects.
 */
beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/ichava-manifest-write-' . bin2hex(random_bytes(4));
    mkdir($this->dir, 0755, true);
    $this->path = $this->dir . '/manifest.php';
});

afterEach(function (): void {
    if (file_exists($this->path)) {
        @unlink($this->path);
    }
    @rmdir($this->dir);
});

it('writes a manifest the canonical loader can read back', function (): void {
    $registry = $this->app->make(IconRegistry::class);
    $manifest = new IconsManifest(new Filesystem, $this->path);

    expect($manifest->write($registry))->toBeTrue();
    expect($manifest->exists())->toBeTrue();

    $loaded = $manifest->load();
    expect($loaded)->toBeArray();
    expect($loaded)->toHaveKey('_stats');
    expect($loaded['_stats'])->toHaveKey('total_sets');
    expect($loaded['_stats'])->toHaveKey('total_icons');
    expect($loaded['_stats'])->toHaveKey('built_at');
});

it("records each set's real base path, not null", function (): void {
    // It read IconSetConfig::$path, which does not exist, so every set's path was null.
    $registry = $this->app->make(IconRegistry::class);
    $manifest = new IconsManifest(new Filesystem, $this->path);
    $manifest->write($registry);

    $sets = array_filter($manifest->load(), fn ($v, $k) => $k !== '_stats' && is_array($v), ARRAY_FILTER_USE_BOTH);

    expect($sets)->not->toBeEmpty();
    foreach ($sets as $name => $set) {
        $this->assertIsString($set['path'] ?? null, "{$name} has no path");
        $this->assertDirectoryExists($set['path'], "{$name}'s path is not its directory");
    }
});
