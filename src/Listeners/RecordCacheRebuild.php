<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Listeners;

use Throwable;
use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Events\IconCacheEvent;

/**
 * Remember the last cache rebuild, for StatusData.
 *
 * Kept in the cache store itself for 30 days: a rebuild that old is no
 * longer news, and a status that cannot read the store has bigger problems
 * to report than this line.
 */
final class RecordCacheRebuild
{
    public const string KEY = 'ichava:status:last_rebuild';

    /**
     * @return array<string, mixed>|null
     */
    public static function last(): ?array
    {
        try {
            $last = Cache::get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($last)) {
            return null;
        }

        $typed = [];

        foreach ($last as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    public function handle(IconCacheEvent $event): void
    {
        if (! $event->isRebuilt()) {
            return;
        }

        try {
            Cache::put(self::KEY, [...$event->getRebuildStats(), 'at' => now()->toIso8601String()], now()->addDays(30));
        } catch (Throwable) {
            // Status is best effort; a rebuild must not fail on it.
        }
    }
}
