<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Support\FtsLanguageHelper;
use Simtabi\Laranail\Ichava\Support\Helpers;

/**
 * Create Ichava Icon Tables with Performance Optimizations
 *
 * This migration creates all Ichava tables with full indexing
 * optimized for high-traffic applications (1M+ requests, 3M+ icons).
 *
 * Tables:
 * - ichava_icons: Icon metadata storage (NOT SVG content)
 * - ichava_icon_terms: Categories and variants taxonomy
 * - ichava_icon_termables: Polymorphic many-to-many pivot
 *
 * Features:
 * - PostgreSQL full-text search with multilingual support
 * - Automatic search text updates via triggers
 * - Composite indexes for common query patterns
 * - PostgreSQL-specific optimizations (BRIN, Hash, Covering indexes)
 * - Hierarchical term support with recursive CTEs
 */
return new class extends Migration
{
    private const string ICON_MORPH_TYPE = Icon::class;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->createIconsTable();
        $this->createTermsTable();
        $this->createTermablesTable();
        $this->addPerformanceIndexes();
        $this->setupIconSearchText();
        $this->analyzeTablesForQueryPlanner();
    }

    /**
     * Create the icons table
     */
    protected function createIconsTable(): void
    {
        Schema::create('ichava_icons', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('package', 100);
            $table->string('name', 255);

            // File Information (NOT the content!)
            $table->string('path', 600);
            $table->string('file_hash', 64)->nullable(); // hash for cache busting

            // Search & Discovery (all JSON arrays)
            $table->json('tags')->nullable();           // JSON array of tags
            $table->json('keywords')->nullable();       // JSON array of keywords
            $table->json('search_text')->nullable();    // JSON array for FTS
            $table->text('search_text_plain')->nullable(); // Plain text for GIN FTS index

            // SVG Metadata & Attributes (JSON)
            $table->json('attributes')->nullable(); // JSON: viewbox, width, height, etc.
            $table->json('metadata')->nullable();   // JSON: color, weight, style, etc.

            // Timestamps
            $table->timestamp('file_modified_at')->nullable();
            $table->timestamps();

            // Unique constraint - path is the absolute identifier
            $table->unique('path', 'unique_icon_path');

            // Basic performance indexes
            $table->index('package', 'idx_icons_package');
            $table->index('name', 'idx_icons_name');
            $table->index('file_hash', 'idx_icons_file_hash');
        });
    }

    /**
     * Create the terms table (categories, variants)
     */
    protected function createTermsTable(): void
    {
        Schema::create('ichava_icon_terms', function (Blueprint $table) {
            $table->id();

            // 'category', 'variant', etc.
            $table->string('type', 120);

            $table->string('package', 260);
            $table->string('name', 260);
            $table->string('slug', 380);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('description')->nullable();

            $table->timestamps();

            // A slug is unique per type + package
            $table->unique(['type', 'slug', 'package'], 'uniq_icon_terms_type_slug_package');
            $table->index('type', 'idx_icon_terms_type');

            // Nesting support
            $table->foreign('parent_id')
                ->references('id')
                ->on('ichava_icon_terms')
                ->cascadeOnDelete();

            $table->index('parent_id', 'idx_icon_terms_parent_id');
        });
    }

    /**
     * Create the polymorphic pivot table
     */
    protected function createTermablesTable(): void
    {
        Schema::create('ichava_icon_termables', function (Blueprint $table) {
            $table->unsignedBigInteger('term_id');
            $table->morphs('termable'); // termable_id + termable_type
            $table->timestamps();

            $table->foreign('term_id')
                ->references('id')
                ->on('ichava_icon_terms')
                ->cascadeOnDelete();

            $table->unique(
                ['term_id', 'termable_id', 'termable_type'],
                'uniq_icon_termables_term'
            );

            $table->index(
                ['termable_type', 'termable_id'],
                'idx_icon_termables_model'
            );
        });
    }

    /**
     * Add performance indexes for high-traffic applications
     */
    protected function addPerformanceIndexes(): void
    {
        // =====================================================================
        // COMPOSITE INDEXES FOR COMMON FILTER COMBINATIONS
        // =====================================================================

        Schema::table('ichava_icons', function (Blueprint $table) {
            // Package + Name composite (for package-scoped searches)
            $table->index(['package', 'name'], 'idx_icons_package_name');

            // Package + created_at (for recent icons in package)
            $table->index(['package', 'created_at'], 'idx_icons_package_created');

            // Name + package composite (for name searches with package filter)
            $table->index(['name', 'package'], 'idx_icons_name_package');
        });

        // =====================================================================
        // OPTIMIZED PIVOT TABLE INDEXES
        // =====================================================================

        Schema::table('ichava_icon_termables', function (Blueprint $table) {
            // Reverse lookup: find all icons for a term (for category filtering)
            $table->index(['term_id', 'termable_type', 'termable_id'], 'idx_termables_term_lookup');

            // Performance index for term counting queries
            $table->index(['termable_type', 'term_id'], 'idx_termables_type_term');
        });

        // =====================================================================
        // TERM TABLE OPTIMIZATION
        // =====================================================================

        Schema::table('ichava_icon_terms', function (Blueprint $table) {
            // Type + Package composite (for filtering categories by package)
            $table->index(['type', 'package'], 'idx_terms_type_package');

            // Type + Slug composite (for fast term lookups)
            $table->index(['type', 'slug'], 'idx_terms_type_slug');

            // Parent lookup for hierarchical queries
            $table->index(['parent_id', 'type'], 'idx_terms_parent_type');
        });

        // =====================================================================
        // POSTGRESQL-SPECIFIC OPTIMIZATIONS
        // =====================================================================

        if (Helpers::dbDriverIsPgSql()) {
            // BRIN index for created_at (time-series queries, very space-efficient)
            DB::statement('CREATE INDEX IF NOT EXISTS idx_icons_created_brin ON ichava_icons USING BRIN (created_at)');

            // BRIN index for updated_at (efficient for time-based queries)
            DB::statement('CREATE INDEX IF NOT EXISTS idx_icons_updated_brin ON ichava_icons USING BRIN (updated_at)');

            // Hash index for exact package lookups (faster than B-tree for equality)
            DB::statement('CREATE INDEX IF NOT EXISTS idx_icons_package_hash ON ichava_icons USING HASH (package)');

            // Covering index for icon list queries (includes all commonly selected columns)
            DB::statement('
                CREATE INDEX IF NOT EXISTS idx_icons_list_covering 
                ON ichava_icons (package, name) 
                INCLUDE (id, path, file_hash, created_at, updated_at)
            ');
        }
    }

    /**
     * Setup PostgreSQL FTS helpers, triggers, and indexes
     */
    protected function setupIconSearchText(string $iconMorphType = self::ICON_MORPH_TYPE): void
    {
        if (! Helpers::dbDriverIsPgSql()) {
            return;
        }

        $escapedMorphType = addslashes($iconMorphType);
        $language = FtsLanguageHelper::getPrimaryLanguage();
        $isMultilingual = FtsLanguageHelper::isMultilingual();
        $languages = FtsLanguageHelper::getLanguages();

        // =====================================================================
        // 1. CORE REFRESH FUNCTION FOR A SINGLE ICON
        // =====================================================================

        DB::statement("
        CREATE OR REPLACE FUNCTION refresh_ichava_icon_search_text(p_icon_id bigint)
        RETURNS void AS \$\$
        DECLARE
            v_search_parts text[];
            v_term_names text[];
            v_search_json json;
            v_search_plain text;
        BEGIN
            -- Collect search components from icon and related terms
            WITH RECURSIVE term_tree AS (
                SELECT t.id, t.name, t.parent_id
                FROM ichava_icon_termables it
                JOIN ichava_icon_terms t ON t.id = it.term_id
                WHERE it.termable_type = '{$escapedMorphType}'
                  AND it.termable_id = p_icon_id
                UNION ALL
                SELECT parent.id, parent.name, parent.parent_id
                FROM ichava_icon_terms parent
                JOIN term_tree child ON child.parent_id = parent.id
            )
            SELECT array_agg(DISTINCT term_tree.name)
            INTO v_term_names
            FROM term_tree;

            -- Build search text array from all components
            SELECT ARRAY[
                i.name,
                COALESCE((
                    SELECT string_agg(value::text, ' ')
                    FROM json_array_elements_text(i.keywords::json)
                ), ''),
                COALESCE((
                    SELECT string_agg(value::text, ' ')
                    FROM json_array_elements_text(i.tags::json)
                ), ''),
                COALESCE(array_to_string(v_term_names, ' '), '')
            ]
            INTO v_search_parts
            FROM ichava_icons i
            WHERE i.id = p_icon_id;

            -- Convert to JSON
            v_search_json := array_to_json(array_remove(v_search_parts, ''));

            -- Convert to plain text for FTS
            v_search_plain := array_to_string(array_remove(v_search_parts, ''), ' ');

            -- Update both columns
            UPDATE ichava_icons
            SET search_text = v_search_json,
                search_text_plain = v_search_plain
            WHERE id = p_icon_id;
        END;
        \$\$ LANGUAGE plpgsql
    ");

        // =====================================================================
        // 2. HELPER: REFRESH ALL ICONS FOR A GIVEN TERM
        // =====================================================================

        DB::statement("
        CREATE OR REPLACE FUNCTION refresh_ichava_icons_search_text_for_term(p_term_id bigint)
        RETURNS void AS \$\$
        DECLARE
            r record;
        BEGIN
            FOR r IN
                SELECT termable_id
                FROM ichava_icon_termables
                WHERE term_id = p_term_id
                  AND termable_type = '{$escapedMorphType}'
            LOOP
                PERFORM refresh_ichava_icon_search_text(r.termable_id);
            END LOOP;
        END;
        \$\$ LANGUAGE plpgsql
    ");

        // =====================================================================
        // 3. TRIGGER FUNCTION ON ICHAVA_ICONS
        // =====================================================================

        DB::statement('
        CREATE OR REPLACE FUNCTION trg_refresh_ichava_icon_search_text()
        RETURNS trigger AS $$
        BEGIN
            PERFORM refresh_ichava_icon_search_text(NEW.id);
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
    ');

        DB::statement('
        CREATE TRIGGER trg_ichava_icons_search_text
        AFTER INSERT OR UPDATE OF name, keywords, tags
        ON ichava_icons
        FOR EACH ROW
        EXECUTE FUNCTION trg_refresh_ichava_icon_search_text()
    ');

        // =====================================================================
        // 4. TRIGGER FUNCTION ON PIVOT TABLE
        // =====================================================================

        DB::statement("
        CREATE OR REPLACE FUNCTION trg_refresh_ichava_icon_search_text_from_termable()
        RETURNS trigger AS \$\$
        DECLARE
            v_icon_id bigint;
        BEGIN
            IF (TG_OP = 'INSERT') THEN
                IF NEW.termable_type = '{$escapedMorphType}' THEN
                    v_icon_id := NEW.termable_id;
                ELSE
                    RETURN NEW;
                END IF;
            ELSIF (TG_OP = 'UPDATE') THEN
                IF NEW.termable_type = '{$escapedMorphType}' THEN
                    v_icon_id := NEW.termable_id;
                ELSIF OLD.termable_type = '{$escapedMorphType}' THEN
                    v_icon_id := OLD.termable_id;
                ELSE
                    RETURN NEW;
                END IF;
            ELSE
                IF OLD.termable_type = '{$escapedMorphType}' THEN
                    v_icon_id := OLD.termable_id;
                ELSE
                    RETURN OLD;
                END IF;
            END IF;

            PERFORM refresh_ichava_icon_search_text(v_icon_id);

            IF TG_OP = 'DELETE' THEN
                RETURN OLD;
            ELSE
                RETURN NEW;
            END IF;
        END;
        \$\$ LANGUAGE plpgsql
    ");

        DB::statement('
        CREATE TRIGGER trg_ichava_icon_termables_search_text
        AFTER INSERT OR UPDATE OR DELETE
        ON ichava_icon_termables
        FOR EACH ROW
        EXECUTE FUNCTION trg_refresh_ichava_icon_search_text_from_termable()
    ');

        // =====================================================================
        // 5. TRIGGER ON TERMS TABLE
        // =====================================================================

        DB::statement('
        CREATE OR REPLACE FUNCTION trg_refresh_ichava_icons_search_text_from_term()
        RETURNS trigger AS $$
        BEGIN
            PERFORM refresh_ichava_icons_search_text_for_term(NEW.id);
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
    ');

        DB::statement('
        CREATE TRIGGER trg_ichava_icon_terms_search_text
        AFTER UPDATE OF name
        ON ichava_icon_terms
        FOR EACH ROW
        EXECUTE FUNCTION trg_refresh_ichava_icons_search_text_from_term()
    ');

        // =====================================================================
        // 6. BACKFILL SEARCH_TEXT FOR EXISTING ROWS
        // =====================================================================
        //
        // The PL/pgSQL row-by-row loop below holds a write lock on the table
        // for the duration. On a multi-million-row install this can stall the
        // migration for minutes and time out CI. Set ICHAVA_SKIP_FTS_BACKFILL=1
        // in the env to opt out (fresh installs don't need it; the trigger
        // populates new rows. For existing-data installs, run the backfill
        // out-of-band via `php artisan ichava:database refresh`).

        if (! env('ICHAVA_SKIP_FTS_BACKFILL', false)) {
            DB::statement('
            DO $$
            DECLARE
                rec record;
            BEGIN
                FOR rec IN SELECT id FROM ichava_icons LOOP
                    PERFORM refresh_ichava_icon_search_text(rec.id);
                END LOOP;
            END $$;
        ');
        }

        // =====================================================================
        // 7. FTS INDEX USING GIN ON SEARCH_TEXT_PLAIN COLUMN
        // =====================================================================

        if ($isMultilingual && count($languages) > 1) {
            // Multilingual index: Create separate indexes for each language
            foreach ($languages as $index => $lang) {
                DB::statement("
                    CREATE INDEX IF NOT EXISTS idx_icons_search_{$lang}
                    ON ichava_icons
                    USING GIN (to_tsvector('{$lang}', COALESCE(search_text_plain, '')))
                ");
            }
        } else {
            // Single language index
            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_icons_search
                ON ichava_icons
                USING GIN (to_tsvector('{$language}', COALESCE(search_text_plain, '')))
            ");
        }
    }

    /**
     * Analyze tables for query planner optimization
     */
    protected function analyzeTablesForQueryPlanner(): void
    {
        $driver = DB::connection()->getDriverName();

        match ($driver) {
            'pgsql' => array_map(static fn (string $t) => DB::statement("ANALYZE {$t}"), [
                'ichava_icons',
                'ichava_icon_terms',
                'ichava_icon_termables',
            ]),
            'mysql', 'mariadb' => array_map(static fn (string $t) => DB::statement("ANALYZE TABLE {$t}"), [
                'ichava_icons',
                'ichava_icon_terms',
                'ichava_icon_termables',
            ]),
            default => null, // SQLite + others: ANALYZE is implicit / unsupported
        };
    }

    /**
     * Drop FTS-related triggers, functions and indexes
     */
    protected function dropIconSearchText(): void
    {
        if (! Helpers::dbDriverIsPgSql()) {
            return;
        }

        // Drop primary index
        DB::statement('DROP INDEX IF EXISTS idx_icons_search');

        // Drop multilingual indexes
        $languages = FtsLanguageHelper::getLanguages();
        foreach ($languages as $lang) {
            DB::statement("DROP INDEX IF EXISTS idx_icons_search_{$lang}");
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_ichava_icon_terms_search_text ON ichava_icon_terms');
        DB::unprepared('DROP FUNCTION IF EXISTS trg_refresh_ichava_icons_search_text_from_term()');
        DB::unprepared('DROP FUNCTION IF EXISTS refresh_ichava_icons_search_text_for_term(bigint)');

        DB::unprepared('DROP TRIGGER IF EXISTS trg_ichava_icon_termables_search_text ON ichava_icon_termables');
        DB::unprepared('DROP FUNCTION IF EXISTS trg_refresh_ichava_icon_search_text_from_termable()');

        DB::unprepared('DROP TRIGGER IF EXISTS trg_ichava_icons_search_text ON ichava_icons');
        DB::unprepared('DROP FUNCTION IF EXISTS trg_refresh_ichava_icon_search_text()');
        DB::unprepared('DROP FUNCTION IF EXISTS refresh_ichava_icon_search_text(bigint)');
    }

    /**
     * Drop performance indexes
     */
    protected function dropPerformanceIndexes(): void
    {
        // Drop PostgreSQL-specific indexes
        if (Helpers::dbDriverIsPgSql()) {
            DB::statement('DROP INDEX IF EXISTS idx_icons_created_brin');
            DB::statement('DROP INDEX IF EXISTS idx_icons_updated_brin');
            DB::statement('DROP INDEX IF EXISTS idx_icons_package_hash');
            DB::statement('DROP INDEX IF EXISTS idx_icons_list_covering');
        }

        // Drop standard indexes
        Schema::table('ichava_icons', function (Blueprint $table) {
            $table->dropIndex('idx_icons_package_name');
            $table->dropIndex('idx_icons_package_created');
            $table->dropIndex('idx_icons_name_package');
        });

        Schema::table('ichava_icon_termables', function (Blueprint $table) {
            $table->dropIndex('idx_termables_term_lookup');
            $table->dropIndex('idx_termables_type_term');
        });

        Schema::table('ichava_icon_terms', function (Blueprint $table) {
            $table->dropIndex('idx_terms_type_package');
            $table->dropIndex('idx_terms_type_slug');
            $table->dropIndex('idx_terms_parent_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropIconSearchText();
        $this->dropPerformanceIndexes();

        Schema::dropIfExists('ichava_icon_termables');
        Schema::dropIfExists('ichava_icon_terms');
        Schema::dropIfExists('ichava_icons');
    }
};
