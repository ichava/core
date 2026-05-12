<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Seeder\IconSeederHelpers;
use Simtabi\Laranail\PackageTools\Support\RuntimeConfigurator;

/**
 * Seed Icons Job
 *
 * Processes a chunk of icon files (e.g., 1000 at a time).
 * Dispatched by IchavaSeeder via Laravel's job batching.
 *
 * Multi-level deduplication:
 * 1. DB Level: UNIQUE constraint on `path` column
 * 2. Hash Level: Skip files with unchanged file_hash
 * 3. Job Level: Filter out already-processed files before insert
 * 4. Category Level: Bulk upsert with conflict handling
 *
 * Memory efficient: Each job processes only its assigned files.
 * Parallelizable: Multiple workers can process jobs simultaneously.
 *
 * @see IchavaSeeder::seed() Dispatches these jobs
 * @see IconSeederHelpers Trait for path/tag extraction
 */
class SeedIconsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use IconSeederHelpers;

    public int $tries;

    public int $timeout;

    /**
     * @param  string  $packageName  Package identifier
     * @param  array<string>  $files  Absolute file paths to process
     * @param  int  $jobIndex  Index of this job (for logging)
     * @param  int  $totalJobs  Total number of jobs in the batch
     * @param  bool  $force  Force update even if file_hash unchanged
     */
    public function __construct(
        public string $packageName,
        public array $files,
        public int $jobIndex,
        public int $totalJobs,
        public bool $force = false
    ) {
        $this->tries = (int) config('ichava.queue.retries', 3);
        $this->timeout = (int) config('ichava.queue.timeout', 300);
        $this->onQueue(config('ichava.queue.name', 'ichava-icons'));
    }

    /**
     * Execute the job.
     */
    public function handle(IchavaLogger $logger): void
    {
        // Check if batch was cancelled
        if ($this->batch()?->cancelled()) {
            $logger->info('🛑 Seeding cancelled, skipping', [
                'package' => $this->packageName,
                'job_index' => $this->jobIndex,
            ]);

            return;
        }

        RuntimeConfigurator::make()
            ->memory('512M')
            ->timeout($this->timeout)
            ->disableTelescope()
            ->apply();

        $fileCount = count($this->files);
        $logger->info("Processing job {$this->jobIndex}/{$this->totalJobs}", [
            'package' => $this->packageName,
            'files' => $fileCount,
        ]);

        try {
            $result = $this->processFiles($logger);

            $logger->info("Job {$this->jobIndex} completed", [
                'package' => $this->packageName,
                'files_received' => $fileCount,
                'files_processed' => $result['processed'],
                'files_skipped' => $result['skipped'],
                'files_new' => $result['new'],
                'files_updated' => $result['updated'],
            ]);
        } catch (\Throwable $e) {
            $logger->error("Job {$this->jobIndex} failed: {$e->getMessage()}", $e, [
                'package' => $this->packageName,
            ]);
            throw $e;
        }
    }

    /**
     * Process the files with multi-level deduplication.
     *
     * Change detection modes:
     * 1. Normal: Skip if file_hash unchanged
     * 2. Force: Process all files regardless of hash
     *
     * @return array{processed: int, skipped: int, new: int, updated: int}
     */
    protected function processFiles(IchavaLogger $logger): array
    {
        $registry = app(IconRegistry::class);
        $packages = $registry->all();
        $packageData = $packages[$this->packageName] ?? null;

        if (! $packageData) {
            throw new \RuntimeException("Package {$this->packageName} not found in registry");
        }

        $basePath = rtrim($packageData['base_path'], '/');
        $stats = ['processed' => 0, 'skipped' => 0, 'new' => 0, 'updated' => 0];

        // Build list of relative paths for this chunk
        $relativePaths = [];
        $fileDataMap = [];

        foreach ($this->files as $absolutePath) {
            if (! File::exists($absolutePath)) {
                $stats['skipped']++;

                continue;
            }

            $relativePath = $this->extractRelativePath($absolutePath, $basePath);
            $fileHash = md5_file($absolutePath);
            $name = pathinfo($absolutePath, PATHINFO_FILENAME);

            $relativePaths[] = $relativePath;
            $fileDataMap[$relativePath] = [
                'absolute_path' => $absolutePath,
                'file_hash' => $fileHash,
                'file_modified_at' => date('Y-m-d H:i:s', filemtime($absolutePath)),
                'name' => $name,
                'tags' => $this->extractTags($relativePath, $name),
                'keywords' => $this->extractKeywords($relativePath, $name),
            ];
        }

        // DEDUPLICATION LEVEL 2: Get existing icons for comparison
        $existingIcons = Icon::where('package', $this->packageName)
            ->whereIn('path', $relativePaths)
            ->get(['path', 'file_hash', 'tags', 'keywords'])
            ->keyBy('path');

        // Determine which files need processing
        $toProcess = [];
        foreach ($fileDataMap as $relativePath => $data) {
            $existing = $existingIcons[$relativePath] ?? null;

            if (! $existing) {
                // New icon
                $toProcess[$relativePath] = $data;
                $stats['new']++;

                continue;
            }

            // Force mode: always update
            if ($this->force) {
                $toProcess[$relativePath] = $data;
                $stats['updated']++;

                continue;
            }

            // Check if anything changed
            $hasChanges = $this->detectChanges($existing, $data);

            if ($hasChanges) {
                $toProcess[$relativePath] = $data;
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
        }

        $stats['processed'] = count($toProcess);

        if (empty($toProcess)) {
            $logger->debug('All files unchanged, skipping', [
                'package' => $this->packageName,
                'job_index' => $this->jobIndex,
                'force' => $this->force,
            ]);

            return $stats;
        }

        DB::beginTransaction();

        try {
            // Disable trigger for bulk operations (PostgreSQL)
            $triggerDisabled = $this->disableSearchTrigger();

            // Build icon data for upsert (tags/keywords already extracted above)
            $iconData = [];
            foreach ($toProcess as $relativePath => $data) {
                $iconData[] = [
                    'package' => $this->packageName,
                    'name' => $data['name'],
                    'path' => $relativePath,
                    'file_hash' => $data['file_hash'],
                    'file_modified_at' => $data['file_modified_at'],
                    'tags' => Icon::prepareAttributeForDatabase('tags', $data['tags']),
                    'keywords' => Icon::prepareAttributeForDatabase('keywords', $data['keywords']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // DEDUPLICATION LEVEL 1: Upsert with unique key constraint
            // This handles any race conditions where multiple jobs might process same files
            Icon::upsert(
                $iconData,
                ['path'], // Unique key
                ['package', 'name', 'file_hash', 'file_modified_at', 'tags', 'keywords', 'updated_at']
            );

            // DEDUPLICATION LEVEL 4: Bulk attach categories with conflict handling
            $this->bulkAttachCategories($iconData);

            DB::commit();

            // Re-enable trigger and refresh search text
            $this->enableSearchTriggerAndRefresh($triggerDisabled, $iconData);

            // Cleanup
            unset($iconData, $toProcess, $fileDataMap);
            gc_collect_cycles();

        } catch (\Throwable $e) {
            $this->enableSearchTrigger();
            DB::rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * Bulk attach categories to icons with deduplication.
     *
     * Uses INSERT ... ON CONFLICT DO NOTHING for PostgreSQL
     * or INSERT IGNORE for MySQL to prevent duplicate term attachments.
     */
    protected function bulkAttachCategories(array $iconData): void
    {
        // Build category map
        $categoryMap = IconTerm::where('package', $this->packageName)
            ->where('type', IconTerm::TYPE_CATEGORY)
            ->pluck('id', 'slug')
            ->toArray();

        if (empty($categoryMap)) {
            return;
        }

        // Build path to category slug mapping
        $pathToCategory = [];
        foreach ($iconData as $data) {
            $categorySlug = $this->extractCategorySlug($data['path']);
            if ($categorySlug && isset($categoryMap[$categorySlug])) {
                $pathToCategory[$data['path']] = $categoryMap[$categorySlug];
            }
        }

        if (empty($pathToCategory)) {
            return;
        }

        // Get icon IDs for the paths
        $iconIds = Icon::where('package', $this->packageName)
            ->whereIn('path', array_keys($pathToCategory))
            ->pluck('id', 'path')
            ->toArray();

        // Build termables data
        $termablesData = [];
        $morphType = Icon::class;
        $now = now();

        foreach ($pathToCategory as $path => $termId) {
            $iconId = $iconIds[$path] ?? null;
            if (! $iconId) {
                continue;
            }

            $termablesData[] = [
                'term_id' => $termId,
                'termable_id' => $iconId,
                'termable_type' => $morphType,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($termablesData)) {
            return;
        }

        // Bulk insert with conflict handling
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL: Use ON CONFLICT DO NOTHING
            $this->bulkInsertTermablesPostgres($termablesData);
        } else {
            // MySQL/SQLite: Use insertOrIgnore
            DB::table('ichava_icon_termables')->insertOrIgnore($termablesData);
        }
    }

    /**
     * Bulk insert termables for PostgreSQL with ON CONFLICT DO NOTHING.
     */
    protected function bulkInsertTermablesPostgres(array $data): void
    {
        if (empty($data)) {
            return;
        }

        // Process in chunks to avoid parameter limits
        collect($data)->chunk(500)->each(function (Collection $chunk) {
            $values = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?)';
                $bindings[] = $row['term_id'];
                $bindings[] = $row['termable_id'];
                $bindings[] = $row['termable_type'];
                $bindings[] = $row['created_at'];
                $bindings[] = $row['updated_at'];
            }

            $sql = 'INSERT INTO ichava_icon_termables (term_id, termable_id, termable_type, created_at, updated_at) VALUES '
                .implode(', ', $values)
                .' ON CONFLICT (term_id, termable_id, termable_type) DO NOTHING';

            DB::statement($sql, $bindings);
        });
    }

    /**
     * Disable PostgreSQL search trigger for bulk operations.
     */
    protected function disableSearchTrigger(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            DB::statement('ALTER TABLE ichava_icons DISABLE TRIGGER trg_ichava_icons_search_text');

            return true;
        } catch (\Exception $e) {
            // Trigger might not exist
            return false;
        }
    }

    /**
     * Re-enable PostgreSQL search trigger.
     */
    protected function enableSearchTrigger(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('ALTER TABLE ichava_icons ENABLE TRIGGER trg_ichava_icons_search_text');
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Re-enable trigger and refresh search text for affected icons.
     */
    protected function enableSearchTriggerAndRefresh(bool $triggerDisabled, array $iconData): void
    {
        if (! $triggerDisabled) {
            return;
        }

        $this->enableSearchTrigger();

        // Bulk refresh search text for processed icons
        $paths = Arr::pluck($iconData, 'path');
        $iconIds = Icon::where('package', $this->packageName)
            ->whereIn('path', $paths)
            ->pluck('id');

        // Process in chunks to avoid memory issues
        foreach ($iconIds->chunk(100) as $chunk) {
            foreach ($chunk as $iconId) {
                try {
                    DB::statement('SELECT refresh_ichava_icon_search_text(?)', [$iconId]);
                } catch (\Exception $e) {
                    // Log but don't fail
                }
            }
        }
    }

    /**
     * Detect if any relevant data has changed for an existing icon.
     *
     * Compares:
     * 1. file_hash - SVG content changed
     * 2. tags - Tag extraction logic may have changed
     * 3. keywords - Keyword extraction logic may have changed
     *
     * @param  Icon  $existing  Existing icon from database
     * @param  array  $newData  New data from file
     * @return bool True if any changes detected
     */
    protected function detectChanges(Icon $existing, array $newData): bool
    {
        // 1. File content changed (most common case)
        if ($existing->file_hash !== $newData['file_hash']) {
            return true;
        }

        // 2. Tags changed (extraction logic updated)
        $existingTags = $existing->tags ?? [];
        $newTags = $newData['tags'] ?? [];
        if ($this->arraysDiffer($existingTags, $newTags)) {
            return true;
        }

        // 3. Keywords changed (extraction logic updated)
        $existingKeywords = $existing->keywords ?? [];
        $newKeywords = $newData['keywords'] ?? [];
        if ($this->arraysDiffer($existingKeywords, $newKeywords)) {
            return true;
        }

        return false;
    }

    /**
     * Check if two arrays have different contents (order-insensitive).
     */
    protected function arraysDiffer(array $a, array $b): bool
    {
        // Normalize: sort and remove duplicates
        $a = array_unique($a);
        $b = array_unique($b);
        sort($a);
        sort($b);

        return $a !== $b;
    }
}
