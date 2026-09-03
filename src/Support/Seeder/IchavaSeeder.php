<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Seeder;

use Exception;
use Throwable;
use Illuminate\Bus\Batch;
use Illuminate\Support\Str;
use RecursiveIteratorIterator;
use Illuminate\Database\Seeder;
use RecursiveDirectoryIterator;
use Ajaxray\AnsiKit\AnsiTerminal;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\warning;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Jobs\SeedIconsJob;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * Central seeder for all registered icon packages.
 *
 * Walks each package's icon directory, splits files into configurable chunks
 * (default 1000), and dispatches them to the queue (or runs sync) for
 * parallel processing. See README § "Database Seeding" for invocation and
 * deduplication details.
 *
 * @see SeedIconsJob
 */
class IchavaSeeder extends Seeder
{
    /**
     * Default number of icons per job.
     */
    public const DEFAULT_CHUNK_SIZE = 1000;

    protected IchavaLogger $logger;

    /**
     * Whether to force synchronous seeding (no queue)
     */
    protected bool $syncMode = false;

    /**
     * Whether to force update even if file hash unchanged
     */
    protected bool $forceUpdate = false;

    public function __construct()
    {
        $this->logger = app('ichava.logger');
    }

    /**
     * Set sync mode (force synchronous seeding)
     */
    public function setSyncMode(bool $sync): self
    {
        $this->syncMode = $sync;

        return $this;
    }

    /**
     * Check if sync mode is enabled
     */
    public function isSyncMode(): bool
    {
        return $this->syncMode;
    }

    /**
     * Set force update mode (update all entries even if unchanged)
     */
    public function setForceUpdate(bool $force): self
    {
        $this->forceUpdate = $force;

        return $this;
    }

    /**
     * Check if force update is enabled
     */
    public function isForceUpdate(): bool
    {
        return $this->forceUpdate;
    }

    /**
     * Seed a single package (terms + icons).
     *
     * This is the recommended method for auto-seeding as it includes
     * both term seeding (categories/variants) and icon seeding.
     *
     * @param string $packageName Package identifier
     * @param string $svgPath Path to SVG directory
     * @param bool $useQueue Use queue for icon seeding
     * @param int $chunkSize Icons per job
     * @param bool $force Force update even if unchanged
     *
     * @return array Result with 'terms', 'icons', and 'batch_id' (if queued)
     */
    public function seedPackage(
        string $packageName,
        string $svgPath,
        bool $useQueue = true,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        bool $force = false,
    ): array {
        $result = [
            'package'   => $packageName,
            'terms'     => false,
            'icons'     => false,
            'batch_id'  => null,
            'processed' => 0,
            'error'     => null,
            'force'     => $force,
        ];

        try {
            // Step 1: Seed terms (categories/variants) - always sync
            $this->seedTermsForPackage($packageName, $svgPath);
            $result['terms'] = true;

            // Step 2: Seed icons
            if ($useQueue) {
                $batch = $this->seed($packageName, $svgPath, $chunkSize, null, $force);
                if ($batch) {
                    $result['icons'] = true;
                    $result['batch_id'] = $batch->id;
                }
            } else {
                $syncResult = $this->seedSync($packageName, $svgPath, $chunkSize, $force);
                if (! isset($syncResult['error'])) {
                    $result['icons'] = true;
                    $result['processed'] = $syncResult['processed'];
                } else {
                    $result['error'] = $syncResult['error'];
                }
            }
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
            $this->logger->error("❌ Failed to seed package: {$packageName}", $e);
        }

        return $result;
    }

    /**
     * Seed icons to queue (parallel processing).
     *
     * @param string $packageName Package identifier
     * @param string $svgPath Path to SVG directory
     * @param int $chunkSize Icons per job (default: 1000)
     * @param callable|null $onProgress Progress callback
     * @param bool $force Force update even if unchanged
     *
     * @return Batch|null The batch instance or null if no icons found
     */
    public function seed(
        string $packageName,
        string $svgPath,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        ?callable $onProgress = null,
        bool $force = false,
    ): ?Batch {
        if (! File::isDirectory($svgPath)) {
            $this->logger->error("❌ Directory not found: {$svgPath}");

            return null;
        }

        $files = $this->collectFiles($svgPath);
        $totalFiles = count($files);

        if ($totalFiles === 0) {
            $this->logger->warning("⚠️ No SVG files found in {$svgPath}");

            return null;
        }

        $this->logger->info('🌱 Starting icon seeding', [
            'package'     => $packageName,
            'total_files' => $totalFiles,
            'chunk_size'  => $chunkSize,
            'force'       => $force,
        ]);

        // Chunk files
        $chunks = array_chunk($files, $chunkSize);
        $totalJobs = count($chunks);

        $this->logger->info("📦 Created {$totalJobs} seeding jobs", ['package' => $packageName]);

        // Create jobs
        $jobs = [];
        foreach ($chunks as $index => $chunk) {
            $jobs[] = new SeedIconsJob(
                packageName: $packageName,
                files: $chunk,
                jobIndex: $index + 1,
                totalJobs: $totalJobs,
                force: $force,
            );
        }

        // Batch closures must avoid capturing $this (non-serializable command).
        // Resolve IchavaLogger from the container at execution time instead.
        $batch = Bus::batch($jobs)
            ->name("Seed Icons: {$packageName}")
            ->onQueue(config('ichava.core.queue.name', 'ichava-icons'))
            ->allowFailures()
            ->before(function (Batch $b) use ($packageName) {
                app(IchavaLogger::class)->seedingInfo('🌱 Icon seeding started', [
                    'package'    => $packageName,
                    'batch_id'   => $b->id,
                    'total_jobs' => $b->totalJobs,
                ]);
            })
            ->progress(function (Batch $b) use ($packageName, $onProgress) {
                app(IchavaLogger::class)->seedingInfo('🔄 Seeding progress', [
                    'package'   => $packageName,
                    'progress'  => $b->progress(),
                    'processed' => $b->processedJobs(),
                    'pending'   => $b->pendingJobs,
                    'failed'    => $b->failedJobs,
                ]);
                if ($onProgress) {
                    $onProgress($b);
                }
            })
            ->then(function (Batch $b) use ($packageName, $totalFiles) {
                app(IchavaLogger::class)->seedingInfo('✅ Icon seeding completed', [
                    'package'        => $packageName,
                    'batch_id'       => $b->id,
                    'total_files'    => $totalFiles,
                    'processed_jobs' => $b->processedJobs(),
                ]);
            })
            ->catch(function (Batch $b, Throwable $e) use ($packageName) {
                app(IchavaLogger::class)->seedingError('❌ Icon seeding failed', [
                    'package'     => $packageName,
                    'batch_id'    => $b->id,
                    'failed_jobs' => $b->failedJobs,
                    'error'       => $e->getMessage(),
                ]);
            })
            ->finally(function (Batch $b) use ($packageName) {
                app(IchavaLogger::class)->seedingInfo('🏁 Icon seeding finished', [
                    'package'  => $packageName,
                    'batch_id' => $b->id,
                    'success'  => ! $b->hasFailures(),
                ]);
            })
            ->dispatch();

        $this->logger->info('🚀 Seeding jobs dispatched', [
            'package'    => $packageName,
            'batch_id'   => $batch->id,
            'total_jobs' => count($jobs),
        ]);

        return $batch;
    }

    /**
     * Seed synchronously (no queue, for testing or small sets).
     *
     * @param string $packageName Package identifier
     * @param string $svgPath Path to SVG directory
     * @param int $chunkSize Icons per chunk
     * @param bool $force Force update even if unchanged
     */
    public function seedSync(
        string $packageName,
        string $svgPath,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        bool $force = false,
    ): array {
        if (! File::isDirectory($svgPath)) {
            return ['error' => "Directory not found: {$svgPath}"];
        }

        $files = $this->collectFiles($svgPath);
        $totalFiles = count($files);

        if ($totalFiles === 0) {
            return ['error' => 'No SVG files found'];
        }

        $chunks = array_chunk($files, $chunkSize);
        $totalJobs = count($chunks);
        $processed = 0;
        $errors = [];

        foreach ($chunks as $index => $chunk) {
            try {
                $job = new SeedIconsJob(
                    packageName: $packageName,
                    files: $chunk,
                    jobIndex: $index + 1,
                    totalJobs: $totalJobs,
                    force: $force,
                );

                $job->handle($this->logger);
                $processed += count($chunk);

                gc_collect_cycles();

            } catch (Throwable $e) {
                $errors[] = [
                    'job'   => $index + 1,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'total_files' => $totalFiles,
            'processed'   => $processed,
            'jobs'        => $totalJobs,
            'errors'      => $errors,
            'force'       => $force,
        ];
    }

    /**
     * Get seeding job status by batch ID.
     */
    public function getStatus(string $batchId): ?array
    {
        $batch = Bus::findBatch($batchId);

        if (! $batch) {
            return null;
        }

        return [
            'id'             => $batch->id,
            'name'           => $batch->name,
            'progress'       => $batch->progress(),
            'total_jobs'     => $batch->totalJobs,
            'pending_jobs'   => $batch->pendingJobs,
            'processed_jobs' => $batch->processedJobs(),
            'failed_jobs'    => $batch->failedJobs,
            'has_failures'   => $batch->hasFailures(),
            'finished'       => $batch->finished(),
            'cancelled'      => $batch->cancelled(),
            'created_at'     => $batch->createdAt,
            'finished_at'    => $batch->finishedAt,
        ];
    }

    /**
     * Cancel a running seeding operation.
     */
    public function cancel(string $batchId): bool
    {
        $batch = Bus::findBatch($batchId);

        if (! $batch) {
            return false;
        }

        $batch->cancel();
        $this->logger->info('🛑 Seeding cancelled', ['batch_id' => $batchId]);

        return true;
    }

    /**
     * Run the database seeds (console only)
     */
    public function run(): void
    {
        if (! app()->runningInConsole()) {
            throw IchavaException::seedingRequiresConsole();
        }

        $this->displayHeader();

        if (! $this->ensureTableExists()) {
            return;
        }

        $this->seedAllPackages();

        $this->displayFooter();
    }

    /**
     * Seed terms (categories/variants) for a single package.
     */
    protected function seedTermsForPackage(string $packageName, string $svgPath): void
    {
        try {
            $registry = app(IconRegistry::class);
            $packages = $registry->all();
            $packageData = $packages[$packageName] ?? null;

            if (! $packageData) {
                $this->logger->warning("⚠️ Package data not found in registry: {$packageName}");
                // Continue anyway - terms can be seeded with minimal data
                $packageData = ['name' => $packageName];
            }

            $termSeeder = new IconTermsSeeder;
            $termSeeder->seedSinglePackage($packageName, [
                'svg_path'     => $svgPath,
                'base_path'    => $svgPath,
                'package_data' => $packageData,
            ]);

            $this->logger->debug("✅ Terms seeded for package: {$packageName}");
        } catch (Throwable $e) {
            $this->logger->warning("⚠️ Failed to seed terms for: {$packageName}", ['error' => $e->getMessage()]);
            // Don't throw - icon seeding can continue even if terms fail
        }
    }

    /**
     * Seed all packages with terms first, then seed icons
     */
    protected function seedAllPackages(): void
    {
        $this->command->info('🔄 Discovering registered icon packages...');
        $this->command->newLine();
        $this->logger->seedingInfo('🔍 Starting package discovery');

        $packages = $this->discoverPackages();

        if (empty($packages)) {
            $this->displayNoPackagesMessage();

            return;
        }

        $this->command->info('📋 Found ' . count($packages) . ' package(s)');
        $this->command->newLine();
        $this->logger->seedingInfo('Found ' . count($packages) . ' registered packages');

        $chunkSize = (int) config('ichava.core.database.batch_size', self::DEFAULT_CHUNK_SIZE);

        $stats = [
            'packages_total'   => count($packages),
            'packages_success' => 0,
            'packages_failed'  => 0,
            'jobs_dispatched'  => 0,
            'total_icons'      => 0,
            'chunk_size'       => $chunkSize,
            'mode'             => $this->syncMode ? 'sync' : 'queue',
            'force_update'     => $this->forceUpdate,
            'package_details'  => [],
        ];

        foreach ($packages as $packageName => $packageData) {
            $packageStats = [
                'name'   => $packageName,
                'icons'  => 0,
                'jobs'   => 0,
                'status' => 'pending',
            ];

            try {
                $this->command->line("  <fg=cyan>📦 {$packageName}</fg=cyan>");

                // Step 1: Seed terms for this package
                $this->seedPackageTerms($packageName, $packageData);

                // Step 2: Count icons
                $iconCount = $this->countIcons($packageData['svg_path']);
                $packageStats['icons'] = $iconCount;
                $stats['total_icons'] += $iconCount;

                $this->command->line("    <fg=gray>Icons found: {$iconCount}</fg=gray>");
                $this->logger->seedingInfo("🎨 Icons found for {$packageName}: {$iconCount}");

                if ($iconCount === 0) {
                    $this->command->line('    <fg=yellow>⚠ No icons to seed</fg=yellow>');
                    $packageStats['status'] = 'empty';
                    $stats['packages_success']++;
                    $stats['package_details'][] = $packageStats;

                    continue;
                }

                // Step 3: Seed icons
                $forceMsg = $this->forceUpdate ? ' (force update)' : '';

                if ($this->syncMode || ! config('ichava.core.database.use_queue', true)) {
                    $this->command->line("    <fg=yellow>→ Seeding icons synchronously{$forceMsg} (chunk size: {$chunkSize})...</fg=yellow>");

                    $result = $this->seedSync($packageName, $packageData['svg_path'], $chunkSize, $this->forceUpdate);

                    if (isset($result['error'])) {
                        throw new IchavaException($result['error']);
                    }

                    $packageStats['jobs'] = $result['jobs'];
                    $packageStats['status'] = 'synced';
                    $this->command->line("    <fg=green>✓ Seeded {$result['processed']} icons in {$result['jobs']} chunks</fg=green>");
                    $this->logger->seedingInfo("✅ Icons seeded synchronously for {$packageName}", $result);

                } else {
                    $this->command->line("    <fg=yellow>→ Dispatching seeding jobs{$forceMsg} (chunk size: {$chunkSize})...</fg=yellow>");

                    $batch = $this->seed($packageName, $packageData['svg_path'], $chunkSize, null, $this->forceUpdate);

                    if ($batch) {
                        $totalJobs = $batch->totalJobs;
                        $packageStats['jobs'] = $totalJobs;
                        $packageStats['status'] = 'queued';
                        $packageStats['batch_id'] = $batch->id;
                        $stats['jobs_dispatched'] += $totalJobs;
                        $this->command->line("    <fg=green>✓ Dispatched {$totalJobs} jobs (ID: {$batch->id})</fg=green>");
                        $this->logger->seedingInfo("🚀 Seeding jobs dispatched for {$packageName}", [
                            'batch_id'     => $batch->id,
                            'total_jobs'   => $totalJobs,
                            'icon_count'   => $iconCount,
                            'force_update' => $this->forceUpdate,
                        ]);
                    }
                }

                $stats['packages_success']++;

            } catch (IchavaException|Throwable $e) {
                $this->command->error('    ❌ Failed: ' . $e->getMessage());
                $this->logger->error("❌ Failed to process package: {$packageName}", $e, [
                    'package' => $packageName,
                ]);
                $packageStats['status'] = 'failed';
                $packageStats['error'] = $e->getMessage();
                $stats['packages_failed']++;
            }

            $stats['package_details'][] = $packageStats;
            $this->command->newLine();
        }

        $this->displayStats($stats);

        if ($stats['jobs_dispatched'] > 0) {
            $this->processQueuedJobs($stats['jobs_dispatched']);
        }
    }

    /**
     * Collect all SVG files from directory.
     */
    protected function collectFiles(string $path): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $path,
                RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS,
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && Str::lower($file->getExtension()) === 'svg') {
                $files[] = $file->getRealPath();
            }
        }

        return $files;
    }

    /**
     * Count icons in a package directory
     */
    protected function countIcons(string $svgPath): int
    {
        if (! File::isDirectory($svgPath)) {
            return 0;
        }

        return count($this->collectFiles($svgPath));
    }

    /**
     * Seed package terms (categories/variants)
     */
    protected function seedPackageTerms(string $packageName, array $packageData): void
    {
        $this->command->line('    <fg=gray>→ Seeding terms...</fg=gray>');

        try {
            $termSeeder = new IconTermsSeeder;
            $termSeeder->setCommand($this->command);
            $termSeeder->setContainer(app());
            $termSeeder->seedSinglePackage($packageName, $packageData);
            $this->command->line('    <fg=green>✓ Terms seeded</fg=green>');
        } catch (IchavaException $e) {
            $this->command->warn("    ⚠ Terms failed: {$e->getMessage()}");
        }
    }

    /**
     * Discover all registered packages
     */
    protected function discoverPackages(): array
    {
        $packages = [];

        try {
            $registry = app(IconRegistry::class);
            $registered = $registry->all();

            foreach ($registered as $packageName => $packageData) {
                try {
                    $iconSet = $registry->set($packageName);
                    $svgPath = $iconSet->basePath();
                } catch (Exception $e) {
                    $svgPath = $packageData['base_path'] ?? '';
                }

                if (empty($svgPath) || ! File::isDirectory($svgPath)) {
                    continue;
                }

                $packages[$packageName] = [
                    'svg_path'     => $svgPath,
                    'base_path'    => $svgPath,
                    'package_data' => $packageData,
                ];
            }
        } catch (IchavaException $e) {
            // Registry not available
        }

        return $packages;
    }

    /**
     * Ensure table exists
     */
    protected function ensureTableExists(): bool
    {
        if (! Schema::hasTable('ichava_icons')) {
            $this->command->error('❌ Table "ichava_icons" does not exist!');
            $this->command->warn('💡 Run migrations first: php artisan migrate');

            return false;
        }

        return true;
    }

    protected function displayHeader(): void
    {
        $terminal = new AnsiTerminal;
        $terminal->writeStyled("🚀 Ichava Icon Database Seeding\n", [AnsiTerminal::TEXT_BOLD, AnsiTerminal::FG_CYAN]);
        $this->command->newLine();
    }

    protected function displayFooter(): void
    {
        $terminal = new AnsiTerminal;
        $this->command->newLine();
        $terminal->writeStyled("✅ Seeding completed!\n", [AnsiTerminal::TEXT_BOLD, AnsiTerminal::FG_GREEN]);

        if ($this->syncMode) {
            try {
                $totalIcons = Icon::count();
                info('📊 Total icons in database: ' . number_format($totalIcons));
            } catch (Exception $e) {
                warning('📊 Unable to query database. Run: php artisan ichava:database stats');
            }
        }
    }

    protected function displayStats(array $stats): void
    {
        $this->command->newLine();

        // Header using AnsiKit
        $terminal = new AnsiTerminal;
        $terminal->writeStyled("📊 ICHAVA SEEDING SUMMARY\n", [AnsiTerminal::TEXT_BOLD, AnsiTerminal::FG_CYAN]);

        // Configuration summary table
        $mode = $stats['mode'] === 'sync' ? 'Synchronous' : 'Queue';
        $force = $stats['force_update'] ? 'Yes' : 'No';

        table(
            headers: ['Setting', 'Value'],
            rows: [
                ['Mode', $mode],
                ['Force Update', $force],
                ['Chunk Size', number_format($stats['chunk_size'])],
                ['Total Icons', number_format($stats['total_icons'])],
                ['Total Jobs', number_format($stats['jobs_dispatched'])],
            ],
        );

        $this->command->newLine();
        info("📦 Packages ({$stats['packages_total']} total)");

        // Package breakdown table
        $packageRows = [];
        foreach ($stats['package_details'] as $pkg) {
            $status = match ($pkg['status']) {
                'synced' => '✓ Synced',
                'queued' => '⏳ Queued',
                'empty'  => '○ Empty',
                'failed' => '✗ Failed',
                default  => '? Unknown',
            };

            $packageRows[] = [
                $pkg['name'],
                number_format($pkg['icons']),
                (string) $pkg['jobs'],
                $status,
            ];
        }

        table(
            headers: ['Package', 'Icons', 'Jobs', 'Status'],
            rows: $packageRows,
        );

        $this->command->newLine();

        // Result
        if ($stats['packages_failed'] > 0) {
            warning("⚠ {$stats['packages_failed']} package(s) failed!");
        } else {
            $terminal->writeStyled("✓ All {$stats['packages_success']} package(s) processed successfully!\n", [AnsiTerminal::TEXT_BOLD, AnsiTerminal::FG_GREEN]);
        }
    }

    /**
     * Process queued jobs automatically.
     *
     * Uses `queue:work --stop-when-empty` to process all pending jobs
     * and return when done, allowing the seeder to complete.
     */
    protected function processQueuedJobs(int $jobCount): void
    {
        $queueName = config('ichava.core.queue.name', 'ichava-icons');
        $terminal = new AnsiTerminal;

        $this->command->newLine();
        $terminal->writeStyled("⏳ Processing {$jobCount} seeding jobs...\n", [AnsiTerminal::TEXT_BOLD, AnsiTerminal::FG_YELLOW]);
        $this->command->newLine();

        try {
            // Process all queued jobs and exit when done
            Artisan::call('queue:work', [
                '--queue'           => $queueName,
                '--stop-when-empty' => true,
                '--memory'          => 512,
                '--timeout'         => 300,
            ], $this->command->getOutput());

            $this->command->newLine();
            $terminal->writeStyled("✅ All seeding jobs processed!\n", [AnsiTerminal::TEXT_BOLD, AnsiTerminal::FG_GREEN]);

        } catch (Throwable $e) {
            $terminal->writeStyled('❌ Queue processing failed: ' . $e->getMessage() . "\n", [AnsiTerminal::TEXT_BOLD, AnsiTerminal::FG_RED]);
            $this->command->newLine();
            note("Run manually: php artisan queue:work --queue={$queueName} --stop-when-empty");
        }
    }

    protected function displayJobInstructions(int $jobCount): void
    {
        $queueName = config('ichava.core.queue.name', 'ichava-icons');

        $this->command->newLine();
        warning("⏳ {$jobCount} seeding jobs dispatched to queue");
        $this->command->newLine();
        note("Start queue worker: php artisan queue:work --queue={$queueName}");
        note('Or use Horizon: php artisan horizon');
    }

    protected function displayNoPackagesMessage(): void
    {
        warning('⚠️  No icon packages registered!');
        $this->command->newLine();
        note('💡 Register packages using IconRegistry::fromDirectory()');

        $codeExample = <<<'CODE'
IconRegistry::fromDirectory(
    $this->package->basePath('resources/assets/svg'),
    self::class
);
CODE;
        $this->command->line($codeExample);
    }
}
