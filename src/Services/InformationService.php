<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;
use Throwable;

/**
 * InformationService
 *
 * Centralized service for all Ichava information and display operations including:
 * - Package listing and statistics
 * - Icon listing and searching
 * - Lifecycle status
 * - FTS language information
 *
 * Extracted from BaseInformationCommand and IchavaStatusCommand for maximum reusability.
 */
class InformationService
{
    public function __construct(
        protected IconRegistry $registry,
        protected IchavaLifecycleManager $lifecycle,
        protected DatabaseOperationsService $databaseService,
        protected IchavaLogger $logger,
    ) {}

    /**
     * Get all registered packages with statistics
     */
    public function getPackages(): array
    {
        $packages = $this->registry->all();
        $result = [];

        foreach ($packages as $name => $data) {
            $iconCount = Icon::where('package', $name)->count();

            $result[$name] = [
                'name' => $name,
                'base_path' => $data['base_path'] ?? $data['path'] ?? '-',
                'icon_count' => $iconCount,
                'status' => 'active',
            ];
        }

        return $result;
    }

    /**
     * Discover packages from filesystem (vendor directory)
     */
    public function discoverPackages(): array
    {
        $vendorPath = base_path('vendor');
        $discovered = [];

        if (File::isDirectory($vendorPath)) {
            $directories = File::glob($vendorPath.'/*/ichava-*');
            foreach ($directories as $dir) {
                $name = basename(dirname($dir)).'/'.basename($dir);
                $discovered[$name] = [
                    'path' => $dir,
                    'registered' => $this->registry->has($name),
                ];
            }
        }

        // Also check platform/ichava directory
        $platformPath = base_path('platform/ichava');
        if (File::isDirectory($platformPath)) {
            $directories = File::directories($platformPath);
            foreach ($directories as $dir) {
                $name = 'ichava/'.basename($dir);
                if (! isset($discovered[$name])) {
                    $discovered[$name] = [
                        'path' => $dir,
                        'registered' => $this->registry->has($name),
                    ];
                }
            }
        }

        return $discovered;
    }

    /**
     * Get icons with optional filters
     */
    public function getIcons(array $filters = []): array
    {
        $query = Icon::query();

        if (! empty($filters['package'])) {
            $query->where('package', $filters['package']);
        }

        if (! empty($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%");
        }

        if (! empty($filters['category'])) {
            $query->whereHas('categories', function ($q) use ($filters) {
                $q->where('slug', $filters['category']);
            });
        }

        $limit = $filters['limit'] ?? 50;

        return $query->limit($limit)->get()->toArray();
    }

    /**
     * Search icons by name
     */
    public function searchIcons(string $search, ?string $package = null, int $limit = 50): array
    {
        $query = Icon::where('name', 'like', "%{$search}%");

        if ($package) {
            $query->where('package', $package);
        }

        return $query->limit($limit)->get()->toArray();
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        $stats = $this->databaseService->getStatistics();

        $stats['database_size'] = $this->getDatabaseSize();
        $stats['cache_driver'] = config('cache.default');

        return $stats;
    }

    /**
     * Get top packages by icon count
     */
    public function getTopPackages(int $limit = 5): array
    {
        return Icon::select('package', DB::raw('count(*) as count'))
            ->groupBy('package')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get lifecycle status
     */
    public function getLifecycleStatus(): array
    {
        $hasMigrations = $this->lifecycle->hasMigrations();
        $hasSeeds = $this->lifecycle->hasSeeds();
        $hasCache = $this->lifecycle->hasCache();
        $isReady = $this->lifecycle->isReady();
        $stage = $this->lifecycle->getStage();

        $status = [
            'stage' => $stage,
            'is_ready' => $isReady,
            'checks' => [
                'migrations' => $hasMigrations,
                'seeds' => $hasSeeds,
                'cache' => $hasCache,
            ],
            'icon_count' => null,
            'next_steps' => [],
        ];

        // Get icon count if migrations exist
        if ($hasMigrations) {
            try {
                $status['icon_count'] = Icon::count();
            } catch (Throwable $e) {
                $status['icon_count'] = 'Error';
            }
        }

        // Determine next steps
        if (! $isReady) {
            if (! $hasMigrations) {
                $status['next_steps'][] = 'Run: php artisan migrate';
            }
            if ($hasMigrations && ! $hasSeeds) {
                $status['next_steps'][] = 'Run: php artisan ichava:database seed';
            }
        }

        return $status;
    }

    /**
     * Reset lifecycle state
     */
    public function resetLifecycle(): void
    {
        $this->lifecycle->reset();
        $this->logger->info('🔄 Lifecycle state reset');
    }

    /**
     * Get available PostgreSQL FTS languages
     */
    public function getFtsLanguages(): array
    {
        try {
            $languages = DB::select("
                SELECT cfgname as language, 
                       cfgowner::regrole as owner,
                       obj_description(oid, 'pg_ts_config') as description
                FROM pg_ts_config
                ORDER BY cfgname
            ");

            return array_map(fn ($lang) => [
                'language' => $lang->language,
                'owner' => $lang->owner ?? '-',
                'description' => $lang->description ?? '-',
            ], $languages);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get current FTS language configuration
     */
    public function getCurrentFtsLanguage(): string
    {
        return config('ichava.core.database.search.language', 'simple');
    }

    /**
     * Truncate long paths for display
     */
    public function truncatePath(string $path, int $maxLength = 50): string
    {
        if (strlen($path) <= $maxLength) {
            return $path;
        }

        $parts = explode('/', $path);
        if (count($parts) <= 2) {
            return substr($path, 0, $maxLength - 3).'...';
        }

        return $parts[0].'/.../'.end($parts);
    }

    /**
     * Format file size
     */
    public function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2).' '.$units[$i];
    }

    /**
     * Filter items by search term
     */
    public function filterBySearch(array $items, string $search, array $searchFields = ['name']): array
    {
        if (empty($search)) {
            return $items;
        }

        return array_filter($items, function ($item) use ($search, $searchFields) {
            foreach ($searchFields as $field) {
                $value = is_array($item) ? ($item[$field] ?? '') : ($item->$field ?? '');
                if (stripos($value, $search) !== false) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Get database size (PostgreSQL)
     */
    protected function getDatabaseSize(): string
    {
        try {
            $size = DB::select("
                SELECT pg_size_pretty(
                    pg_total_relation_size('ichava_icons') +
                    pg_total_relation_size('ichava_icon_terms') +
                    pg_total_relation_size('ichava_icon_termables')
                ) as size
            ")[0]->size ?? '0 bytes';

            return $size;
        } catch (Exception $e) {
            return 'N/A';
        }
    }
}
