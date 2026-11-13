<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Record what each SVG is (`icon` or `illustration`) and its theme twins.
 *
 * `kind` is resolved by the seeder from the pack's config.json (category kind,
 * then the pack's kind, then `icon`). Every existing row is an icon, which is
 * what every row was before kinds existed, so the default is the backfill.
 *
 * `theme_variants` holds the files that are a theme of this one rather than
 * items of their own, e.g. `{"dark": {"path": "files/scenes/park-dark.svg",
 * "file_hash": "..."}}`. Null for an SVG with none.
 *
 * Additive on all four drivers. The index leads with `kind` because every
 * browser query filters on it, then narrows by package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ichava_icons', function (Blueprint $table) {
            $table->string('kind', 20)->default('icon');
            $table->json('theme_variants')->nullable();

            $table->index(['kind', 'package'], 'idx_icons_kind_package');
        });
    }

    public function down(): void
    {
        Schema::table('ichava_icons', function (Blueprint $table) {
            $table->dropIndex('idx_icons_kind_package');
            $table->dropColumn(['kind', 'theme_variants']);
        });
    }
};
