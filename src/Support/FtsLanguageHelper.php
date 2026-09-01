<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

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
        return config('ichava.core.database.search.strategy', 'simple');
    }

    /**
     * Get the configured primary language for FTS
     */
    public static function getPrimaryLanguage(): string
    {
        $language = config('ichava.core.database.search.language', 'simple');

        // For static context, use simple validation without DB check
        if (! in_array($language, self::AVAILABLE_LANGUAGES)) {
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
     * @return array<string>
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
        $languages = config('ichava.core.database.search.languages', ['simple', 'english']);

        return array_map(
            fn ($lang) => self::validateLanguage($lang),
            $languages,
        );
    }

    /**
     * Get search scope configuration
     */
    public static function getSearchScope(): array
    {
        return config('ichava.core.database.search.scope', [
            'icon_name' => true,
            'keywords' => true,
            'tags' => true,
            'categories' => true,
            'variants' => true,
            'metadata' => true,
            'package_name' => false,
        ]);
    }

    /**
     * Check if a specific scope is enabled
     */
    public static function isScopeEnabled(string $scope): bool
    {
        return self::getSearchScope()[$scope] ?? false;
    }

    /**
     * Build a search query including categories and variants
     *
     * Creates a query that searches:
     * - Icon names, keywords, tags
     * - Category names (including parent categories)
     * - Variant names
     * - Icon metadata
     */
    public static function buildComprehensiveSearchQuery(string $iconMorphType): string
    {
        $languages = self::getLanguages();
        $scope = self::getSearchScope();

        $searchComponents = [];

        // Icon name is always searched
        $searchComponents[] = 'i.name';

        // Keywords and tags (JSON arrays)
        if ($scope['keywords']) {
            $searchComponents[] = "COALESCE(array_to_string(ARRAY(SELECT jsonb_array_elements_text(i.keywords)), ' '), '')";
        }

        if ($scope['tags']) {
            $searchComponents[] = "COALESCE(array_to_string(ARRAY(SELECT jsonb_array_elements_text(i.tags)), ' '), '')";
        }

        // Categories (including parent hierarchy)
        if ($scope['categories']) {
            $searchComponents[] = "COALESCE((
                WITH RECURSIVE term_tree AS (
                    SELECT t.id, t.name, t.parent_id
                    FROM ichava_icon_termables it
                    JOIN ichava_icon_terms t ON t.id = it.term_id
                    WHERE it.termable_type = '{$iconMorphType}'
                      AND it.termable_id = i.id
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
                  AND it.termable_id = i.id
                  AND t.type = 'variant'
            ), '')";
        }

        // Package name
        if ($scope['package_name']) {
            $searchComponents[] = 'i.package';
        }

        // Metadata (JSON object)
        if ($scope['metadata']) {
            $searchComponents[] = "COALESCE(i.metadata::text, '')";
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

        return '('.implode(' OR ', $conditions).')';
    }

    /**
     * Build a multilingual FTS query (legacy, kept for compatibility)
     *
     * Creates a query that searches across multiple language configurations
     */
    public static function buildMultilingualQuery(string $searchTerm): string
    {
        $languages = self::getLanguages();

        if (count($languages) === 1) {
            return self::buildSingleLanguageQuery($searchTerm, $languages[0]);
        }

        // Build OR conditions for each language
        $conditions = array_map(
            fn ($lang) => self::buildSingleLanguageQuery($searchTerm, $lang),
            $languages,
        );

        return '('.implode(' OR ', $conditions).')';
    }

    /**
     * Build a single language FTS query (legacy)
     */
    public static function buildSingleLanguageQuery(string $searchTerm, string $language): string
    {
        return sprintf(
            "to_tsvector('%s', COALESCE(array_to_string(ARRAY(SELECT jsonb_array_elements_text(search_text)), ' '), '')) @@ plainto_tsquery('%s', ?)",
            $language,
            $language,
        );
    }

    /**
     * Get language-specific stemming example
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
                'input' => 'running quickly',
                'tokens' => $result->tokens ?? null,
            ];
        } catch (Exception $e) {
            return [
                'language' => $language,
                'error' => $e->getMessage(),
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

    /**
     * Get list of available FTS languages from database
     */
    public function getAvailableLanguages(): array
    {
        try {
            $configs = DB::select(
                'SELECT cfgname FROM pg_ts_config ORDER BY cfgname',
            );

            return array_map(fn ($config) => $config->cfgname, $configs);
        } catch (Exception $e) {
            $this->logger->error("❌ Failed to fetch available FTS languages: {$e->getMessage()}");

            return self::AVAILABLE_LANGUAGES;
        }
    }
}
