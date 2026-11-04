<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Throwable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * The cached copy of each pack's parsed config.json, keyed by its directory.
 *
 * The one owner of those cache keys. ConfigurationService remembers through
 * it, and IconWatcherService and IconCacheService forget through it, so none
 * of the three needs another to reach a key (C-05: the cache service and the
 * watcher used to reach each other through the container).
 */
final readonly class PackConfigCache
{
    private const int TTL_SECONDS = 86_400;

    /**
     * The container, not the cache factory: a pack's constants class reads its
     * config while the providers register, before the cache store exists, so
     * the store is resolved on use and a failure falls back to reading the file.
     */
    public function __construct(private Container $app) {}

    /** The cache key for the config.json in $directoryPath. */
    public function key(string $directoryPath): string
    {
        return 'config:' . md5($directoryPath);
    }

    /**
     * The cached config for $directoryPath, or $load's result, stored for a day.
     * A cache that fails falls back to $load.
     *
     * @param callable(): mixed $load
     */
    public function remember(string $directoryPath, callable $load): mixed
    {
        try {
            return $this->store()->remember($this->key($directoryPath), self::TTL_SECONDS, $load(...));
        } catch (Throwable) {
            return $load();
        }
    }

    /** Drop the cached config for $directoryPath; false when nothing was dropped or the store failed. */
    public function forget(string $directoryPath): bool
    {
        try {
            return $this->store()->forget($this->key($directoryPath));
        } catch (Throwable) {
            return false;
        }
    }

    private function store(): Repository
    {
        $factory = $this->app->make(CacheFactory::class);

        return $factory->store('file');
    }
}
