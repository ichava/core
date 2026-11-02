<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The current user's browser preferences changed.
 *
 * `keys` names what changed; `reason` says why: `updated` (a write),
 * `cleared` (reset to defaults) or `merged` (a guest's preferences folded into
 * the owner's on sign-in).
 */
final readonly class PreferencesChanged
{
    use Dispatchable;

    public const string UPDATED = 'updated';

    public const string CLEARED = 'cleared';

    public const string MERGED = 'merged';

    /**
     * @param list<string> $keys
     */
    public function __construct(
        public array $keys,
        public string $reason,
        public bool $persisted,
    ) {}
}
