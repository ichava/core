<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Actions;

use Simtabi\Laranail\Ichava\Services\IconPreferenceService;

/**
 * Apply one browser-preferences update: the single entry point icon-browser's
 * REST `preferences.*` endpoints and its Inertia settings route both call, so
 * the two modes behave identically.
 *
 * Each recognised section goes through its own setter, which keeps the
 * stored shape the service defines:
 *
 * - `packages`    list of package names       → setPackages()
 * - `categories`  list of category slugs      → setCategories()
 * - `search`      string                      → setSearch()
 * - `sorting`     {sort_by, sort_direction}   → setSorting()
 * - `preferences` {view_mode, icon_size, …}   → setPreferences() (merged)
 * - `tree`        {expanded_nodes, checked_nodes} → setTreeState()
 *
 * Unknown sections are ignored rather than stored, so a client cannot write
 * arbitrary keys into the store. Returns the stored preferences after the
 * update, cleaned by IconPreferenceService::validate().
 */
final readonly class UpdatePreferences
{
    private const array SORT_DIRECTIONS = ['asc', 'desc'];

    public function __construct(
        private IconPreferenceService $preferences,
    ) {}

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<mixed>
     */
    public function handle(array $changes): array
    {
        if (is_array($changes['packages'] ?? null)) {
            $this->preferences->setPackages(self::strings($changes['packages']));
        }

        if (is_array($changes['categories'] ?? null)) {
            $this->preferences->setCategories(self::strings($changes['categories']));
        }

        if (is_string($changes['search'] ?? null)) {
            $this->preferences->setSearch($changes['search']);
        }

        $sorting = $changes['sorting'] ?? null;

        if (is_array($sorting) && is_string($sorting['sort_by'] ?? null)) {
            $direction = is_string($sorting['sort_direction'] ?? null) ? strtolower($sorting['sort_direction']) : 'asc';

            $this->preferences->setSorting(
                $sorting['sort_by'],
                in_array($direction, self::SORT_DIRECTIONS, true) ? $direction : 'asc',
            );
        }

        if (is_array($changes['preferences'] ?? null)) {
            $this->preferences->setPreferences($changes['preferences']);
        }

        $tree = $changes['tree'] ?? null;

        if (is_array($tree)) {
            // A section left out keeps its stored value.
            $current = $this->preferences->getTreeState();

            $this->preferences->setTreeState(
                self::listOr($tree['expanded_nodes'] ?? null, $current['expanded_nodes'] ?? null),
                self::listOr($tree['checked_nodes'] ?? null, $current['checked_nodes'] ?? null),
            );
        }

        $stored = [];

        foreach ($this->preferences->getAll() as $key => $value) {
            $stored[(string) $key] = $value;
        }

        return $this->preferences->validate($stored);
    }

    /**
     * @return array<mixed>
     */
    private static function listOr(mixed $value, mixed $fallback): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_array($fallback) ? $fallback : [];
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }
}
