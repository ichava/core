<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Auth;

use Closure;
use Simtabi\Laranail\Ichava\Data\UserData;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Ichava\Contracts\PresentsUser;

/**
 * A presenter the host supplies as a closure, through `Ichava::presentUserUsing()`.
 */
final readonly class ClosureUserPresenter implements PresentsUser
{
    /**
     * @param Closure(Authenticatable): UserData $presenter
     */
    public function __construct(
        private Closure $presenter,
    ) {}

    public function present(Authenticatable $user): UserData
    {
        return ($this->presenter)($user);
    }
}
