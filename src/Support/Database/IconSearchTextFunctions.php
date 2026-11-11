<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * The PostgreSQL functions behind `ichava_icons.search_text` that compare
 * `termable_type`.
 *
 * They live here rather than inline in a migration so that a later migration
 * can recreate them on an installed database. Every method is PostgreSQL-only
 * and the caller decides whether the driver is PostgreSQL.
 *
 * @internal
 */
final class IconSearchTextFunctions
{
    /**
     * Create, or replace, every function that compares `termable_type`.
     *
     * Pass the morph alias the termables rows actually hold, which is
     * `Icon::MORPH_ALIAS` while core's morph map is registered.
     */
    public static function install(string $iconMorphType): void
    {
        self::createRefreshFunction($iconMorphType);
        self::createTermRefreshFunction($iconMorphType);
        self::createTermableTriggerFunction($iconMorphType);
    }

    /**
     * Rebuild `search_text` for every icon, row by row, through the refresh
     * function. Holds row locks on `ichava_icons` for its duration.
     */
    public static function rebuildAll(): void
    {
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

    /**
     * The function that rebuilds one icon's search text from its name, keywords, tags and attached terms.
     */
    public static function createRefreshFunction(string $iconMorphType): void
    {
        $escapedMorphType = self::quoted($iconMorphType);

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
    }

    /**
     * The function that rebuilds the search text of every icon attached to one term.
     */
    public static function createTermRefreshFunction(string $iconMorphType): void
    {
        $escapedMorphType = self::quoted($iconMorphType);

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
    }

    /**
     * The trigger function on the termables pivot, which refreshes the icon a row attaches.
     */
    public static function createTermableTriggerFunction(string $iconMorphType): void
    {
        $escapedMorphType = self::quoted($iconMorphType);

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
    }

    /**
     * The value as the body of a standard SQL string literal. PostgreSQL treats
     * backslashes in a standard string as ordinary characters, so only the
     * single quote needs doubling. `addslashes()`, used here before, doubled the
     * backslashes in a class name and so could never match one either.
     */
    private static function quoted(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
