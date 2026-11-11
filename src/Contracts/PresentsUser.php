<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

use Simtabi\Laranail\Ichava\Data\UserData;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Shapes the host's user for the browser.
 *
 * The result is the one user shape every surface sends (the Inertia `auth`
 * prop and the REST session endpoint alike): `id`, `name`, `avatarUrl` and
 * `abilities`. No email, password or token leaves the server unless the host
 * opts a field in.
 */
interface PresentsUser
{
    public function present(Authenticatable $user): UserData;
}
