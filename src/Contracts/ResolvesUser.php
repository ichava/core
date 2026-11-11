<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Finds the host application's signed-in user.
 *
 * Ichava has no auth of its own: favourites, preferences and admin access all
 * belong to whoever the host says is signed in. The default implementation
 * reads a guard (`ichava.ichava-core.auth.guard`, null for the default guard),
 * which covers session, Sanctum, Fortify, Jetstream and Breeze unchanged. A
 * host with an unusual setup binds its own, or passes a closure to
 * `Ichava::resolveUserUsing()`.
 */
interface ResolvesUser
{
    /** The signed-in user, or null for a guest. */
    public function user(): ?Authenticatable;
}
