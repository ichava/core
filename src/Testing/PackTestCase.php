<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Testing;

use Illuminate\Support\Env;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Simtabi\Laranail\Ichava\Providers\IchavaServiceProvider;

/**
 * The base test case every icon pack's suite extends (P-03).
 *
 * Boots core and the pack's provider, on the database `DB_CONNECTION` names
 * (in-memory SQLite by default; CI runs the PostgreSQL and MySQL matrix) and a
 * non-serialising array cache. A pack names its provider and nothing else:
 *
 * ```php
 * abstract class TestCase extends PackTestCase
 * {
 *     protected function packProvider(): string
 *     {
 *         return IconsServiceProvider::class;
 *     }
 * }
 * ```
 *
 * Requires orchestra/testbench, which every pack has in require-dev.
 */
abstract class PackTestCase extends Orchestra
{
    /**
     * The pack's service provider.
     *
     * @return class-string
     */
    abstract protected function packProvider(): string;

    /**
     * @param Application $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [IchavaServiceProvider::class, $this->packProvider()];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->configureDatabase($app);
        $this->configureCache($app);
    }

    /**
     * @param Application $app
     */
    protected function configureDatabase($app): void
    {
        $driver = Env::get('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', match ($driver) {
            'pgsql' => [
                'driver'      => 'pgsql',
                'host'        => Env::get('DB_HOST', '127.0.0.1'),
                'port'        => Env::get('DB_PORT', '5432'),
                'database'    => Env::get('DB_DATABASE', 'ichava_test'),
                'username'    => Env::get('DB_USERNAME', 'ichava'),
                'password'    => Env::get('DB_PASSWORD', 'secret'),
                'charset'     => 'utf8',
                'prefix'      => '',
                'search_path' => 'public',
                'sslmode'     => 'prefer',
            ],
            'mysql', 'mariadb' => [
                'driver'    => $driver,
                'host'      => Env::get('DB_HOST', '127.0.0.1'),
                'port'      => Env::get('DB_PORT', '3306'),
                'database'  => Env::get('DB_DATABASE', 'ichava_test'),
                'username'  => Env::get('DB_USERNAME', 'ichava'),
                'password'  => Env::get('DB_PASSWORD', 'secret'),
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
                'strict'    => true,
                'engine'    => 'InnoDB',
            ],
            default => [
                'driver'   => 'sqlite',
                'database' => Env::get('DB_DATABASE', ':memory:'),
                'prefix'   => '',

                // Off by default on SQLite, and Laravel only enables them when the key is
                // present, so without this the engine's cascading deletes go unenforced.
                'foreign_key_constraints' => true,
            ],
        });
    }

    /**
     * @param Application $app
     */
    protected function configureCache($app): void
    {
        // The array store returns the object it was given, so nothing here crosses
        // serialize()/unserialize(). A test that depends on that boundary must switch to a
        // serialising store itself.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver'    => 'array',
            'serialize' => false,
        ]);
    }
}
