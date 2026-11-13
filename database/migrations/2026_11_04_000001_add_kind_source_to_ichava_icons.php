<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Record which row of the kind resolution order decided each row's kind.
 *
 * `kind_source` is one of `host`, `item`, `rule`, `category`, `pack` or
 * `default` (KindSource). `kind_rule` names what matched: the host override
 * key, the pack rule's `match`, or the category slug; null otherwise.
 *
 * Additive on all four drivers. Every existing row reads `default` until the
 * next seed re-files it, which is expected and is what doctor's `kind.drift`
 * reports. `kind_rule` can hold a host config pattern, so it is kept out of
 * the public API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ichava_icons', function (Blueprint $table) {
            $table->string('kind_source', 16)->default('default');
            $table->string('kind_rule', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ichava_icons', function (Blueprint $table) {
            $table->dropColumn(['kind_source', 'kind_rule']);
        });
    }
};
