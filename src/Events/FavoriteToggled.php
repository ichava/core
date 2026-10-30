<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An icon was added to or removed from the current user's favourites.
 *
 * A host hook: listen to audit or mirror favourites without forking. Read the
 * user with `Ichava::currentUser()`; `persisted` is false when no store could
 * keep the change (a stateless host), so a listener can tell a real write from
 * a no-op.
 */
final readonly class FavoriteToggled
{
    use Dispatchable;

    public function __construct(
        public int $iconId,
        public bool $favorited,
        public bool $persisted,
    ) {}
}
