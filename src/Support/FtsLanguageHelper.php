<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use stdClass;
use Exception;
use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;

/**
 * Full-Text Search Language Helper
 *
 * Provides utilities for PostgreSQL FTS language configuration.
 * Handles language detection, validation, and multilingual search.
 *
 * MULTILINGUAL BY DESIGN:
 * - Defaults to 'simple' (language-agnostic, works for ALL languages)
 * - Supports category and variant search
 * - Flexible strategy configuration
 */
final class FtsLanguageHelper
{
    /**
     * Available PostgreSQL text search configurations
     */
    private const AVAILABLE_LANGUAGES = [
        'simple',
        'arabic',
        'danish',
        'dutch',
        'english',
        'finnish',
        'french',
        'german',
        'greek',
        'hungarian',
        'indonesian',
        'irish',
        'italian',
        'lithuanian',
        'nepali',
        'norwegian',
        'portuguese',
        'romanian',
        'russian',
        'spanish',
        'swedish',
        'tamil',
        'turkish',
    ];

    public function __construct(
        private IchavaLogger $logger,
    ) {}

    /**
     * Get the search strategy
     *
     * @return string 'simple'|'multilingual'|'single'
     */
    public static function getStrategy(): string
    {
        $strategy = config('ichava.ichava-core.database.search.strategy', 'simple');

        return is_string($strategy) ? $strategy : 'simple';
    }

    /**
     * Get the configured primary language for FTS
     *
     * @return literal-string One of AVAILABLE_LANGUAGES.
     */
    public static function getPrimaryLanguage(): string
    {
        $language = config('ichava.ichava-core.database.search.language', 'simple');

        // For static context, use simple validation without DB check
        if (! is_string($language) || ! in_array($language, self::AVAILABLE_LANGUAGES, true)) {
            return 'simple';
        }

        return $language;
    }

    /**
     * Check if multilingual search is enabled
     */
    public static function isMultilingual(): bool
    {
        return self::getStrategy() === 'multilingual';
    }

    /**
     * Check if simple strategy is used (default, works for all languages)
     */
    public static function isSimple(): bool
    {
        return self::getStrategy() === 'simple';
    }

    /**
     * Get all configured languages for search
     *
     * Every entry is one of AVAILABLE_LANGUAGES, so each is a literal SQL-safe name.
     *
     * @return array<literal-string>
     */
    public static function getLanguages(): array
    {
        $strategy = self::getStrategy();

        if ($strategy === 'simple') {
            return ['simple'];
        }

        if ($strategy === 'single') {
            return [self::getPrimaryLanguage()];
        }

        // Multilingual strategy
        $languages = config('ichava.ichava-core.database.search.languages', ['simple', 'english']);

        if (! is_array($languages)) {
            $languages = ['simple', 'english'];
        }

        // Static context: simple validation without DB check or logger,
        // mirroring getPrimaryLanguage() above.
        return array_map(
            fn (mixed $lang): string => is_string($lang) && in_array($lang, self::AVAILABLE_LANGUAGES, true) ? $lang : 'simple',
            $languages,
        );
    }

    /**
     * Get search scope configuration
     *
     * @return array<array-key, mixed>
     */
    public static function getSearchScope(): array
    {
        $default = [
            'icon_name'    => true,
            'keywords'     => true,
            'tags'         => true,
            'categories'   => true,
            'variants'     => true,
            'metadata'     => true,
            'package_name' => false,
        ];

        $scope = config('ichava.ichava-core.database.search.scope', $default);

        return is_array($scope) ? $scope : $default;
    }

    /**
     * Check if a specific scope is enabled
     */
    public static function isScopeEnabled(string $scope): bool
    {
        $enabled = self::getSearchScope()[$scope] ?? false;

        return is_bool($enabled) ? $enabled : false;
    }

    /**
     * Build a search query including categories and variants
     *
     * Creates a query that searches:
     * - Icon names, keywords, tags
     * - Category names (including parent categories)
     * - Variant names
     * - Icon metadata
     *
     * Both arguments are interpolated into SQL, so both must be literals.
     *
     * @param literal-string $iconMorphType
     * @param literal-string $table
     *
     * @return literal-string
     */
    public static function buildComprehensiveSearchQuery(string $iconMorphType, string $table = 'ichava_icons'): string
    {
        // Qualify with the real table, not a bare `i` alias.
        //
        // Every column reference here used to read `i.name`, `i.id`, and so on,
        // but nothing ever aliased the table to `i`: `Icon::query()` emits
        // `from "ichava_icons"`. The clause was therefore invalid SQL on the
        // one driver it was written for. It went unnoticed because
        // `scopeSearch()` had no caller in src/ -- the service that should have
        // used it hand-wrote its own query instead, so this branch was never
        // reached until that duplication was removed.
        $i = $table;
        $languages = self::getLanguages();
        $scope = self::getSearchScope();

        $searchComponents = [];

        // Icon name is always searched
        $searchComponents[] = "{$i}.name";

        // Keywords and tags (JSON arrays)
        if ($scope['keywords']) {
            $searchComponents[] = "COALESCE(array_to_string(ARRAY(SELECT json_array_elements_text({$i}.keywords::json)), ' '), '')";
        }

        if ($scope['tags']) {
            $searchComponents[] = "COALESCE(array_to_string(ARRAY(SELECT json_array_elements_text({$i}.tags::json)), ' '), '')";
        }

        // Categories (including parent hierarchy)
        if ($scope['categories']) {
            $searchComponents[] = "COALESCE((
                WITH RECURSIVE term_tree AS (
                    SELECT t.id, t.name, t.parent_id
                    FROM ichava_icon_termables it
                    JOIN ichava_icon_terms t ON t.id = it.term_id
                    WHERE it.termable_type = '{$iconMorphType}'
                      AND it.termable_id = {$i}.id
                      AND t.type = 'category'
                    UNION ALL
                    SELECT parent.id, parent.name, parent.parent_id
                    FROM ichava_icon_terms parent
                    JOIN term_tree child ON child.parent_id = parent.id
                )
                SELECT string_agg(DISTINCT term_tree.name, ' ')
                FROM term_tree
            ), '')";
        }

        // Variants
        if ($scope['variants']) {
            $searchComponents[] = "COALESCE((
                SELECT string_agg(t.name, ' ')
                FROM ichava_icon_termables it
                JOIN ichava_icon_terms t ON t.id = it.term_id
                WHERE it.termable_type = '{$iconMorphType}'
                  AND it.termable_id = {$i}.id
                  AND t.type = 'variant'
            ), '')";
        }

        // Package name
        if ($scope['package_name']) {
            $searchComponents[] = "{$i}.package";
        }

        // Metadata (JSON object)
        if ($scope['metadata']) {
            $searchComponents[] = "COALESCE({$i}.metadata::text, '')";
        }

        $combinedText = implode(" || ' ' || ", $searchComponents);

        // Build the FTS query based on strategy
        if (count($languages) === 1) {
            $lang = $languages[0];

            return "to_tsvector('{$lang}', {$combinedText}) @@ plainto_tsquery('{$lang}', ?)";
        }

        // Multiple languages: OR across all
        $conditions = array_map(
            fn ($lang) => "to_tsvector('{$lang}', {$combinedText}) @@ plainto_tsquery('{$lang}', ?)",
            $languages,
        );

        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * Get language-specific stemming example
     *
     * @return array<string, mixed>
     */
    public static function getStemExample(string $language): array
    {
        try {
            $result = DB::selectOne(
                "SELECT to_tsvector(?, 'running quickly') as tokens",
                [$language],
            );

            return [
                'language' => $language,
                'input'    => 'running quickly',
                'tokens'   => $result instanceof stdClass ? ($result->tokens ?? null) : null,
            ];
        } catch (Exception $e) {
            return [
                'language' => $language,
                'error'    => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate and sanitize FTS language
     */
    public function validateLanguage(string $language): string
    {
        // Check if language is in our known list
        if (! in_array($language, self::AVAILABLE_LANGUAGES)) {
            $this->logger->warning("⚠️ Unknown FTS language: {$language}, falling back to simple");

            return 'simple';
        }

        // For PostgreSQL, verify the language configuration exists
        try {
            $exists = DB::selectOne(
                'SELECT 1 FROM pg_ts_config WHERE cfgname = ?',
                [$language],
            );

            if (! $exists) {
                $this->logger->warning("⚠️ Language configuration '{$language}' not found in PostgreSQL, falling back to simple");

                return 'simple';
            }

            return $language;
        } catch (Exception $e) {
            $this->logger->error("❌ Failed to validate FTS language: {$e->getMessage()}");

            return 'simple';
        }
    }
}
