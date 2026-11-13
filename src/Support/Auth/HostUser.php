<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Auth;

use Closure;
use Simtabi\Laranail\Ichava\Data\UserData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Ichava\Contracts\PresentsUser;
use Simtabi\Laranail\Ichava\Contracts\ResolvesUser;

/**
 * The one place the host's user is read.
 *
 * Resolves the bound `ResolvesUser` and `PresentsUser` on every call rather
 * than holding them, so a host that rebinds either -- directly, or through
 * `Ichava::resolveUserUsing()` -- is honoured from that moment on.
 */
final readonly class HostUser
{
    public function __construct(
        private Container $container,
    ) {}

    /** The signed-in host user, or null for a guest. */
    public function user(): ?Authenticatable
    {
        return $this->container->make(ResolvesUser::class)->user();
    }

    /** The signed-in user in the shape the browser receives, or null. */
    public function current(): ?UserData
    {
        $user = $this->user();

        return $user === null ? null : $this->container->make(PresentsUser::class)->present($user);
    }

    /**
     * @param Closure(): ?Authenticatable $resolver
     */
    public function resolveUsing(Closure $resolver): void
    {
        $this->container->instance(ResolvesUser::class, new ClosureUserResolver($resolver));
    }

    /**
     * @param Closure(Authenticatable): UserData $presenter
     */
    public function presentUsing(Closure $presenter): void
    {
        $this->container->instance(PresentsUser::class, new ClosureUserPresenter($presenter));
    }
}
