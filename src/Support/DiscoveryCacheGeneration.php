<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Actions\ClearDiscoveryCaches;

/**
 * The generation mixed into every discovery cache key. Advancing it retires
 * every key built with the previous one; {@see ClearDiscoveryCaches} does.
 *
 * Read on each key construction rather than memoised: a clear may run in
 * another request, and a memoised value would serve the previous generation
 * for the life of the instance.
 */
final class DiscoveryCacheGeneration
{
    /** The prefix every discovery cache key carries. */
    public const string PREFIX = 'ichava.discovery';

    /** The key holding the generation. */
    public const string KEY = self::PREFIX . '.generation';

    public static function current(): int
    {
        $generation = Cache::get(self::KEY, 0);

        return is_numeric($generation) ? (int) $generation : 0;
    }

    public static function advance(): void
    {
        Cache::increment(self::KEY);
    }
}
