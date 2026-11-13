<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

use Simtabi\Laranail\Ichava\Data\PreferenceOwner;

/**
 * A preference store keyed by owner (`owner_type`, `owner_id`) instead of by
 * session, so a signed-in user's favourites, collections and settings follow
 * them across devices.
 *
 * Core ships the contract, not a table. The rows belong to ichava/icon-browser,
 * which owns the features that write them and publishes the migration; core
 * ships no migration for them. When an implementation is bound, the
 * `PreferenceStore` core resolves routes signed-in users to it and keeps the
 * session store for guests, and a guest's session preferences merge into the
 * owner's on sign-in.
 */
interface OwnerPreferenceStore extends PreferenceStore
{
    /** A store scoped to one owner. Must not mutate the instance it is called on. */
    public function forOwner(PreferenceOwner $owner): static;

    /** The owner this store is scoped to, or null when it is unscoped. */
    public function owner(): ?PreferenceOwner;
}
