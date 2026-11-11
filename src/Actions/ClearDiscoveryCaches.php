<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Actions;

use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Support\DiscoveryCacheGeneration;

/**
 * Invalidate everything `IconDiscoveryService` caches.
 *
 * Extracted because invalidation was the half of that class that got it wrong:
 * it forgot two keys of six, and could not have reached two of the rest. Three
 * of them are written through the FILE store and one through IconCacheService
 * under its own prefix, while the clear called `Cache::forget()` on the DEFAULT
 * store with hand-built keys. Seeding icons cleared nothing a reader noticed.
 *
 * Keeping the keys and the clear in one small object is the point. They were
 * six lines apart in a 967-line class and still drifted, because nothing there
 * owned the question "what did we write?".
 *
 * The md5-suffixed keys cannot be enumerated, so invalidation is a generation
 * counter: bump it and every key the service builds changes shape at once.
 * That is the mechanism `cache.version` already uses ecosystem-wide.
 */
final class ClearDiscoveryCaches
{
    /** The prefix every discovery cache key carries. */
    public const string PREFIX = DiscoveryCacheGeneration::PREFIX;

    /** The key holding the invalidation generation. */
    public const string GENERATION_KEY = DiscoveryCacheGeneration::KEY;

    public function handle(): void
    {
        // Written with `Cache::remember()` on the default store, so it is
        // forgettable by name and does not need the generation.
        Cache::forget(self::PREFIX . '.installed');

        // Everything else -- `.packages`, `.categories`, `.search` on the file
        // store, and `icons.search.db.*` through IconCacheService -- carries
        // the generation in its key. One increment retires all of them.
        DiscoveryCacheGeneration::advance();
    }
}
