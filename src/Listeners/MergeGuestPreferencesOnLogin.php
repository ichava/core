<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Container\Container;
use Simtabi\Laranail\Ichava\Data\PreferenceOwner;
use Simtabi\Laranail\Ichava\Contracts\PreferenceStore;
use Simtabi\Laranail\Ichava\Events\PreferencesChanged;
use Simtabi\Laranail\Ichava\Actions\MergeGuestPreferences;
use Simtabi\Laranail\Ichava\Contracts\OwnerPreferenceStore;
use Simtabi\Laranail\Ichava\Support\OwnerAwarePreferenceStore;

/**
 * On the host's sign-in, fold the guest's session preferences into the user's
 * owner-keyed store.
 *
 * Does nothing unless an `OwnerPreferenceStore` is bound: with session storage
 * only, the guest's data is already the user's session data.
 */
final readonly class MergeGuestPreferencesOnLogin
{
    public function __construct(
        private Container $container,
        private MergeGuestPreferences $merge,
    ) {}

    public function handle(Login $event): void
    {
        if (! $this->container->bound(OwnerPreferenceStore::class)) {
            return;
        }

        $store = $this->container->make(PreferenceStore::class);

        if (! $store instanceof OwnerAwarePreferenceStore) {
            return;
        }

        $owner = $this->container->make(OwnerPreferenceStore::class)->forOwner(PreferenceOwner::fromUser($event->user));
        $changed = $this->merge->handle($store->guest(), $owner);

        if ($changed !== []) {
            PreferencesChanged::dispatch($changed, PreferencesChanged::MERGED, true);
        }
    }
}
