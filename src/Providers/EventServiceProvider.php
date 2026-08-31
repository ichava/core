<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Providers;

use Simtabi\Laranail\Ichava\Events\IconCacheEvent;
use Simtabi\Laranail\Ichava\Events\IconRegistrationEvent;
use Simtabi\Laranail\Ichava\Listeners\InvalidateIconCache;
use Simtabi\Laranail\Ichava\Listeners\AutoUnseedOnUnregistration;
use Simtabi\Laranail\Ichava\Listeners\AutoSeedIconsOnRegistration;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * EventServiceProvider - Ichava Event & Listener Registration
 *
 * Maps events to their corresponding listeners for the Ichava icon management system.
 *
 * Event Classification:
 * - SETUP EVENTS: Run during initial system setup (registration, seeding)
 * - OPERATIONAL EVENTS: Run during normal operation (cache invalidation, updates)
 */
final class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * Event Classification:
     * - SETUP EVENTS (IconRegistrationEvent::registered): Always run, no lifecycle guards
     *   These track core setup (migrations, seeding) and mark system as READY
     * - OPERATIONAL EVENTS (IconCacheEvent::changed): Guarded by lifecycle checks
     *   Only run after core setup is complete (migrations + seeds + cache)
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // OPERATIONAL EVENT: Only runs when system is READY
        IconCacheEvent::class => [
            InvalidateIconCache::class,  // Guarded: hasCache()
        ],

        // SETUP EVENTS: Always run (track core setup)
        IconRegistrationEvent::class => [
            AutoSeedIconsOnRegistration::class,   // Seeds icons when package is registered
            AutoUnseedOnUnregistration::class,    // Removes icons when package is unregistered
        ],
    ];
}
