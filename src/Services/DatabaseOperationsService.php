<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Throwable;

/**
 * DatabaseOperationsService
 *
 * Centralized service for all Ichava database operations including:
 * - Migration management (run, fresh)
 * - Seeding with smart queue logic
 * - Unseeding (package removal, orphan cleanup)
 * - Statistics and validation
 *
 * Extracted from BaseDatabaseCommand for maximum reusability.
 */
class DatabaseOperationsService
{
    /**
     * Smart queue threshold - packages with fewer icons are seeded synchronously
     */
    public const SMART_QUEUE_THRESHOLD = 5000;

    /**
     * Ichava table names in dependency order
     */
    protected const TABLES = [
        'ichava_icon_termables',
        'ichava_icon_terms',
        'ichava_icons',
    ];

    public function __construct(
        protected IchavaLogger $logger,
        protected IconRegistry $registry,
    ) {}

    /**
     * Check if all required Ichava tables exist
     */
    public function tablesExist(): bool
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get list of missing tables
     */
    public function getMissingTables(): array
    {
        $missing = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * Ensure all required tables exist with proper columns
     */
    public function ensureTablesExist(): bool
    {
        if (! Schema::hasTable('ichava_icons')) {
            return false;
        }

        if (! Schema::hasTable('ichava_icon_terms')) {
            return false;
        }

        return true;
    }

    /**
     * Drop all Ichava tables (preserves other application tables)
     */
    public function dropTables(): array
    {
        $dropped = [];

        // Disable foreign key checks temporarily
        if (Helpers::dbDriverIsPgSql()) {
            DB::statement('SET session_replication_role = replica');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            // Drop FTS triggers and functions first (PostgreSQL)
            if (Helpers::dbDriverIsPgSql()) {
                $this->dropPostgresqlFtsObjects();
            }

            // Drop tables in reverse dependency order
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    Schema::dropIfExists($table);
                    $dropped[] = $table;
                    $this->logger->info("Dropped table: {$table}");
                }
            }
        } finally {
            // Re-enable foreign key checks
            if (Helpers::dbDriverIsPgSql()) {
                DB::statement('SET session_replication_role = DEFAULT');
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return $dropped;
    }

    /**
     * Run Ichava migrations
     */
    public function runMigrations(): int
    {
        $this->logger->info('🗄️ Running Ichava migrations');

        return Artisan::call('migrate', [
            '--path' => 'platform/ichava/ichava/database/migrations',
            '--force' => true,
        ]);
    }

    /**
     * Fresh migration: drop tables and re-run migrations
     */
    public function freshMigration(): array
    {
        $this->logger->info('🗄️ Starting fresh Ichava migration');

        $dropped = $this->dropTables();

        $exitCode = $this->runMigrations();

        return [
            'dropped_tables' => $dropped,
            'migration_exit_code' => $exitCode,
            'success' => $exitCode === 0,
        ];
    }

    /**
     * Truncate all Ichava tables (keeps structure, removes data)
     */
    public function truncateTables(): array
    {
        $truncated = [];

        // Disable foreign key checks temporarily
        if (Helpers::dbDriverIsPgSql()) {
            DB::statement('SET session_replication_role = replica');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                    $truncated[] = $table;
                    $this->logger->info("Truncated table: {$table}");
                }
            }
        } finally {
            // Re-enable foreign key checks
            if (Helpers::dbDriverIsPgSql()) {
                DB::statement('SET session_replication_role = DEFAULT');
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        return $truncated;
    }

    /**
     * Remove all data for a specific package
     */
    public function unseedPackage(string $packageName): array
    {
        $this->logger->info("Unseeding package: {$packageName}");

        $stats = [
            'package' => $packageName,
            'icons_deleted' => 0,
            'term_relations_deleted' => 0,
            'orphaned_terms_deleted' => 0,
        ];

        DB::beginTransaction();

        try {
            // Get icon IDs for this package
            $iconIds = Icon::where('package', $packageName)->pluck('id')->toArray();

            if (! empty($iconIds)) {
                // Delete term relationships for these icons
                $stats['term_relations_deleted'] = DB::table('ichava_icon_termables')
                    ->where('termable_type', (new Icon)->getMorphClass())
                    ->whereIn('termable_id', $iconIds)
                    ->delete();

                // Delete icons
                $stats['icons_deleted'] = Icon::where('package', $packageName)->delete();
            }

            // Clean up orphaned terms
            $stats['orphaned_terms_deleted'] = $this->cleanupOrphanedTerms();

            DB::commit();

            $this->logger->info("Unseeded package: {$packageName}", $stats);

            return $stats;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->logger->error("Failed to unseed package: {$packageName}", $e);
            throw $e;
        }
    }

    /**
     * Remove all Ichava data (unseed all packages)
     */
    public function unseedAll(): array
    {
        $this->logger->info('Unseeding all packages');

        $stats = [
            'icons_deleted' => 0,
            'term_relations_deleted' => 0,
            'terms_deleted' => 0,
        ];

        DB::beginTransaction();

        try {
            // Delete all term relationships
            $stats['term_relations_deleted'] = DB::table('ichava_icon_termables')->delete();

            // Delete all icons
            $stats['icons_deleted'] = Icon::query()->delete();

            // Delete all terms
            $stats['terms_deleted'] = IconTerm::query()->delete();

            DB::commit();

            $this->logger->info('🧹 Unseeded all packages', $stats);

            return $stats;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->logger->error('❌ Failed to unseed all packages', $e);
            throw $e;
        }
    }

    /**
     * Remove orphaned terms (terms with no associated icons)
     */
    public function cleanupOrphanedTerms(): int
    {
        $iconMorphClass = (new Icon)->getMorphClass();

        // Find terms that have no icons attached
        $orphanedTermIds = IconTerm::whereNotExists(function ($query) use ($iconMorphClass) {
            $query->select(DB::raw(1))
                ->from('ichava_icon_termables')
                ->whereColumn('ichava_icon_termables.term_id', 'ichava_icon_terms.id')
                ->where('ichava_icon_termables.termable_type', $iconMorphClass);
        })->pluck('id')->toArray();

        if (empty($orphanedTermIds)) {
            return 0;
        }

        // Delete orphaned terms (cascade will handle children)
        $deleted = IconTerm::whereIn('id', $orphanedTermIds)->delete();

        $this->logger->info("Cleaned up {$deleted} orphaned terms");

        return $deleted;
    }

    /**
     * Determine if a package should use queue for seeding
     */
    public function shouldUseQueue(int $iconCount, bool $forceSync = false): bool
    {
        if ($forceSync) {
            return false;
        }

        $threshold = config('ichava.core.database.smart_queue_threshold', self::SMART_QUEUE_THRESHOLD);

        return $iconCount >= $threshold;
    }

    /**
     * Count icons in a directory
     */
    public function countIconsInDirectory(string $path): int
    {
        if (empty($path) || ! File::isDirectory($path)) {
            return 0;
        }

        $count = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && Str::lower($file->getExtension()) === 'svg') {
                    $count++;
                }
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to count icons in: {$path}", ['error' => $e->getMessage()]);
        }

        return $count;
    }

    /**
     * Get database statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'icons' => 0,
            'packages' => 0,
            'categories' => 0,
            'variants' => 0,
            'term_relationships' => 0,
            'database_size' => null,
        ];

        try {
            $stats['icons'] = Icon::count();
            $stats['packages'] = Icon::distinct('package')->count('package');
            $stats['categories'] = IconTerm::where('type', IconTerm::TYPE_CATEGORY)->count();
            $stats['variants'] = IconTerm::where('type', IconTerm::TYPE_VARIANT)->count();
            $stats['term_relationships'] = DB::table('ichava_icon_termables')->count();

            // Get database size (PostgreSQL)
            if (Helpers::dbDriverIsPgSql()) {
                $stats['database_size'] = $this->getPostgresqlDatabaseSize();
            }
        } catch (Exception $e) {
            $this->logger->warning('⚠️ Failed to get database statistics', ['error' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * Get per-package statistics
     */
    public function getPackageStatistics(): array
    {
        return Icon::select('package', DB::raw('count(*) as count'))
            ->groupBy('package')
            ->orderByDesc('count')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->package => $row->count])
            ->toArray();
    }

    /**
     * Check if database has any seeded data
     */
    public function hasSeeds(): bool
    {
        if (! $this->tablesExist()) {
            return false;
        }

        try {
            return Icon::count() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Drop PostgreSQL FTS objects (triggers, functions, indexes)
     */
    protected function dropPostgresqlFtsObjects(): void
    {
        // Drop triggers
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ichava_icon_terms_search_text ON ichava_icon_terms');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ichava_icon_termables_search_text ON ichava_icon_termables');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ichava_icons_search_text ON ichava_icons');

        // Drop functions
        DB::unprepared('DROP FUNCTION IF EXISTS trg_refresh_ichava_icons_search_text_from_term() CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS refresh_ichava_icons_search_text_for_term(bigint) CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS trg_refresh_ichava_icon_search_text_from_termable() CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS trg_refresh_ichava_icon_search_text() CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS refresh_ichava_icon_search_text(bigint) CASCADE');

        // Drop indexes
        DB::statement('DROP INDEX IF EXISTS idx_icons_search');
        DB::statement('DROP INDEX IF EXISTS idx_icons_created_brin');
        DB::statement('DROP INDEX IF EXISTS idx_icons_updated_brin');
        DB::statement('DROP INDEX IF EXISTS idx_icons_package_hash');
        DB::statement('DROP INDEX IF EXISTS idx_icons_list_covering');
    }

    /**
     * Get PostgreSQL database size for Ichava tables
     */
    protected function getPostgresqlDatabaseSize(): ?string
    {
        try {
            $result = DB::select("
                SELECT pg_size_pretty(
                    pg_total_relation_size('ichava_icons') +
                    pg_total_relation_size('ichava_icon_terms') +
                    pg_total_relation_size('ichava_icon_termables')
                ) as size
            ");

            return $result[0]->size ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
}
