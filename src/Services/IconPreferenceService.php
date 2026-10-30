<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Events\FavoriteToggled;
use Simtabi\Laranail\Ichava\Events\CollectionChanged;
use Simtabi\Laranail\Ichava\Contracts\PreferenceStore;
use Simtabi\Laranail\Ichava\Events\PreferencesChanged;

/**
 * IconPreferenceService - Self-Contained Preferences Manager
 *
 * HYBRID ARCHITECTURE - 3-Tier Fallback:
 * 1. Browser localStorage (always works) - handled by frontend
 * 2. Laravel session (if available) - via IchavaSessionManager
 * 3. User account (if authenticated) - future enhancement
 *
 * Philosophy:
 * - Never fail if host's session doesn't work
 * - Gracefully degrade to browser storage
 * - Work standalone, enhance when possible
 *
 * Includes validation functionality (merged from IconPreferenceValidationService)
 */
final class IconPreferenceService
{
    /** Session key for preferences */
    private const string SESSION_KEY = 'preferences';

    /** Top-level keys that hold the user's library rather than preferences. */
    private const array LIBRARY_KEYS = ['favorites', 'collections', 'history', 'command_history'];

    public function __construct(
        private IchavaLogger $logger,
        private PreferenceStore $sessionManager,
    ) {
        // Session manager handles availability detection
    }

    /**
     * Get all preferences from session
     *
     * @return array<mixed>
     */
    public function getAll(): array
    {
        $stored = $this->sessionManager->get($this->getSessionKey());

        return $stored ? (array) $stored : $this->getDefaults();
    }

    /**
     * Get a specific preference value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $preferences = $this->getAll();

        return data_get($preferences, $key, $default);
    }

    /**
     * Set a specific preference value
     */
    public function set(string $key, mixed $value): bool
    {
        $preferences = $this->getAll();
        data_set($preferences, $key, $value);

        // The store's answer is the write's outcome, not a detail. It is
        // `false` whenever no session is available -- a supported host mode,
        // not an error -- and this used to discard it and log success anyway.
        $persisted = $this->sessionManager->put($this->getSessionKey(), $preferences);

        $this->logger->debug($persisted ? '⚙️ Preference updated' : '⚙️ Preference not persisted', [
            'key'        => $key,
            'persisted'  => $persisted,
            'tier'       => $this->sessionManager->getTier(),
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        // Library writes announce themselves with their own, more specific
        // events (FavoriteToggled, CollectionChanged); everything else is a
        // preference.
        if (! in_array(Str::before($key, '.'), self::LIBRARY_KEYS, true)) {
            PreferencesChanged::dispatch([$key], PreferencesChanged::UPDATED, $persisted);
        }

        return $persisted;
    }

    /**
     * Update multiple preferences at once
     *
     * @param array<string, mixed> $data
     *
     * @return array<mixed>
     */
    public function update(array $data): array
    {
        $preferences = array_merge($this->getAll(), $data);
        $persisted = $this->sessionManager->put($this->getSessionKey(), $preferences);

        $this->logger->info($persisted ? '⚙️ Preferences bulk updated' : '⚙️ Preferences not persisted', [
            'keys'      => array_keys($data),
            'persisted' => $persisted,
            'tier'      => $this->sessionManager->getTier(),
            'ip'        => request()->ip(),
        ]);

        PreferencesChanged::dispatch(array_map(strval(...), array_keys($data)), PreferencesChanged::UPDATED, $persisted);

        // `persisted` travels with the payload rather than replacing the return
        // type: callers read the merged preferences back, and a bool return
        // would have been a silent breaking change for every one of them.
        //
        // `persisted` is therefore a RESERVED key. array_merge and not `+`, so
        // the flag wins over a client that sends a preference of that name --
        // `+` would have kept the client's value and silently dropped the
        // outcome, which is the failure this whole change is about.
        return array_merge($preferences, ['persisted' => $persisted]);
    }

    /**
     * Clear all preferences and reset to defaults
     *
     * @return array<string, mixed>
     */
    public function clear(): array
    {
        $defaults = $this->getDefaults();
        $persisted = $this->sessionManager->put($this->getSessionKey(), $defaults);

        PreferencesChanged::dispatch(array_map(strval(...), array_keys($defaults)), PreferencesChanged::CLEARED, $persisted);

        // Log for audit trail
        $this->logger->info('🧹 Preferences cleared', [
            'tier' => $this->sessionManager->getTier(),
            'ip'   => request()->ip(),
        ]);

        return $defaults;
    }

    /**
     * Get search filters
     *
     * @return array<mixed>
     */
    public function getFilters(): array
    {
        return $this->arrayAt('filters', [
            'search'     => '',
            'packages'   => [],
            'categories' => [],
            'variants'   => [],
        ]);
    }

    /**
     * Set search filters
     *
     * @param array<mixed> $filters
     */
    public function setFilters(array $filters): self
    {
        $this->set('filters', $filters);

        return $this;
    }

    /**
     * Get search query
     */
    public function getSearch(): string
    {
        $search = $this->get('filters.search', '');

        return is_string($search) ? $search : '';
    }

    /**
     * Set search query
     */
    public function setSearch(string $query): self
    {
        $this->set('filters.search', $query);

        return $this;
    }

    /**
     * Get selected packages
     *
     * @return array<mixed>
     */
    public function getPackages(): array
    {
        return $this->arrayAt('filters.packages', []);
    }

    /**
     * Set selected packages
     *
     * @param array<mixed> $packages
     */
    public function setPackages(array $packages): self
    {
        $this->set('filters.packages', $packages);

        return $this;
    }

    /**
     * Get selected categories
     *
     * @return array<mixed>
     */
    public function getCategories(): array
    {
        return $this->arrayAt('filters.categories', []);
    }

    /**
     * Set selected categories
     *
     * @param array<mixed> $categories
     */
    public function setCategories(array $categories): self
    {
        $this->set('filters.categories', $categories);

        return $this;
    }

    /**
     * Get sorting preferences
     *
     * @return array<mixed>
     */
    public function getSorting(): array
    {
        return $this->arrayAt('sorting', [
            'sort_by'        => 'name',
            'sort_direction' => 'asc',
        ]);
    }

    /**
     * Set sorting preferences
     */
    public function setSorting(string $sortBy, string $sortDirection): self
    {
        $this->set('sorting', [
            'sort_by'        => $sortBy,
            'sort_direction' => $sortDirection,
        ]);

        return $this;
    }

    /**
     * Get UI preferences
     *
     * @return array<mixed>
     */
    public function getPreferences(): array
    {
        return $this->arrayAt('preferences', [
            'view_mode' => 'grid',
            'icon_size' => 48,
            'per_page'  => 60,
        ]);
    }

    /**
     * Set UI preferences
     *
     * @param array<mixed> $preferences
     */
    public function setPreferences(array $preferences): self
    {
        $this->set('preferences', array_merge($this->getPreferences(), $preferences));

        return $this;
    }

    /**
     * Get tree state
     *
     * @return array<mixed>
     */
    public function getTreeState(): array
    {
        return $this->arrayAt('tree', [
            'expanded_nodes' => [],
            'checked_nodes'  => [],
        ]);
    }

    /**
     * Set tree state
     *
     * @param array<mixed> $expandedNodes
     * @param array<mixed> $checkedNodes
     */
    public function setTreeState(array $expandedNodes, array $checkedNodes): self
    {
        $this->set('tree', [
            'expanded_nodes' => $expandedNodes,
            'checked_nodes'  => $checkedNodes,
        ]);

        return $this;
    }

    /**
     * Get user's favorite icon IDs
     *
     * @return array<mixed>
     */
    public function getFavorites(): array
    {
        return $this->arrayAt('favorites', []);
    }

    /**
     * Add icon to favorites
     */
    public function addFavorite(int $iconId): void
    {
        $favorites = $this->getFavorites();
        if (! in_array($iconId, $favorites)) {
            $favorites[] = $iconId;
            $persisted = $this->set('favorites', $favorites);
            $this->logger->debug('⭐ Icon added to favorites', ['icon_id' => $iconId]);
            FavoriteToggled::dispatch($iconId, true, $persisted);
        }
    }

    /**
     * Remove icon from favorites
     */
    public function removeFavorite(int $iconId): void
    {
        $favorites = $this->getFavorites();
        $favorites = array_values(Arr::where($favorites, fn ($id) => $id !== $iconId));
        $persisted = $this->set('favorites', $favorites);
        $this->logger->debug('⭐ Icon removed from favorites', ['icon_id' => $iconId]);
        FavoriteToggled::dispatch($iconId, false, $persisted);
    }

    /**
     * Toggle icon favorite status
     * Returns true if now favorited, false if unfavorited
     */
    public function toggleFavorite(int $iconId): bool
    {
        $favorites = $this->getFavorites();
        if (in_array($iconId, $favorites)) {
            $this->removeFavorite($iconId);

            return false;
        } else {
            $this->addFavorite($iconId);

            return true;
        }
    }

    /**
     * Check if icon is favorited
     */
    public function isFavorite(int $iconId): bool
    {
        return in_array($iconId, $this->getFavorites());
    }

    /**
     * Get user's collections
     *
     * @return array<mixed>
     */
    public function getCollections(): array
    {
        return $this->arrayAt('collections', []);
    }

    /**
     * Create a new collection
     *
     * @return array{id: string, name: string, color: string, icon_ids: list<int>, created_at: string}
     */
    public function createCollection(string $name, ?string $color = null): array
    {
        $collections = $this->getCollections();
        $collection = [
            'id'         => 'collection-' . time() . '-' . bin2hex(random_bytes(4)),
            'name'       => $name,
            'color'      => $color ?? $this->getRandomCollectionColor(),
            'icon_ids'   => [],
            'created_at' => now()->toIso8601String(),
        ];
        $collections[] = $collection;
        $persisted = $this->set('collections', $collections);
        $this->logger->debug('📁 Collection created', ['collection_id' => $collection['id'], 'name' => $name]);
        CollectionChanged::dispatch(CollectionChanged::CREATED, $collection['id'], null, $persisted);

        return $collection;
    }

    /**
     * Update a collection
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>|null
     */
    public function updateCollection(string $collectionId, array $data): ?array
    {
        $collections = $this->getCollections();
        foreach ($collections as $index => $collection) {
            if (is_array($collection) && ($collection['id'] ?? null) === $collectionId) {
                $updated = array_merge($collection, $data);
                $collections[$index] = $updated;
                $persisted = $this->set('collections', $collections);
                $this->logger->debug('📁 Collection updated', ['collection_id' => $collectionId]);
                CollectionChanged::dispatch(CollectionChanged::UPDATED, $collectionId, null, $persisted);

                return $updated;
            }
        }

        return null;
    }

    /**
     * Delete a collection
     */
    public function deleteCollection(string $collectionId): void
    {
        $collections = $this->getCollections();
        $collections = array_values(Arr::where(
            $collections,
            fn (mixed $c): bool => ! is_array($c) || ($c['id'] ?? null) !== $collectionId,
        ));
        $persisted = $this->set('collections', $collections);
        $this->logger->debug('🗑️ Collection deleted', ['collection_id' => $collectionId]);
        CollectionChanged::dispatch(CollectionChanged::DELETED, $collectionId, null, $persisted);
    }

    /**
     * Get a single collection by ID
     *
     * @return array<mixed>|null
     */
    public function getCollection(string $collectionId): ?array
    {
        $collections = $this->getCollections();
        foreach ($collections as $collection) {
            if (is_array($collection) && ($collection['id'] ?? null) === $collectionId) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * Add icon to collection
     */
    public function addIconToCollection(string $collectionId, int $iconId): void
    {
        $collections = $this->getCollections();
        foreach ($collections as $index => $collection) {
            if (is_array($collection) && ($collection['id'] ?? null) === $collectionId) {
                $iconIds = is_array($collection['icon_ids'] ?? null) ? $collection['icon_ids'] : [];

                if (! in_array($iconId, $iconIds)) {
                    $iconIds[] = $iconId;
                    $collection['icon_ids'] = $iconIds;
                    $collections[$index] = $collection;
                    $persisted = $this->set('collections', $collections);
                    $this->logger->debug('📁 Icon added to collection', [
                        'collection_id' => $collectionId,
                        'icon_id'       => $iconId,
                    ]);
                    CollectionChanged::dispatch(CollectionChanged::ICON_ADDED, $collectionId, $iconId, $persisted);
                }
                break;
            }
        }
    }

    /**
     * Remove icon from collection
     */
    public function removeIconFromCollection(string $collectionId, int $iconId): void
    {
        $collections = $this->getCollections();
        foreach ($collections as $index => $collection) {
            if (is_array($collection) && ($collection['id'] ?? null) === $collectionId) {
                $iconIds = is_array($collection['icon_ids'] ?? null) ? $collection['icon_ids'] : [];
                $collection['icon_ids'] = array_values(
                    Arr::where($iconIds, fn (mixed $id): bool => $id !== $iconId),
                );
                $collections[$index] = $collection;
                $persisted = $this->set('collections', $collections);
                $this->logger->debug('📁 Icon removed from collection', [
                    'collection_id' => $collectionId,
                    'icon_id'       => $iconId,
                ]);
                CollectionChanged::dispatch(CollectionChanged::ICON_REMOVED, $collectionId, $iconId, $persisted);
                break;
            }
        }
    }

    /**
     * Get user's icon activity history
     *
     * @return array<mixed>
     */
    public function getHistory(): array
    {
        return $this->arrayAt('history', []);
    }

    /**
     * Add entry to history
     */
    public function addHistoryEntry(int $iconId, string $action): void
    {
        $history = $this->getHistory();

        // Get icon name for display
        $icon = Icon::find($iconId);
        $iconName = $icon ? $icon->name : "Icon #{$iconId}";

        $entry = [
            'icon_id'   => $iconId,
            'icon_name' => $iconName,
            'action'    => $action, // 'view', 'copy', 'download'
            'timestamp' => now()->toIso8601String(),
        ];

        // Add to beginning
        array_unshift($history, $entry);

        // Keep max 100 entries
        $history = array_slice($history, 0, 100);

        $this->set('history', $history);
    }

    /**
     * Clear history
     */
    public function clearHistory(): void
    {
        $this->set('history', []);
        $this->logger->debug('🧹 History cleared');
    }

    /**
     * Get command history
     *
     * @return array<mixed>
     */
    public function getCommandHistory(): array
    {
        return $this->arrayAt('command_history', []);
    }

    /**
     * Add entry to command history
     *
     * @param string $command The command executed
     * @param string $type Command type: 'action', 'search', 'navigation'
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function addCommandHistory(string $command, string $type, array $metadata = []): void
    {
        $history = $this->getCommandHistory();

        $entry = [
            'command'   => $command,
            'type'      => $type,
            'metadata'  => $metadata,
            'timestamp' => now()->toIso8601String(),
        ];

        // Add to beginning
        array_unshift($history, $entry);

        // Keep max 50 entries
        $history = array_slice($history, 0, 50);

        $this->set('command_history', $history);
    }

    /**
     * Clear command history
     */
    public function clearCommandHistory(): void
    {
        $this->set('command_history', []);
        $this->logger->debug('🧹 Command history cleared');
    }

    /**
     * Validate all preferences against available data
     * Returns cleaned preferences array
     *
     * @param array<string, mixed> $preferences
     *
     * @return array<string, mixed>
     */
    public function validate(array $preferences): array
    {
        // If database is empty, return minimal valid preferences
        if (! Icon::query()->exists()) {
            return $this->getDefaults();
        }

        $cleaned = $preferences;
        $changes = [];

        // Get available data once
        $availablePackages = $this->getAvailablePackages();
        $availableCategories = $this->getAvailableCategories();
        $availableVariants = $this->getAvailableVariants();

        // 1. Validate selected packages
        $selectedPackages = $this->listIn($preferences, 'filters', 'packages');
        $validPackages = array_values(array_intersect(self::scalars($selectedPackages), $availablePackages));
        if (count($validPackages) !== count($selectedPackages)) {
            $removed = count($selectedPackages) - count($validPackages);
            $changes[] = "Removed {$removed} invalid package filter(s)";
            $cleaned['filters'] = self::withKey($cleaned['filters'] ?? null, 'packages', $validPackages);
        }

        // 2. Validate selected categories
        $selectedCategories = $this->listIn($preferences, 'filters', 'categories');
        $validCategories = array_values(array_intersect(self::scalars($selectedCategories), $availableCategories));
        if (count($validCategories) !== count($selectedCategories)) {
            $removed = count($selectedCategories) - count($validCategories);
            $changes[] = "Removed {$removed} invalid category filter(s)";
            $cleaned['filters'] = self::withKey($cleaned['filters'] ?? null, 'categories', $validCategories);
        }

        // 3. Validate selected variants
        $selectedVariants = $this->listIn($preferences, 'filters', 'variants');
        $validVariants = array_values(array_intersect(self::scalars($selectedVariants), $availableVariants));
        if (count($validVariants) !== count($selectedVariants)) {
            $removed = count($selectedVariants) - count($validVariants);
            $changes[] = "Removed {$removed} invalid variant filter(s)";
            $cleaned['filters'] = self::withKey($cleaned['filters'] ?? null, 'variants', $validVariants);
        }

        // 4. Validate tree checked nodes (category paths)
        $checkedNodes = $this->listIn($preferences, 'tree', 'checked_nodes');
        $validCheckedNodes = array_values(array_intersect(self::scalars($checkedNodes), $availableCategories));
        if (count($validCheckedNodes) !== count($checkedNodes)) {
            $removed = count($checkedNodes) - count($validCheckedNodes);
            $changes[] = "Removed {$removed} invalid checked category node(s)";
            $cleaned['tree'] = self::withKey($cleaned['tree'] ?? null, 'checked_nodes', $validCheckedNodes);
        }

        // 5. Validate tree expanded nodes
        $expandedNodes = $this->listIn($preferences, 'tree', 'expanded_nodes');
        $validExpandedNodes = array_filter($expandedNodes, function ($node) {
            return is_string($node) && strlen($node) > 0;
        });
        if (count($validExpandedNodes) !== count($expandedNodes)) {
            $removed = count($expandedNodes) - count($validExpandedNodes);
            $changes[] = "Removed {$removed} invalid expanded node(s)";
            $cleaned['tree'] = self::withKey($cleaned['tree'] ?? null, 'expanded_nodes', array_values($validExpandedNodes));
        }

        // 6. Validate view mode
        $viewMode = self::valueIn($preferences, 'preferences', 'view_mode') ?? 'grid';
        $validViewModes = ['grid', 'list'];
        if (! in_array($viewMode, $validViewModes)) {
            $changes[] = 'Reset invalid view mode: ' . self::describe($viewMode) . ' → grid';
            $cleaned['preferences'] = self::withKey($cleaned['preferences'] ?? null, 'view_mode', 'grid');
        }

        // 7. Validate icon size
        $iconSize = self::valueIn($preferences, 'preferences', 'icon_size') ?? 48;
        if (! is_numeric($iconSize) || $iconSize < 24 || $iconSize > 128) {
            $changes[] = 'Reset invalid icon size: ' . self::describe($iconSize) . ' → 48';
            $cleaned['preferences'] = self::withKey($cleaned['preferences'] ?? null, 'icon_size', 48);
        }

        // 8. Validate per page
        $perPage = self::valueIn($preferences, 'pagination', 'per_page') ?? 60;
        $validPerPage = [30, 60, 90, 120];
        if (! in_array($perPage, $validPerPage)) {
            $changes[] = 'Reset invalid per page: ' . self::describe($perPage) . ' → 60';
            $cleaned['pagination'] = self::withKey($cleaned['pagination'] ?? null, 'per_page', 60);
            $cleaned['preferences'] = self::withKey($cleaned['preferences'] ?? null, 'per_page', 60); // Keep in sync
        }

        // 9. Validate sort_by
        $sortBy = self::valueIn($preferences, 'sorting', 'sort_by') ?? 'name';
        $validSortFields = ['name', 'package', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $validSortFields)) {
            $changes[] = 'Reset invalid sort field: ' . self::describe($sortBy) . ' → name';
            $cleaned['sorting'] = self::withKey($cleaned['sorting'] ?? null, 'sort_by', 'name');
        }

        // 10. Validate sort_direction
        $sortDirection = self::valueIn($preferences, 'sorting', 'sort_direction') ?? 'asc';
        $validDirections = ['asc', 'desc'];
        if (! in_array($sortDirection, $validDirections)) {
            $changes[] = 'Reset invalid sort direction: ' . self::describe($sortDirection) . ' → asc';
            $cleaned['sorting'] = self::withKey($cleaned['sorting'] ?? null, 'sort_direction', 'asc');
        }

        // Log changes if any
        if (! empty($changes)) {
            $this->logger->info('Icon browser preferences validated', [
                'changes' => $changes,
                'tier'    => $this->sessionManager->getTier(),
            ]);
        }

        return $cleaned;
    }

    /**
     * `$preferences[$group][$key]`, or null when either level is missing.
     *
     * @param array<string, mixed> $preferences
     */
    private static function valueIn(array $preferences, string $group, string $key): mixed
    {
        $section = $preferences[$group] ?? null;

        return is_array($section) ? ($section[$key] ?? null) : null;
    }

    /**
     * $section with $key set to $value; a section that is not an array
     * becomes one holding only $key.
     *
     * @return array<mixed>
     */
    private static function withKey(mixed $section, string $key, mixed $value): array
    {
        $section = is_array($section) ? $section : [];
        $section[$key] = $value;

        return $section;
    }

    /**
     * The scalar members of $values. A non-scalar can never match an
     * available name, so it is dropped (and counted as removed) either way.
     *
     * @param array<mixed> $values
     *
     * @return array<bool|float|int|string>
     */
    private static function scalars(array $values): array
    {
        return array_filter($values, is_scalar(...));
    }

    /**
     * The non-empty strings among $values, the same set Collection::filter()
     * kept, as a list.
     *
     * @param iterable<mixed> $values
     *
     * @return list<string>
     */
    private static function nonEmptyStrings(iterable $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '' && $value !== '0') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * A value as it reads in a log line.
     */
    private static function describe(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string) $value : get_debug_type($value);
    }

    /**
     * Get the session key for preferences
     */
    private function getSessionKey(): string
    {
        return self::SESSION_KEY;
    }

    /**
     * Get default preferences structure
     *
     * @return array<string, mixed>
     */
    private function getDefaults(): array
    {
        return [
            'filters' => [
                'search'     => '',
                'packages'   => [],
                'categories' => [],
                'variants'   => [],
            ],
            'sorting' => [
                'sort_by'        => 'name',
                'sort_direction' => 'asc',
            ],
            'preferences' => [
                'view_mode' => 'grid',
                'icon_size' => 48,
                'per_page'  => 60,
            ],
            'tree' => [
                'expanded_nodes' => [],
                'checked_nodes'  => [],
            ],
            'pagination' => [
                'current_page' => 1,
                'per_page'     => 60,
            ],
            'favorites'       => [],
            'collections'     => [],
            'history'         => [],
            'command_history' => [],
        ];
    }

    /**
     * Get random color for new collection
     */
    private function getRandomCollectionColor(): string
    {
        $colors = ['#8b5cf6', '#ec4899', '#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#06b6d4', '#84cc16'];

        return $colors[array_rand($colors)];
    }

    /**
     * Get all available package names from database
     *
     * @return list<string>
     */
    private function getAvailablePackages(): array
    {
        return self::nonEmptyStrings(Icon::query()
            ->distinct()
            ->pluck('package'));
    }

    /**
     * Get all available category names from database
     *
     * @return list<string>
     */
    private function getAvailableCategories(): array
    {
        // Get categories from terms table if using new structure
        if (Schema::hasTable('ichava_icon_terms')) {
            return self::nonEmptyStrings(DB::table('ichava_icon_terms')
                ->where('type', 'category')
                ->pluck('slug'));
        }

        // Fallback to old structure
        return self::nonEmptyStrings(Icon::query()
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category'));
    }

    /**
     * Get all available variant names from database
     *
     * @return list<string>
     */
    private function getAvailableVariants(): array
    {
        // Get variants from terms table if using new structure
        if (Schema::hasTable('ichava_icon_terms')) {
            return self::nonEmptyStrings(DB::table('ichava_icon_terms')
                ->where('type', 'variant')
                ->pluck('slug'));
        }

        // Fallback to old structure
        return self::nonEmptyStrings(Icon::query()
            ->whereNotNull('variant')
            ->distinct()
            ->pluck('variant'));
    }

    /**
     * The preference at $key when it is an array, $default otherwise.
     *
     * @param array<mixed> $default
     *
     * @return array<mixed>
     */
    private function arrayAt(string $key, array $default): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * `$preferences[$group][$key]` when it is an array, an empty one otherwise.
     *
     * @param array<string, mixed> $preferences
     *
     * @return array<mixed>
     */
    private function listIn(array $preferences, string $group, string $key): array
    {
        $value = self::valueIn($preferences, $group, $key);

        return is_array($value) ? $value : [];
    }
}
