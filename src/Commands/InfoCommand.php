<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\InformationService;

/**
 * Unified Icon Information Command
 *
 * Single command for displaying information: packages, icons, status, languages, stats.
 * Merges functionality from IchavaStatusCommand.
 *
 * @example
 * php artisan ichava:info packages            # List all packages
 * php artisan ichava:info icons               # List all icons
 * php artisan ichava:info status              # Show lifecycle status
 * php artisan ichava:info languages           # List FTS languages
 * php artisan ichava:info discover            # Discover packages
 * php artisan ichava:info stats               # Show statistics
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
final class InfoCommand extends BaseCommand
{
    protected $signature = 'ichava:info
                            {type? : Type: packages, icons, status, languages, discover, stats}
                            {--search= : Search filter}
                            {--package= : Filter by package}
                            {--limit=50 : Limit results}
                            {--format=table : Output format: table, json, csv}
                            {--export= : Export to file}
                            {--reset : Reset lifecycle state (for status type)}
                            {--force : Force operation without confirmation}';

    protected $description = 'Display Ichava information (packages, icons, status, stats)';

    protected array $validTypes = ['packages', 'icons', 'status', 'languages', 'discover', 'stats'];

    public function __construct(
        protected InformationService $infoService,
        protected IchavaLogger $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = $this->argument('type');

        // If no type provided, prompt user to select
        if (empty($type)) {
            $type = select(
                label: 'What information would you like to view?',
                options: [
                    'stats'     => 'Stats - Overview statistics',
                    'packages'  => 'Packages - List registered icon packages',
                    'icons'     => 'Icons - Browse icons',
                    'status'    => 'Status - Lifecycle and health status',
                    'languages' => 'Languages - PostgreSQL FTS languages',
                    'discover'  => 'Discover - Find unregistered packages',
                ],
                default: 'stats',
                hint: 'Select what to display',
            );
        }

        return match ($type) {
            'packages'  => $this->handlePackages(),
            'icons'     => $this->handleIcons(),
            'status'    => $this->handleStatus(),
            'languages' => $this->handleLanguages(),
            'discover'  => $this->handleDiscover(),
            'stats'     => $this->handleStats(),
            default     => $this->handleInvalidType($type, $this->validTypes),
        };
    }

    /**
     * List all packages
     */
    protected function handlePackages(): int
    {
        intro('📦 Registered Icon Packages');

        $packages = spin(
            callback: fn () => $this->infoService->getPackages(),
            message: 'Loading packages...',
        );

        if (empty($packages)) {
            warning('No packages registered.');
            note('💡 Register packages in your service provider using IchavaRegistrar');

            return self::SUCCESS;
        }

        // Filter by search
        $searchTerm = $this->option('search');
        if (empty($searchTerm) && ! $this->isQuiet()) {
            $searchTerm = text(
                label: 'Search packages (leave empty to show all)',
                placeholder: 'e.g., fontawesome',
                hint: 'Filter packages by name',
            );
        }

        if (! empty($searchTerm)) {
            $packages = $this->infoService->filterBySearch($packages, $searchTerm, ['name']);
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($packages, JSON_PRETTY_PRINT));
        } else {
            $this->displayPackagesTable($packages);
        }

        // Export if requested
        $this->handleExport($packages);

        return self::SUCCESS;
    }

    /**
     * List all icons
     */
    protected function handleIcons(): int
    {
        intro('🎨 Icon Browser');

        // Get search filter
        $searchTerm = $this->option('search');
        if (empty($searchTerm) && ! $this->isQuiet()) {
            $searchTerm = text(
                label: 'Search icons',
                placeholder: 'e.g., arrow, user, check',
                hint: 'Filter icons by name',
            );
        }

        $filters = [
            'package' => $this->option('package'),
            'search'  => $searchTerm,
            'limit'   => (int) $this->option('limit'),
        ];

        $icons = spin(
            callback: fn () => $this->infoService->getIcons($filters),
            message: 'Loading icons...',
        );

        if (empty($icons)) {
            warning('No icons found.');

            return self::SUCCESS;
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($icons, JSON_PRETTY_PRINT));
        } else {
            $this->displayIconsTable($icons);
            note('Showing ' . count($icons) . ' icons. Use --limit to show more.');
        }

        // Export if requested
        $this->handleExport($icons);

        return self::SUCCESS;
    }

    /**
     * Display lifecycle status (merged from IchavaStatusCommand)
     */
    protected function handleStatus(): int
    {
        intro('🔍 Ichava Lifecycle Status');

        // Reset if requested
        if ($this->option('reset')) {
            spin(
                callback: fn () => $this->infoService->resetLifecycle(),
                message: 'Resetting lifecycle state...',
            );
            $this->success('Lifecycle state reset');
        }

        $status = spin(
            callback: fn () => $this->infoService->getLifecycleStatus(),
            message: 'Checking status...',
        );

        // Status checks table
        table(
            headers: ['Check', 'Status'],
            rows: [
                ['Migrations', $status['checks']['migrations'] ? '✅ OK' : '❌ NOT READY'],
                ['Seeds', $status['checks']['seeds'] ? '✅ OK' : '❌ NOT READY'],
                ['Cache', $status['checks']['cache'] ? '✅ OK' : '❌ NOT READY'],
            ],
        );

        // Current stage
        $stageColor = match ($status['stage']) {
            'READY'    => 'green',
            'SEEDED'   => 'yellow',
            'MIGRATED' => 'yellow',
            default    => 'red',
        };

        $this->line("  <fg=white>Current Stage:</fg=white> <fg={$stageColor}>{$status['stage']}</fg={$stageColor}>");
        $this->line('  <fg=white>System Ready:</fg=white>  ' . ($status['is_ready'] ? '<fg=green>YES</fg=green>' : '<fg=red>NO</fg=red>'));

        // Icon count
        if ($status['icon_count'] !== null) {
            $iconCount = is_numeric($status['icon_count']) ? $this->formatNumber($status['icon_count']) : $status['icon_count'];
            $this->line("  <fg=white>Icon Count:</fg=white>   <fg=cyan>{$iconCount}</fg=cyan>");
        }

        $this->newLine();

        // Next steps
        if (! $status['is_ready'] && ! empty($status['next_steps'])) {
            warning('Next Steps:');
            foreach ($status['next_steps'] as $index => $step) {
                $num = $index + 1;
                $this->line("  {$num}. <fg=cyan>{$step}</fg=cyan>");
            }
        } elseif ($status['is_ready']) {
            outro('✅ Ichava is fully operational!');
        }

        return self::SUCCESS;
    }

    /**
     * List PostgreSQL FTS languages
     */
    protected function handleLanguages(): int
    {
        intro('🌍 PostgreSQL FTS Languages');

        $languages = spin(
            callback: fn () => $this->infoService->getFtsLanguages(),
            message: 'Loading languages...',
        );

        if (empty($languages)) {
            warning('No FTS languages found or not using PostgreSQL.');

            return self::SUCCESS;
        }

        table(
            headers: ['Language', 'Owner', 'Description'],
            rows: array_map(fn ($lang) => [
                $lang['language'],
                $lang['owner'],
                $lang['description'] ?? '',
            ], $languages),
        );

        $currentLang = $this->infoService->getCurrentFtsLanguage();
        info("📌 Current language: {$currentLang}");
        note('💡 Configure in config/ichava.php or ICHAVA_SEARCH_LANGUAGE env var');

        return self::SUCCESS;
    }

    /**
     * Discover packages from filesystem
     */
    protected function handleDiscover(): int
    {
        intro('🔍 Discovering Icon Packages');

        $discovered = spin(
            callback: fn () => $this->infoService->discoverPackages(),
            message: 'Scanning filesystem...',
        );

        if (empty($discovered)) {
            warning('No packages discovered.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($discovered as $name => $data) {
            $rows[] = [
                $name,
                $this->truncatePath($data['path']),
                $data['registered'] ? '✅ Yes' : '❌ No',
            ];
        }

        table(
            headers: ['Package', 'Path', 'Registered'],
            rows: $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Display statistics
     */
    protected function handleStats(): int
    {
        intro('📊 Ichava Statistics');

        $stats = spin(
            callback: fn () => $this->infoService->getStatistics(),
            message: 'Gathering statistics...',
        );

        table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Total Icons', $this->formatNumber($stats['icons'])],
                ['Total Packages', $this->formatNumber($stats['packages'])],
                ['Categories', $this->formatNumber($stats['categories'])],
                ['Variants', $this->formatNumber($stats['variants'])],
                ['Database Size', $stats['database_size'] ?? 'N/A'],
                ['Cache Driver', $stats['cache_driver'] ?? 'N/A'],
            ],
        );

        // Top packages by icon count
        $topPackages = spin(
            callback: fn () => $this->infoService->getTopPackages(5),
            message: 'Loading top packages...',
        );

        if (! empty($topPackages)) {
            $this->newLine();
            info('🏆 Top 5 Packages by Icon Count:');

            table(
                headers: ['Package', 'Icon Count'],
                rows: array_map(fn ($pkg) => [
                    $pkg['package'],
                    $this->formatNumber($pkg['count']),
                ], $topPackages),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Display packages table
     */
    protected function displayPackagesTable(array $packages): void
    {
        $rows = [];
        foreach ($packages as $name => $data) {
            $rows[] = [
                $data['name'] ?? $name,
                $this->truncatePath($data['base_path'] ?? '-'),
                (string) ($data['icon_count'] ?? '-'),
                $this->formatStatus($data['status'] ?? 'active'),
            ];
        }

        table(
            headers: ['Package', 'Path', 'Icons', 'Status'],
            rows: $rows,
        );
    }

    /**
     * Display icons table
     */
    protected function displayIconsTable(array $icons): void
    {
        $rows = array_map(fn ($icon) => [
            $icon['name'] ?? '-',
            $icon['package'] ?? '-',
            $this->truncatePath($icon['path'] ?? '-'),
        ], $icons);

        table(
            headers: ['Name', 'Package', 'Path'],
            rows: $rows,
        );
    }

    /**
     * Handle export option
     */
    protected function handleExport(array $data): void
    {
        $exportPath = $this->option('export');

        if (! $exportPath) {
            return;
        }

        $format = $this->option('format');

        if ($format === 'csv') {
            $this->exportToCsv($data, $exportPath);
        } else {
            $this->exportToJson($data, $exportPath);
        }
    }
}
