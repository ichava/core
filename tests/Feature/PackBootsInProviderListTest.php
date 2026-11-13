<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Tests\Fixtures\BasePack\Providers\IconsServiceProvider;

/*
 * A pack listed beside core boots: its constants class is read while the
 * providers register, before the cache store is ready, so nothing on that
 * path may resolve the cache at construction. Every other pack test
 * registers the fixture after boot, which is how this went unseen.
 */

it('boots a pack provider registered with the application, not after it', function (): void {
    $this->extraProviders = [IconsServiceProvider::class];
    $this->refreshApplication();

    expect(app(IconRegistry::class)->isRegistered('ichava/icon-sets-base-fixture'))->toBeTrue();
});
