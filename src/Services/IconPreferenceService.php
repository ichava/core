<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Session;
use Schema;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Support\IchavaSessionManager;

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

    public function __construct(
        private IchavaLogger $logger,
        private IchavaSessionManager $sessionManager,
    ) {
        // Session manager handles availability detection
    }

    /**
     * Get all preferences from session
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
    public function set(string $key, mixed $value): void
    {
        $preferences = $this->getAll();
        data_set($preferences, $key, $value);
        $this->sessionManager->put($this->getSessionKey(), $preferences);

        // Log for audit trail
        $this->logger->debug('⚙️ Preference updated', [
            'key' => $key,
            'tier' => $this->sessionManager->getTier(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Update multiple preferences at once
     */
    public function update(array $data): array
    {
        $preferences = array_merge($this->getAll(), $data);
        $this->sessionManager->put($this->getSessionKey(), $preferences);

        // Log for audit trail
        $this->logger->info('⚙️ Preferences bulk updated', [
            'keys' => array_keys($data),
            'tier' => $this->sessionManager->getTier(),
            'ip' => request()->ip(),
        ]);

        return $preferences;
    }

    /**
     * Clear all preferences and reset to defaults
     */
    public function clear(): array
    {
        $defaults = $this->getDefaults();
        $this->sessionManager->put($this->getSessionKey(), $defaults);

        // Log for audit trail
        $this->logger->info('🧹 Preferences cleared', [
            'tier' => $this->sessionManager->getTier(),
            'ip' => request()->ip(),
        ]);

        return $defaults;
    }

    /**
     * Get search filters
     */
    public function getFilters(): array
    {
        return $this->get('filters', [
            'search' => '',
            'packages' => [],
            'categories' => [],
            'variants' => [],
        ]);
    }

    /**
     * Set search filters
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
        return $this->get('filters.search', '');
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
     */
    public function getPackages(): array
    {
        return $this->get('filters.packages', []);
    }

    /**
     * Set selected packages
     */
    public function setPackages(array $packages): self
    {
        $this->set('filters.packages', $packages);

        return $this;
    }

    /**
     * Get selected categories
     */
    public function getCategories(): array
    {
        return $this->get('filters.categories', []);
    }

    /**
     * Set selected categories
     */
    public function setCategories(array $categories): self
    {
        $this->set('filters.categories', $categories);

        return $this;
    }

    /**
     * Get sorting preferences
     */
    public function getSorting(): array
    {
        return $this->get('sorting', [
            'sort_by' => 'name',
            'sort_direction' => 'asc',
        ]);
    }

    /**
     * Set sorting preferences
     */
    public function setSorting(string $sortBy, string $sortDirection): self
    {
        $this->set('sorting', [
            'sort_by' => $sortBy,
            'sort_direction' => $sortDirection,
        ]);

        return $this;
    }

    /**
     * Get UI preferences
     */
    public function getPreferences(): array
    {
        return $this->get('preferences', [
            'view_mode' => 'grid',
            'icon_size' => 48,
            'per_page' => 60,
        ]);
    }

    /**
     * Set UI preferences
     */
    public function setPreferences(array $preferences): self
    {
        $this->set('preferences', array_merge($this->getPreferences(), $preferences));

        return $this;
    }

    /**
     * Get tree state
     */
    public function getTreeState(): array
    {
        return $this->get('tree', [
            'expanded_nodes' => [],
            'checked_nodes' => [],
        ]);
    }

    /**
     * Set tree state
     */
    public function setTreeState(array $expandedNodes, array $checkedNodes): self
    {
        $this->set('tree', [
            'expanded_nodes' => $expandedNodes,
            'checked_nodes' => $checkedNodes,
        ]);

        return $this;
    }

    /**
     * Get user's favorite icon IDs
     */
    public function getFavorites(): array
    {
        return $this->get('favorites', []);
    }

    /**
     * Add icon to favorites
     */
    public function addFavorite(int $iconId): void
    {
        $favorites = $this->getFavorites();
        if (! in_array($iconId, $favorites)) {
            $favorites[] = $iconId;
            $this->set('favorites', $favorites);
            $this->logger->debug('⭐ Icon added to favorites', ['icon_id' => $iconId]);
        }
    }

    /**
     * Remove icon from favorites
     */
    public function removeFavorite(int $iconId): void
    {
        $favorites = $this->getFavorites();
        $favorites = array_values(Arr::where($favorites, fn ($id) => $id !== $iconId));
        $this->set('favorites', $favorites);
        $this->logger->debug('⭐ Icon removed from favorites', ['icon_id' => $iconId]);
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
     */
    public function getCollections(): array
    {
        return $this->get('collections', []);
    }

    /**
     * Create a new collection
     */
    public function createCollection(string $name, ?string $color = null): array
    {
        $collections = $this->getCollections();
        $collection = [
            'id' => 'collection-'.time().'-'.bin2hex(random_bytes(4)),
            'name' => $name,
            'color' => $color ?? $this->getRandomCollectionColor(),
            'icon_ids' => [],
            'created_at' => now()->toIso8601String(),
        ];
        $collections[] = $collection;
        $this->set('collections', $collections);
        $this->logger->debug('📁 Collection created', ['collection_id' => $collection['id'], 'name' => $name]);

        return $collection;
    }

    /**
     * Update a collection
     */
    public function updateCollection(string $collectionId, array $data): ?array
    {
        $collections = $this->getCollections();
        foreach ($collections as $index => $collection) {
            if ($collection['id'] === $collectionId) {
                $collections[$index] = array_merge($collection, $data);
                $this->set('collections', $collections);
                $this->logger->debug('📁 Collection updated', ['collection_id' => $collectionId]);

                return $collections[$index];
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
        $collections = array_values(Arr::where($collections, fn ($c) => $c['id'] !== $collectionId));
        $this->set('collections', $collections);
        $this->logger->debug('🗑️ Collection deleted', ['collection_id' => $collectionId]);
    }

    /**
     * Get a single collection by ID
     */
    public function getCollection(string $collectionId): ?array
    {
        $collections = $this->getCollections();
        foreach ($collections as $collection) {
            if ($collection['id'] === $collectionId) {
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
            if ($collection['id'] === $collectionId) {
                if (! in_array($iconId, $collection['icon_ids'])) {
                    $collections[$index]['icon_ids'][] = $iconId;
                    $this->set('collections', $collections);
                    $this->logger->debug('📁 Icon added to collection', [
                        'collection_id' => $collectionId,
                        'icon_id' => $iconId,
                    ]);
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
            if ($collection['id'] === $collectionId) {
                $collections[$index]['icon_ids'] = array_values(
                    Arr::where($collection['icon_ids'], fn ($id) => $id !== $iconId),
                );
                $this->set('collections', $collections);
                $this->logger->debug('📁 Icon removed from collection', [
                    'collection_id' => $collectionId,
                    'icon_id' => $iconId,
                ]);
                break;
            }
        }
    }

    /**
     * Get user's icon activity history
     */
    public function getHistory(): array
    {
        return $this->get('history', []);
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
            'icon_id' => $iconId,
            'icon_name' => $iconName,
            'action' => $action, // 'view', 'copy', 'download'
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
     */
    public function getCommandHistory(): array
    {
        return $this->get('command_history', []);
    }

    /**
     * Add entry to command history
     *
     * @param  string  $command  The command executed
     * @param  string  $type  Command type: 'action', 'search', 'navigation'
     * @param  array  $metadata  Additional metadata
     */
    public function addCommandHistory(string $command, string $type, array $metadata = []): void
    {
        $history = $this->getCommandHistory();

        $entry = [
            'command' => $command,
            'type' => $type,
            'metadata' => $metadata,
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
        $selectedPackages = $preferences['filters']['packages'] ?? [];
        $validPackages = array_values(array_intersect($selectedPackages, $availablePackages));
        if (count($validPackages) !== count($selectedPackages)) {
            $removed = count($selectedPackages) - count($validPackages);
            $changes[] = "Removed {$removed} invalid package filter(s)";
            $cleaned['filters']['packages'] = $validPackages;
        }

        // 2. Validate selected categories
        $selectedCategories = $preferences['filters']['categories'] ?? [];
        $validCategories = array_values(array_intersect($selectedCategories, $availableCategories));
        if (count($validCategories) !== count($selectedCategories)) {
            $removed = count($selectedCategories) - count($validCategories);
            $changes[] = "Removed {$removed} invalid category filter(s)";
            $cleaned['filters']['categories'] = $validCategories;
        }

        // 3. Validate selected variants
        $selectedVariants = $preferences['filters']['variants'] ?? [];
        $validVariants = array_values(array_intersect($selectedVariants, $availableVariants));
        if (count($validVariants) !== count($selectedVariants)) {
            $removed = count($selectedVariants) - count($validVariants);
            $changes[] = "Removed {$removed} invalid variant filter(s)";
            $cleaned['filters']['variants'] = $validVariants;
        }

        // 4. Validate tree checked nodes (category paths)
        $checkedNodes = $preferences['tree']['checked_nodes'] ?? [];
        $validCheckedNodes = array_values(array_intersect($checkedNodes, $availableCategories));
        if (count($validCheckedNodes) !== count($checkedNodes)) {
            $removed = count($checkedNodes) - count($validCheckedNodes);
            $changes[] = "Removed {$removed} invalid checked category node(s)";
            $cleaned['tree']['checked_nodes'] = $validCheckedNodes;
        }

        // 5. Validate tree expanded nodes
        $expandedNodes = $preferences['tree']['expanded_nodes'] ?? [];
        $validExpandedNodes = array_filter($expandedNodes, function ($node) {
            return is_string($node) && strlen($node) > 0;
        });
        if (count($validExpandedNodes) !== count($expandedNodes)) {
            $removed = count($expandedNodes) - count($validExpandedNodes);
            $changes[] = "Removed {$removed} invalid expanded node(s)";
            $cleaned['tree']['expanded_nodes'] = array_values($validExpandedNodes);
        }

        // 6. Validate view mode
        $viewMode = $preferences['preferences']['view_mode'] ?? 'grid';
        $validViewModes = ['grid', 'list'];
        if (! in_array($viewMode, $validViewModes)) {
            $changes[] = "Reset invalid view mode: {$viewMode} → grid";
            $cleaned['preferences']['view_mode'] = 'grid';
        }

        // 7. Validate icon size
        $iconSize = $preferences['preferences']['icon_size'] ?? 48;
        if (! is_numeric($iconSize) || $iconSize < 24 || $iconSize > 128) {
            $changes[] = "Reset invalid icon size: {$iconSize} → 48";
            $cleaned['preferences']['icon_size'] = 48;
        }

        // 8. Validate per page
        $perPage = $preferences['pagination']['per_page'] ?? 60;
        $validPerPage = [30, 60, 90, 120];
        if (! in_array($perPage, $validPerPage)) {
            $changes[] = "Reset invalid per page: {$perPage} → 60";
            $cleaned['pagination']['per_page'] = 60;
            $cleaned['preferences']['per_page'] = 60; // Keep in sync
        }

        // 9. Validate sort_by
        $sortBy = $preferences['sorting']['sort_by'] ?? 'name';
        $validSortFields = ['name', 'package', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $validSortFields)) {
            $changes[] = "Reset invalid sort field: {$sortBy} → name";
            $cleaned['sorting']['sort_by'] = 'name';
        }

        // 10. Validate sort_direction
        $sortDirection = $preferences['sorting']['sort_direction'] ?? 'asc';
        $validDirections = ['asc', 'desc'];
        if (! in_array($sortDirection, $validDirections)) {
            $changes[] = "Reset invalid sort direction: {$sortDirection} → asc";
            $cleaned['sorting']['sort_direction'] = 'asc';
        }

        // Log changes if any
        if (! empty($changes)) {
            $this->logger->info('Icon browser preferences validated', [
                'changes' => $changes,
                'tier' => $this->sessionManager->getTier(),
            ]);
        }

        return $cleaned;
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
     */
    private function getDefaults(): array
    {
        return [
            'filters' => [
                'search' => '',
                'packages' => [],
                'categories' => [],
                'variants' => [],
            ],
            'sorting' => [
                'sort_by' => 'name',
                'sort_direction' => 'asc',
            ],
            'preferences' => [
                'view_mode' => 'grid',
                'icon_size' => 48,
                'per_page' => 60,
            ],
            'tree' => [
                'expanded_nodes' => [],
                'checked_nodes' => [],
            ],
            'pagination' => [
                'current_page' => 1,
                'per_page' => 60,
            ],
            'favorites' => [],
            'collections' => [],
            'history' => [],
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
     */
    private function getAvailablePackages(): array
    {
        return Icon::query()
            ->distinct()
            ->pluck('package')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Get all available category names from database
     */
    private function getAvailableCategories(): array
    {
        // Get categories from terms table if using new structure
        if (Schema::hasTable('ichava_icon_terms')) {
            return DB::table('ichava_icon_terms')
                ->where('type', 'category')
                ->pluck('slug')
                ->filter()
                ->values()
                ->toArray();
        }

        // Fallback to old structure
        return Icon::query()
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Get all available variant names from database
     */
    private function getAvailableVariants(): array
    {
        // Get variants from terms table if using new structure
        if (Schema::hasTable('ichava_icon_terms')) {
            return DB::table('ichava_icon_terms')
                ->where('type', 'variant')
                ->pluck('slug')
                ->filter()
                ->values()
                ->toArray();
        }

        // Fallback to old structure
        return Icon::query()
            ->whereNotNull('variant')
            ->distinct()
            ->pluck('variant')
            ->filter()
            ->values()
            ->toArray();
    }
}
