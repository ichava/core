<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Ichava\Models\Icon;
use Illuminate\Foundation\PackageManifest;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Tests\Fixtures\TranslatedPack\Providers\IconsServiceProvider;

/*
 * A pack is installed by being required, nothing else (W1).
 *
 * Laravel's package discovery reads `extra.laravel.providers` from Composer's
 * installed.json and registers each provider it names; that is the whole of
 * an install. This walks the same chain from a fixture installed.json: the
 * manifest names the fixture pack's provider, the application registers what
 * the manifest names, and the pack's icons are then registered and seeded
 * with no registration call anywhere in this test or the host.
 */
it('discovers and seeds a pack named only in extra.laravel.providers', function (): void {
    config()->set('ichava.ichava-core.database.auto_seed', true);
    config()->set('ichava.ichava-core.database.use_queue', false);

    $manifestPath = sys_get_temp_dir() . '/ichava-w1-packages-' . getmypid() . '.php';
    $manifest = new PackageManifest(new Filesystem, dirname(__DIR__) . '/fixtures/DiscoveredApp', $manifestPath);

    try {
        $providers = $manifest->providers();
    } finally {
        @unlink($manifestPath);
    }

    expect($providers)->toBe([IconsServiceProvider::class])
        ->and(app(IconRegistry::class)->isRegistered('ichava/fixture-pack'))->toBeFalse();

    foreach ($providers as $provider) {
        $this->app->register($provider);
    }

    expect(app(IconRegistry::class)->isRegistered('ichava/fixture-pack'))->toBeTrue()
        ->and(Icon::where('package', 'ichava/fixture-pack')->pluck('name')->all())->toBe(['home']);
});
