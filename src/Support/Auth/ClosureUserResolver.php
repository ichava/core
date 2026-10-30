<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Auth;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Ichava\Contracts\ResolvesUser;

/**
 * A resolver the host supplies as a closure, through `Ichava::resolveUserUsing()`.
 */
final readonly class ClosureUserResolver implements ResolvesUser
{
    /**
     * @param Closure(): ?Authenticatable $resolver
     */
    public function __construct(
        private Closure $resolver,
    ) {}

    public function user(): ?Authenticatable
    {
        return ($this->resolver)();
    }
}
