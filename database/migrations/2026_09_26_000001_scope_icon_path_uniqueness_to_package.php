<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Scope icon path uniqueness to the owning package.
 *
 * `path` is stored relative to each pack's base directory, so two packs laid
 * out as `files/<category>/<name>.svg` produce identical strings. A UNIQUE
 * index on `path` alone made the second pack's seed overwrite the first pack's
 * row. An icon is identified by (package, path).
 *
 * MySQL key length at utf8mb4: package 100 + path 600 = 700 chars = 2800 bytes,
 * under InnoDB's 3072-byte limit for the DYNAMIC row format. Widening either
 * column means redoing that arithmetic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ichava_icons', function (Blueprint $table) {
            $table->dropUnique('unique_icon_path');
            $table->unique(['package', 'path'], 'uniq_icons_package_path');
        });
    }

    /**
     * Fails if two packages already hold the same relative path, which is
     * exactly the data this migration exists to allow. Clear one of them first.
     */
    public function down(): void
    {
        Schema::table('ichava_icons', function (Blueprint $table) {
            $table->dropUnique('uniq_icons_package_path');
            $table->unique('path', 'unique_icon_path');
        });
    }
};
