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
use Illuminate\Bus\PendingBatch;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

use Illuminate\Support\Facades\File;

use function Laravel\Prompts\warning;

use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Jobs\SeedIconsJob;
use Simtabi\Laranail\Ichava\Support\CommandName;
use Simtabi\Laranail\Console\Tools\Support\Status;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Commands\DatabaseCommand;
use Simtabi\Laranail\Console\Tools\Widgets\MetricTable;
use Simtabi\Laranail\Console\Tools\Widgets\StatusBadge;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Package\Tools\Services\Database\ChunkedBatchDispatcher;

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
     * The SeederRunTracker key a package's seeding progress is written under,
     * and read back by `ichava::ichava-core.job-status`.
     */
    public static function trackingKey(string $packageName): string
    {
        return "ichava:{$packageName}";
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

        $queue = (string) config('ichava.ichava-core.queue.name', 'ichava-icons');

        // Batch callbacks are serialized onto the queue, so none of them may
        // capture $this (the seeder holds the console command). They resolve
        // IchavaLogger from the container when they run instead.
        $batch = ChunkedBatchDispatcher::make("Seed Icons: {$packageName}")
            ->items($files)
            ->chunk($chunkSize)
            ->job(static fn (array $chunk, int $index, int $total): SeedIconsJob => new SeedIconsJob(
                packageName: $packageName,
                files: $chunk,
                jobIndex: $index,
                totalJobs: $total,
                force: $force,
            ))
            ->queue($queue)
            ->track(self::trackingKey($packageName))
            ->configure(static fn (PendingBatch $pending): PendingBatch => $pending
                ->before(static function (Batch $b) use ($packageName): void {
                    app(IchavaLogger::class)->seedingInfo('🌱 Icon seeding started', [
                        'package'    => $packageName,
                        'batch_id'   => $b->id,
                        'total_jobs' => $b->totalJobs,
                    ]);
                })
                ->progress(static function (Batch $b) use ($packageName, $onProgress): void {
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
                ->then(static function (Batch $b) use ($packageName, $totalFiles): void {
                    app(IchavaLogger::class)->seedingInfo('✅ Icon seeding completed', [
                        'package'        => $packageName,
                        'batch_id'       => $b->id,
                        'total_files'    => $totalFiles,
                        'processed_jobs' => $b->processedJobs(),
                    ]);
                })
                ->catch(static function (Batch $b, Throwable $e) use ($packageName): void {
                    app(IchavaLogger::class)->seedingError('❌ Icon seeding failed', [
                        'package'     => $packageName,
                        'batch_id'    => $b->id,
                        'failed_jobs' => $b->failedJobs,
                        'error'       => $e->getMessage(),
                    ]);
                })
                ->finally(static function (Batch $b) use ($packageName): void {
                    app(IchavaLogger::class)->seedingInfo('🏁 Icon seeding finished', [
                        'package'  => $packageName,
                        'batch_id' => $b->id,
                        'success'  => ! $b->hasFailures(),
                    ]);
                }))
            ->dispatch();

        if ($batch === null) {
            return null;
        }

        $this->logger->info('🚀 Seeding jobs dispatched', [
            'package'    => $packageName,
            'batch_id'   => $batch->id,
            'total_jobs' => $batch->totalJobs,
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

        $run = ChunkedBatchDispatcher::make("Seed Icons: {$packageName}")
            ->items($files)
            ->chunk($chunkSize)
            ->job(static fn (array $chunk, int $index, int $total): SeedIconsJob => new SeedIconsJob(
                packageName: $packageName,
                files: $chunk,
                jobIndex: $index,
                totalJobs: $total,
                force: $force,
            ))
            ->track(self::trackingKey($packageName))
            ->runInline();

        return [
            'total_files' => $totalFiles,
            'processed'   => $run->processed,
            'jobs'        => $run->chunks,
            'errors'      => array_map(
                static fn (array $error): array => ['job' => $error['chunk'], 'error' => $error['message']],
                $run->errors,
            ),
            'force' => $force,
        ];
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
        $this->command->info(__('ichava/ichava-core::commands.seeder.discovering'));
        $this->command->newLine();
        $this->logger->seedingInfo('🔍 Starting package discovery');

        $packages = $this->discoverPackages();

        if (empty($packages)) {
            $this->displayNoPackagesMessage();

            return;
        }

        $this->command->info(__('ichava/ichava-core::commands.seeder.found', ['count' => count($packages)]));
        $this->command->newLine();
        $this->logger->seedingInfo('Found ' . count($packages) . ' registered packages');

        $chunkSize = (int) config('ichava.ichava-core.database.batch_size', self::DEFAULT_CHUNK_SIZE);

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

                $this->command->line('    <fg=gray>' . __('ichava/ichava-core::commands.seeder.icons_found', ['count' => $iconCount]) . '</>');
                $this->logger->seedingInfo("🎨 Icons found for {$packageName}: {$iconCount}");

                if ($iconCount === 0) {
                    $this->command->line('    <fg=yellow>' . __('ichava/ichava-core::commands.seeder.no_icons') . '</>');
                    $packageStats['status'] = 'empty';
                    $stats['packages_success']++;
                    $stats['package_details'][] = $packageStats;

                    continue;
                }

                // Step 3: Seed icons
                $forceMsg = $this->forceUpdate ? __('ichava/ichava-core::commands.seeder.force_suffix') : '';

                if ($this->syncMode || ! config('ichava.ichava-core.database.use_queue', true)) {
                    $this->command->line('    <fg=yellow>' . __('ichava/ichava-core::commands.seeder.seeding_sync', ['force' => $forceMsg, 'size' => $chunkSize]) . '</>');

                    $result = $this->seedSync($packageName, $packageData['svg_path'], $chunkSize, $this->forceUpdate);

                    if (isset($result['error'])) {
                        throw new IchavaException($result['error']);
                    }

                    $packageStats['jobs'] = $result['jobs'];
                    $packageStats['status'] = 'synced';
                    $this->command->line('    <fg=green>' . __('ichava/ichava-core::commands.seeder.seeded_sync', ['processed' => $result['processed'], 'chunks' => $result['jobs']]) . '</>');
                    $this->logger->seedingInfo("✅ Icons seeded synchronously for {$packageName}", $result);

                } else {
                    $this->command->line('    <fg=yellow>' . __('ichava/ichava-core::commands.seeder.dispatching', ['force' => $forceMsg, 'size' => $chunkSize]) . '</>');

                    $batch = $this->seed($packageName, $packageData['svg_path'], $chunkSize, null, $this->forceUpdate);

                    if ($batch) {
                        $totalJobs = $batch->totalJobs;
                        $packageStats['jobs'] = $totalJobs;
                        $packageStats['status'] = 'queued';
                        $packageStats['batch_id'] = $batch->id;
                        $stats['jobs_dispatched'] += $totalJobs;
                        $this->command->line('    <fg=green>' . __('ichava/ichava-core::commands.seeder.dispatched', ['jobs' => $totalJobs, 'batch' => $batch->id]) . '</>');
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
                $this->command->error('    ' . __('ichava/ichava-core::commands.seeder.failed', ['message' => $e->getMessage()]));
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
        $this->command->line('    <fg=gray>' . __('ichava/ichava-core::commands.seeder.seeding_terms') . '</>');

        try {
            $termSeeder = new IconTermsSeeder;
            $termSeeder->setCommand($this->command);
            $termSeeder->setContainer(app());
            $termSeeder->seedSinglePackage($packageName, $packageData);
            $this->command->line('    <fg=green>' . __('ichava/ichava-core::commands.seeder.terms_seeded') . '</>');
        } catch (IchavaException $e) {
            $this->command->warn('    ' . __('ichava/ichava-core::commands.seeder.terms_failed', ['message' => $e->getMessage()]));
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
            $this->command->error(__('ichava/ichava-core::commands.seeder.table_missing'));
            $this->command->warn(__('ichava/ichava-core::commands.seeder.table_missing_hint'));

            return false;
        }

        return true;
    }

    protected function displayHeader(): void
    {
        $this->command->line('<options=bold;fg=cyan>' . __('ichava/ichava-core::commands.seeder.header') . '</>');
        $this->command->newLine();
    }

    protected function displayFooter(): void
    {
        $this->command->newLine();
        $this->command->line('<options=bold;fg=green>' . __('ichava/ichava-core::commands.seeder.completed') . '</>');

        if ($this->syncMode) {
            try {
                $totalIcons = Icon::count();
                info(__('ichava/ichava-core::commands.seeder.total_in_database', ['count' => number_format($totalIcons)]));
            } catch (Exception $e) {
                warning(__('ichava/ichava-core::commands.seeder.database_unreadable', ['command' => CommandName::of(DatabaseCommand::class)]));
            }
        }
    }

    protected function displayStats(array $stats): void
    {
        $this->command->newLine();

        $this->command->line('<options=bold;fg=cyan>' . __('ichava/ichava-core::commands.seeder.summary') . '</>');

        MetricTable::make()
            ->headers(__('ichava/ichava-core::commands.seeder.setting'))
            ->metrics([
                [__('ichava/ichava-core::commands.seeder.mode'), $stats['mode'] === 'sync' ? __('ichava/ichava-core::commands.seeder.mode_sync') : __('ichava/ichava-core::commands.seeder.mode_queue')],
                [__('ichava/ichava-core::commands.seeder.force_update'), $stats['force_update']],
                [__('ichava/ichava-core::commands.seeder.chunk_size'), $stats['chunk_size']],
                [__('ichava/ichava-core::commands.seeder.total_icons'), $stats['total_icons']],
                [__('ichava/ichava-core::commands.seeder.total_jobs'), $stats['jobs_dispatched']],
            ])
            ->render($this->command->getOutput());

        $this->command->newLine();
        info(__('ichava/ichava-core::commands.seeder.packages', ['count' => $stats['packages_total']]));

        $statuses = [
            'synced' => Status::Success,
            'queued' => Status::Pending,
            'empty'  => Status::Inactive,
            'failed' => Status::Failed,
        ];

        $labels = [
            'synced' => __('ichava/ichava-core::commands.seeder.status.synced'),
            'queued' => __('ichava/ichava-core::commands.seeder.status.queued'),
            'empty'  => __('ichava/ichava-core::commands.seeder.status.empty'),
            'failed' => __('ichava/ichava-core::commands.seeder.status.failed'),
        ];

        $packageRows = [];
        foreach ($stats['package_details'] as $pkg) {
            $packageRows[] = [
                $pkg['name'],
                number_format($pkg['icons']),
                (string) $pkg['jobs'],
                StatusBadge::fromMap($statuses, $pkg['status'])->label($labels[$pkg['status']] ?? null)->render(),
            ];
        }

        table(
            headers: [
                __('ichava/ichava-core::commands.seeder.table.package'),
                __('ichava/ichava-core::commands.seeder.table.icons'),
                __('ichava/ichava-core::commands.seeder.table.jobs'),
                __('ichava/ichava-core::commands.seeder.table.status'),
            ],
            rows: $packageRows,
        );

        $this->command->newLine();

        // Result
        if ($stats['packages_failed'] > 0) {
            warning(__('ichava/ichava-core::commands.seeder.packages_failed', ['count' => $stats['packages_failed']]));
        } else {
            $this->command->line('<options=bold;fg=green>' . __('ichava/ichava-core::commands.seeder.packages_succeeded', ['count' => $stats['packages_success']]) . '</>');
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
        $queueName = config('ichava.ichava-core.queue.name', 'ichava-icons');

        $this->command->newLine();
        $this->command->line('<options=bold;fg=yellow>' . __('ichava/ichava-core::commands.seeder.processing_jobs', ['count' => $jobCount]) . '</>');
        $this->command->newLine();

        try {
            // Process all queued jobs and exit when done
            ChunkedBatchDispatcher::drain($queueName, $this->command->getOutput(), [
                '--memory'  => 512,
                '--timeout' => 300,
            ]);

            $this->command->newLine();
            $this->command->line('<options=bold;fg=green>' . __('ichava/ichava-core::commands.seeder.jobs_processed') . '</>');

        } catch (Throwable $e) {
            $this->command->line('<options=bold;fg=red>' . __('ichava/ichava-core::commands.seeder.queue_failed', ['message' => $e->getMessage()]) . '</>');
            $this->command->newLine();
            note(__('ichava/ichava-core::commands.seeder.queue_manual', ['queue' => $queueName]));
        }
    }

    protected function displayNoPackagesMessage(): void
    {
        warning(__('ichava/ichava-core::commands.seeder.no_packages'));
        $this->command->newLine();
        note(__('ichava/ichava-core::commands.seeder.no_packages_hint'));

        $codeExample = <<<'CODE'
IconRegistry::fromDirectory(
    $this->package->basePath('resources/assets/svg'),
    self::class
);
CODE;
        $this->command->line($codeExample);
    }
}
