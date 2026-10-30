<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Jobs;

use Exception;
use Throwable;
use RuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\DbTools\DbTools;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Simtabi\Laranail\Ichava\Models\Icon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Simtabi\Laranail\Ichava\Support\Coerce;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Simtabi\Laranail\DbTools\Query\ChunkedWriter;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\IconClassifier;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;
use Simtabi\Laranail\Ichava\Support\Seeder\IconSeederHelpers;
use Simtabi\Laranail\Package\Tools\Support\RuntimeConfigurator;
use Simtabi\Laranail\Package\Tools\Services\Database\SeederRunTracker;

/**
 * Seed Icons Job
 *
 * Processes a chunk of icon files (e.g., 1000 at a time).
 * Dispatched by IchavaSeeder via Laravel's job batching.
 *
 * Multi-level deduplication:
 * 1. DB Level: UNIQUE constraint on (`package`, `path`)
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

    /** The per-row trigger that maintains ichava_icons.search_text (PostgreSQL). */
    public const string SEARCH_TRIGGER = 'trg_ichava_icons_search_text';

    public int $tries;

    public int $timeout;

    /**
     * @param string $packageName Package identifier
     * @param array<string> $files Absolute file paths to process
     * @param int $jobIndex Index of this job (for logging)
     * @param int $totalJobs Total number of jobs in the batch
     * @param bool $force Force update even if file_hash unchanged
     */
    public function __construct(
        public string $packageName,
        public array $files,
        public int $jobIndex,
        public int $totalJobs,
        public bool $force = false,
    ) {
        $this->tries = Coerce::int(config('ichava.ichava-core.queue.retries', 3));
        $this->timeout = Coerce::int(config('ichava.ichava-core.queue.timeout', 300));
        $this->onQueue(Coerce::stringOrNull(config('ichava.ichava-core.queue.name', 'ichava-icons')));

        // Null (the default) keeps the application's default connection.
        $connection = Coerce::stringOrNull(config('ichava.ichava-core.queue.connection'));

        if ($connection !== null && $connection !== '') {
            $this->onConnection($connection);
        }
    }

    /**
     * Execute the job.
     */
    public function handle(IchavaLogger $logger): void
    {
        // Check if batch was cancelled
        if ($this->batch()?->cancelled()) {
            $logger->info('🛑 Seeding cancelled, skipping', [
                'package'   => $this->packageName,
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
            'files'   => $fileCount,
        ]);

        try {
            $result = $this->processFiles($logger);

            // Inside a queued batch the job is the only thing that knows its chunk
            // is done, so it reports progress. An inline run is counted by the
            // ChunkedBatchDispatcher driving it; reporting here too would double it.
            if ($this->batchId !== null) {
                app(SeederRunTracker::class)->advance(IchavaSeeder::trackingKey($this->packageName), by: $fileCount);
            }

            $logger->info("Job {$this->jobIndex} completed", [
                'package'         => $this->packageName,
                'files_received'  => $fileCount,
                'files_processed' => $result['processed'],
                'files_skipped'   => $result['skipped'],
                'files_new'       => $result['new'],
                'files_updated'   => $result['updated'],
            ]);
        } catch (Throwable $e) {
            if ($this->batchId !== null) {
                app(SeederRunTracker::class)->advance(IchavaSeeder::trackingKey($this->packageName), failed: true, by: $fileCount);
            }

            $logger->jobFailed(self::class, $e, [
                'package'   => $this->packageName,
                'job_index' => $this->jobIndex,
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
            throw new RuntimeException("Package {$this->packageName} not found in registry");
        }

        $basePath = rtrim(Coerce::string($packageData['base_path']), '/');
        $stats = ['processed' => 0, 'skipped' => 0, 'new' => 0, 'updated' => 0];
        $classifier = new IconClassifier($registry);

        // Build list of relative paths for this chunk
        $relativePaths = [];
        $fileDataMap = [];

        // Theme twins (`scene-dark.svg` beside `scene.svg`, when the category
        // declares them) are recorded on the light file's row, not as rows of
        // their own. Decided from disk rather than from this chunk, so a twin
        // and its light file landing in different jobs still agree.
        $twinPaths = [];

        foreach ($this->files as $absolutePath) {
            if (! File::exists($absolutePath)) {
                $stats['skipped']++;
                $logger->seedingWarning('Icon file vanished before it was seeded', [
                    'package' => $this->packageName,
                    'file'    => $absolutePath,
                ]);

                continue;
            }

            $relativePath = $this->extractRelativePath($absolutePath, $basePath);

            if ($classifier->twinOf($this->packageName, $basePath, $relativePath) !== null) {
                $twinPaths[] = $relativePath;
                $stats['skipped']++;

                continue;
            }

            $fileHash = md5_file($absolutePath);
            $name = pathinfo($absolutePath, PATHINFO_FILENAME);

            $relativePaths[] = $relativePath;
            $fileDataMap[$relativePath] = [
                'absolute_path'    => $absolutePath,
                'file_hash'        => $fileHash,
                'file_modified_at' => date('Y-m-d H:i:s', filemtime($absolutePath) ?: null),
                'name'             => $name,
                'tags'             => $this->extractTags($relativePath, $name),
                'keywords'         => $this->extractKeywords($relativePath, $name),
                'kind'             => $classifier->kind($this->packageName, $relativePath)->value,
                'theme_variants'   => $classifier->themeVariants($this->packageName, $basePath, $relativePath),
            ];
        }

        $this->removeTwinRows($twinPaths);

        // DEDUPLICATION LEVEL 2: Get existing icons for comparison
        $existingIcons = Icon::where('package', $this->packageName)
            ->whereIn('path', $relativePaths)
            ->get(['path', 'file_hash', 'tags', 'keywords', 'kind', 'theme_variants'])
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
                'package'   => $this->packageName,
                'job_index' => $this->jobIndex,
                'force'     => $this->force,
            ]);

            return $stats;
        }

        // Build icon data for upsert (tags/keywords already extracted above)
        $iconData = [];
        foreach ($toProcess as $relativePath => $data) {
            $iconData[] = [
                'package'          => $this->packageName,
                'name'             => $data['name'],
                'path'             => $relativePath,
                'file_hash'        => $data['file_hash'],
                'file_modified_at' => $data['file_modified_at'],
                'tags'             => Icon::prepareAttributeForDatabase('tags', $data['tags']),
                'keywords'         => Icon::prepareAttributeForDatabase('keywords', $data['keywords']),
                'kind'             => $data['kind'],
                'theme_variants'   => $data['theme_variants'] === [] ? null : json_encode($data['theme_variants'], JSON_UNESCAPED_SLASHES),
                'created_at'       => now(),
                'updated_at'       => now(),
            ];
        }

        // The per-row search trigger is suspended around the bulk write and the
        // search text rebuilt once afterwards. Suspension happens OUTSIDE the
        // transaction: on PostgreSQL a failed ALTER TABLE (not the table owner,
        // trigger missing) aborts the transaction it runs in, and every statement
        // after it fails -- which is how the old in-transaction version would
        // have lost the whole chunk. withoutTriggers() restores the trigger in
        // `finally`, and says whether it actually suspended it.
        $suspended = DbTools::withoutTriggers('ichava_icons', [self::SEARCH_TRIGGER], function (bool $suspended) use ($iconData): bool {
            DB::transaction(function () use ($iconData): void {
                // DEDUPLICATION LEVEL 1: Upsert with unique key constraint
                // This handles any race conditions where multiple jobs might process same files
                Icon::upsert(
                    $iconData,
                    ['package', 'path'], // Unique key: a relative path is only unique within its pack
                    ['name', 'file_hash', 'file_modified_at', 'tags', 'keywords', 'kind', 'theme_variants', 'updated_at'],
                );

                // DEDUPLICATION LEVEL 4: Bulk attach categories + variants with conflict handling
                $this->bulkAttachCategories($iconData);
                $this->bulkAttachVariants($iconData);
            });

            return $suspended;
        });

        if ($suspended) {
            $this->refreshSearchText($iconData);
        }

        // Cleanup
        unset($iconData, $toProcess, $fileDataMap);
        gc_collect_cycles();

        return $stats;
    }

    /**
     * Bulk attach categories to icons with deduplication.
     *
     * Duplicate attachments are ignored by the (term_id, termable_id,
     * termable_type) unique key.
     *
     * @param array<int, array<string, mixed>> $iconData
     */
    protected function bulkAttachCategories(array $iconData): void
    {
        // Build category map
        $categoryMap = IconTerm::inPackage($this->packageName)
            ->where('type', IconTerm::TYPE_CATEGORY)
            ->pluck('id', 'slug')
            ->toArray();

        if (empty($categoryMap)) {
            return;
        }

        // Build path to category slug mapping
        $pathToCategory = [];
        foreach ($iconData as $data) {
            $path = Coerce::string($data['path'] ?? '');
            $categorySlug = $this->extractCategorySlug($path);
            if ($categorySlug && isset($categoryMap[$categorySlug])) {
                $pathToCategory[$path] = $categoryMap[$categorySlug];
            }
        }

        $this->insertTermables($pathToCategory);
    }

    /**
     * Bulk attach variants to icons with deduplication.
     *
     * The variant is the first directory after `files/` when it matches a
     * variant term the pack declares (e.g. tabler's `outline`/`filled`); plain
     * folders that are not declared variants stay categories only, so a path
     * like `files/test-icons/triangle.svg` never gains a bogus variant.
     *
     * @param array<int, array<string, mixed>> $iconData
     */
    protected function bulkAttachVariants(array $iconData): void
    {
        $variantMap = IconTerm::inPackage($this->packageName)
            ->where('type', IconTerm::TYPE_VARIANT)
            ->pluck('id', 'slug')
            ->toArray();

        if (empty($variantMap)) {
            return;
        }

        $pathToVariant = [];
        foreach ($iconData as $data) {
            $path = Coerce::string($data['path'] ?? '');
            $variantSlug = $this->extractVariantSlug($path, $variantMap);
            if ($variantSlug && isset($variantMap[$variantSlug])) {
                $pathToVariant[$path] = $variantMap[$variantSlug];
            }
        }

        $this->insertTermables($pathToVariant);
    }

    /**
     * Insert path→term attachments, ignoring duplicates.
     *
     * The morph type is the registered alias (`icon`), never the FQCN: every
     * reader -- Eloquent relations and the `getMorphClass()` joins in the
     * browser services -- queries the alias, so FQCN rows are invisible to the
     * whole taxonomy (null categories/variants, empty tree counts).
     *
     * @param array<array-key, mixed> $pathToTermId icon path => term id
     */
    protected function insertTermables(array $pathToTermId): void
    {
        if (empty($pathToTermId)) {
            return;
        }

        // Get icon IDs for the paths
        $iconIds = Icon::where('package', $this->packageName)
            ->whereIn('path', array_keys($pathToTermId))
            ->pluck('id', 'path')
            ->toArray();

        // Build termables data
        $termablesData = [];
        $morphType = (new Icon)->getMorphClass();
        $now = now();

        foreach ($pathToTermId as $path => $termId) {
            $iconId = $iconIds[$path] ?? null;
            if (! $iconId) {
                continue;
            }

            $termablesData[] = [
                'term_id'       => $termId,
                'termable_id'   => $iconId,
                'termable_type' => $morphType,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (empty($termablesData)) {
            return;
        }

        // insertOrIgnore() already emits ON CONFLICT DO NOTHING on PostgreSQL and
        // SQLite and INSERT IGNORE on MySQL; chunked to stay under the bind cap.
        ChunkedWriter::for('ichava_icon_termables')->chunk(500)->insertOrIgnore($termablesData);
    }

    /**
     * Delete rows for files that are now theme twins of another file.
     *
     * A pack seeded before its category declared `theme_variants` holds a row
     * per twin; those twins are now variants of their light file, so their
     * own rows (and term attachments) go.
     *
     * @param list<string> $twinPaths
     */
    protected function removeTwinRows(array $twinPaths): void
    {
        if ($twinPaths === []) {
            return;
        }

        $ids = Icon::where('package', $this->packageName)
            ->whereIn('path', $twinPaths)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        DB::table('ichava_icon_termables')
            ->where('termable_type', (new Icon)->getMorphClass())
            ->whereIn('termable_id', $ids)
            ->delete();

        Icon::whereIn('id', $ids)->delete();
    }

    /**
     * Rebuild the search text the suspended trigger did not maintain.
     *
     * @param array<int, array<string, mixed>> $iconData
     */
    protected function refreshSearchText(array $iconData): void
    {
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
                } catch (Exception $e) {
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
     * 4. kind - The pack's config.json re-filed the category
     * 5. theme_variants - A theme twin appeared, went, or changed
     *
     * @param Icon $existing Existing icon from database
     * @param array<string, mixed> $newData New data from file
     *
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
        $newTags = is_array($newData['tags'] ?? null) ? $newData['tags'] : [];
        if ($this->arraysDiffer($existingTags, $newTags)) {
            return true;
        }

        // 3. Keywords changed (extraction logic updated)
        $existingKeywords = $existing->keywords ?? [];
        $newKeywords = is_array($newData['keywords'] ?? null) ? $newData['keywords'] : [];
        if ($this->arraysDiffer($existingKeywords, $newKeywords)) {
            return true;
        }

        // 4. Kind changed: a pack that starts declaring a category as
        // illustrations must re-file its unchanged files without --force,
        // or they stay icons until somebody happens to force a re-seed.
        if ($existing->kind->value !== $newData['kind']) {
            return true;
        }

        // 5. Theme twins changed: one appeared, went, or its file changed.
        $existingThemes = is_array($existing->theme_variants) ? $existing->theme_variants : [];
        ksort($existingThemes);

        return $existingThemes !== ($newData['theme_variants'] ?? []);
    }

    /**
     * Check if two arrays have different contents (order-insensitive).
     *
     * Tags and keywords are lists of strings; each entry is read as one.
     *
     * @param array<array-key, mixed> $a
     * @param array<array-key, mixed> $b
     */
    protected function arraysDiffer(array $a, array $b): bool
    {
        // Normalize: sort and remove duplicates
        $a = array_unique(array_map(Coerce::string(...), $a));
        $b = array_unique(array_map(Coerce::string(...), $b));
        sort($a);
        sort($b);

        return $a !== $b;
    }
}
