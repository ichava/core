<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when the update checker finds a vendored icon pack is
 * behind its upstream source.
 *
 * Host applications can subscribe in their EventServiceProvider to
 * route notifications -- Slack, email, GitHub issue, etc. We dispatch
 * once per stale pack per check run.
 */
final readonly class IconPackUpdateAvailable
{
    use Dispatchable;

    public function __construct(
        public string $packageName,
        public ?string $currentVersion,
        public string $latestVersion,
        public ?string $releaseUrl = null,
        public ?string $releaseNotes = null,
        /**
         * Which source under the pack's `upstream` block fired the
         * event. "primary" for the canonical source; the value of
         * `additional_sources[*].name` for secondary trackers (e.g.
         * emoji-sets dispatches one event for `primary` (Twemoji) and
         * one for `openmoji` whenever either upstream moves).
         */
        public string $sourceName = 'primary',
    ) {}
}
