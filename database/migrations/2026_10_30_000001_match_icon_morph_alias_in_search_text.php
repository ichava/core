<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Illuminate\Database\Migrations\Migration;
use Simtabi\Laranail\Ichava\Support\Database\IconSearchTextFunctions;

/**
 * Make the PostgreSQL search-text functions match the icon morph alias.
 *
 * Core registers the morph alias `icon`, and every termables row holds it, but
 * the create-tables migration installed functions that compared the column with
 * the Icon class name. No attached category or variant name ever reached
 * `search_text`. This recreates those functions with the alias and rebuilds
 * `search_text` for the icons already stored.
 *
 * PostgreSQL only; on SQLite, MySQL and MariaDB it does nothing. The rebuild
 * honours `ICHAVA_SKIP_FTS_BACKFILL`, as the create-tables migration does, for
 * installs too large to rebuild inside a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Helpers::dbDriverIsPgSql()) {
            return;
        }

        IconSearchTextFunctions::install(Icon::MORPH_ALIAS);

        if (! env('ICHAVA_SKIP_FTS_BACKFILL', false)) {
            IconSearchTextFunctions::rebuildAll();
        }
    }

    /**
     * Reinstates the functions as the create-tables migration installed them
     * before this fix, comparing with the class name. `search_text` is left as
     * it is; the next write to an icon refreshes it.
     */
    public function down(): void
    {
        if (! Helpers::dbDriverIsPgSql()) {
            return;
        }

        IconSearchTextFunctions::install(Icon::class);
    }
};
