<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Auth;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Ichava\Contracts\ResolvesUser;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The default user resolver: the host's guard.
 *
 * Reads `ichava.ichava-core.auth.guard`, null meaning the application's default
 * guard. A host without an auth system bound has no users, so every request is
 * a guest rather than an error. A guard name that does not exist still throws:
 * that is a misconfiguration the host should see, not a silent guest.
 */
final readonly class GuardUserResolver implements ResolvesUser
{
    public function __construct(
        private Container $container,
    ) {}

    public function user(): ?Authenticatable
    {
        if (! $this->container->bound(AuthFactory::class)) {
            return null;
        }

        $guard = config('ichava.ichava-core.auth.guard');

        return $this->container->make(AuthFactory::class)
            ->guard(is_string($guard) && $guard !== '' ? $guard : null)
            ->user();
    }
}
