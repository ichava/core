<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Support;

use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A provider registered after core that maps core's morph alias to another
 * model, the silent replacement the boot check exists to refuse.
 */
final class ClaimsIchavaMorphAliasProvider extends ServiceProvider
{
    public function register(): void
    {
        Relation::morphMap([Icon::MORPH_ALIAS => IconTerm::class]);
    }
}
