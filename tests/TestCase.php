<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Simtabi\Laranail\Ichava\Providers\IchavaServiceProvider;

/**
 * Base TestCase for Ichava Package Tests
 *
 * Provides proper Laravel application setup for package testing
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Additional setup if needed
        $this->artisan('config:clear');
        $this->artisan('cache:clear');
    }

    /**
     * Get package providers
     *
     * @param Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            IchavaServiceProvider::class,
        ];
    }

    /**
     * Define environment setup
     *
     * @param Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Setup cache to use array driver for testing
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver'    => 'array',
            'serialize' => false,
        ]);

        // Setup Ichava config
        $app['config']->set('ichava.cache_enabled', true); // Enable with array driver
        $app['config']->set('ichava.default_set', 'test');
        $app['config']->set('ichava.cache_driver', 'array');
    }

    // Routes are loaded by IchavaServiceProvider via hasRoutes(['web', 'api']) ,
    // no defineRoutes() override needed.
}
