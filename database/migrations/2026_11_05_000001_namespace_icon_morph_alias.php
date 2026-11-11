<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Illuminate\Database\Migrations\Migration;
use Simtabi\Laranail\Ichava\Support\Database\IconSearchTextFunctions;

/**
 * Move Icon's stored morph alias from the bare `icon` to `ichava-icon` (C-02).
 *
 * The morph map is one flat map per application, so a host or package mapping
 * its own model to `icon` replaced core's entry and every termables row then
 * loaded that model. Rows written under `icon` move to the scoped alias, and on
 * PostgreSQL the search-text functions are reinstalled to compare with it.
 * down() moves them back and reinstalls the functions for `icon`.
 */
return new class extends Migration
{
    private const string PREVIOUS_ALIAS = 'icon';

    public function up(): void
    {
        $this->move(self::PREVIOUS_ALIAS, Icon::MORPH_ALIAS);
    }

    public function down(): void
    {
        $this->move(Icon::MORPH_ALIAS, self::PREVIOUS_ALIAS);
    }

    private function move(string $from, string $to): void
    {
        DB::table('ichava_icon_termables')->where('termable_type', $from)->update(['termable_type' => $to]);

        if (! Helpers::dbDriverIsPgSql()) {
            return;
        }

        IconSearchTextFunctions::install($to);

        if (! env('ICHAVA_SKIP_FTS_BACKFILL', false)) {
            IconSearchTextFunctions::rebuildAll();
        }
    }
};
