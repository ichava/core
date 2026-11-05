<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests;

use Illuminate\Filesystem\Filesystem;
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

    /**
     * This test's own file-cache directory, created per application boot.
     *
     * @var list<string>
     */
    private array $fileCacheDirectories = [];

    /**
     * Providers a test registers after core's, for the next refreshApplication().
     *
     * @var list<class-string>
     */
    protected array $extraProviders = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Additional setup if needed
        $this->artisan('config:clear');
        $this->artisan('cache:clear');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // A fresh Filesystem, not the File facade: some tests mock the facade, and the
        // mock would be asked to delete a directory it has no expectation for.
        $files = new Filesystem;

        foreach ($this->fileCacheDirectories as $directory) {
            $files->deleteDirectory($directory);
        }

        $this->fileCacheDirectories = [];
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
            ...$this->extraProviders,
        ];
    }

    /**
     * Define environment setup
     *
     * @param Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $this->configureDatabase($app);
        $this->configureCache($app);

        // `ichava.default_set` and friends were bare keys that no code reads. The
        // package namespaces its config under the short name it registers, so the
        // live key carries it.
        $app['config']->set('ichava.ichava-core.default_set', 'test');
    }

    /**
     * Point the suite at whichever database `DB_CONNECTION` names.
     *
     * The suite ran on in-memory SQLite and nothing else, which makes every
     * difference between drivers invisible: index-length limits, JSON column
     * semantics, `ON CONFLICT` versus `ON DUPLICATE KEY`, foreign-key enforcement,
     * and the whole PostgreSQL full-text path that SQLite never reaches. CI runs
     * this matrix against real servers; locally it stays on in-memory SQLite so the
     * inner loop needs no service running.
     *
     * @param Application $app
     */
    protected function configureDatabase($app): void
    {
        $driver = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', match ($driver) {
            'pgsql' => [
                'driver'      => 'pgsql',
                'host'        => env('DB_HOST', '127.0.0.1'),
                'port'        => env('DB_PORT', '5432'),
                'database'    => env('DB_DATABASE', 'ichava_test'),
                'username'    => env('DB_USERNAME', 'ichava'),
                'password'    => env('DB_PASSWORD', 'secret'),
                'charset'     => 'utf8',
                'prefix'      => '',
                'search_path' => 'public',
                'sslmode'     => 'prefer',
            ],
            'mysql', 'mariadb' => [
                'driver'    => $driver,
                'host'      => env('DB_HOST', '127.0.0.1'),
                'port'      => env('DB_PORT', '3306'),
                'database'  => env('DB_DATABASE', 'ichava_test'),
                'username'  => env('DB_USERNAME', 'ichava'),
                'password'  => env('DB_PASSWORD', 'secret'),
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
                'strict'    => true,
                'engine'    => 'InnoDB',
            ],
            default => [
                'driver'   => 'sqlite',
                'database' => env('DB_DATABASE', ':memory:'),
                'prefix'   => '',

                // Off by default on SQLite, and Laravel only turns it on when the key
                // is present. Without it the suite never enforced a foreign key, so a
                // cascade that MySQL and PostgreSQL apply was untested on the one
                // driver that ran.
                'foreign_key_constraints' => true,
            ],
        });
    }

    /**
     * @param Application $app
     */
    protected function configureCache($app): void
    {
        // The array store hands back the object it was given, so nothing here crosses
        // serialize()/unserialize(). A test that depends on that boundary has to switch
        // to a serialising store itself.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver'    => 'array',
            'serialize' => false,
        ]);

        // The services write through `cache()->store('file')` whatever the default
        // store is, and Testbench points that store at its own skeleton's
        // storage/framework/cache/data -- one directory under vendor/, shared by every
        // test and by every `--parallel` worker. Two workers searching for the same
        // term wrote the same md5 key, so one worker read the other's result against
        // its own empty in-memory database, and a `flush()` in one worker deleted
        // directories another was iterating (Finder's UnexpectedValueException). Each
        // test, and each application it boots, owns its directory instead.
        $directory = sys_get_temp_dir() . '/ichava-core-file-cache-' . getmypid() . '-' . bin2hex(random_bytes(8));
        $this->fileCacheDirectories[] = $directory;

        $app['config']->set('cache.stores.file', [
            'driver'    => 'file',
            'path'      => $directory,
            'lock_path' => $directory,
        ]);
    }

    // Routes are loaded by IchavaServiceProvider via hasRoutes(['web', 'api']) ,
    // no defineRoutes() override needed.
}
