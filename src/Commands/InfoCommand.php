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
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

use Simtabi\Laranail\Console\Tools\Support\Status;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Console\Tools\Widgets\CheckList;
use Simtabi\Laranail\Console\Tools\Widgets\StatusBadge;
use Simtabi\Laranail\Ichava\Services\InformationService;

/**
 * Unified Icon Information Command
 *
 * Single command for displaying information: packages, icons, status, languages, stats.
 * Merges functionality from IchavaStatusCommand.
 *
 * @example
 * php artisan ichava::ichava-core.info packages            # List all packages
 * php artisan ichava::ichava-core.info icons               # List all icons
 * php artisan ichava::ichava-core.info status              # Show lifecycle status
 * php artisan ichava::ichava-core.info languages           # List FTS languages
 * php artisan ichava::ichava-core.info discover            # Discover packages
 * php artisan ichava::ichava-core.info stats               # Show statistics
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
final class InfoCommand extends BaseCommand
{
    /**
     * Lifecycle stages on the shared status vocabulary: ready is done, the
     * two part-way stages are warnings, and nothing installed is a failure.
     *
     * @var array<string, Status>
     */
    private const array STAGE_MAP = [
        'READY'         => Status::Success,
        'SEEDED'        => Status::Warning,
        'MIGRATED'      => Status::Warning,
        'UNINITIALIZED' => Status::Failed,
    ];

    protected $signature = 'ichava::ichava-core.info
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
                label: __('ichava/ichava-core::commands.info.select'),
                options: [
                    'stats'     => __('ichava/ichava-core::commands.info.options.stats'),
                    'packages'  => __('ichava/ichava-core::commands.info.options.packages'),
                    'icons'     => __('ichava/ichava-core::commands.info.options.icons'),
                    'status'    => __('ichava/ichava-core::commands.info.options.status'),
                    'languages' => __('ichava/ichava-core::commands.info.options.languages'),
                    'discover'  => __('ichava/ichava-core::commands.info.options.discover'),
                ],
                default: 'stats',
                hint: __('ichava/ichava-core::commands.info.select_hint'),
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
        intro(__('ichava/ichava-core::commands.info.packages.intro'));

        $packages = spin(
            callback: fn () => $this->infoService->getPackages(),
            message: __('ichava/ichava-core::commands.info.packages.loading'),
        );

        if (empty($packages)) {
            warning(__('ichava/ichava-core::commands.info.packages.none'));
            $this->tip(__('ichava/ichava-core::commands.info.packages.none_hint'));

            return self::SUCCESS;
        }

        // Filter by search
        $searchTerm = $this->option('search');
        if (empty($searchTerm) && ! $this->isQuiet()) {
            $searchTerm = text(
                label: __('ichava/ichava-core::commands.info.packages.search'),
                placeholder: __('ichava/ichava-core::commands.info.packages.search_placeholder'),
                hint: __('ichava/ichava-core::commands.info.packages.search_hint'),
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
        intro(__('ichava/ichava-core::commands.info.icons.intro'));

        // Get search filter
        $searchTerm = $this->option('search');
        if (empty($searchTerm) && ! $this->isQuiet()) {
            $searchTerm = text(
                label: __('ichava/ichava-core::commands.info.icons.search'),
                placeholder: __('ichava/ichava-core::commands.info.icons.search_placeholder'),
                hint: __('ichava/ichava-core::commands.info.icons.search_hint'),
            );
        }

        $filters = [
            'package' => $this->option('package'),
            'search'  => $searchTerm,
            'limit'   => (int) $this->option('limit'),
        ];

        $icons = spin(
            callback: fn () => $this->infoService->getIcons($filters),
            message: __('ichava/ichava-core::commands.info.icons.loading'),
        );

        if (empty($icons)) {
            warning(__('ichava/ichava-core::commands.info.icons.none'));

            return self::SUCCESS;
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($icons, JSON_PRETTY_PRINT));
        } else {
            $this->displayIconsTable($icons);
            note(__('ichava/ichava-core::commands.info.icons.showing', ['count' => count($icons)]));
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
        intro(__('ichava/ichava-core::commands.info.status.intro'));

        // Reset if requested
        if ($this->option('reset')) {
            spin(
                callback: fn () => $this->infoService->resetLifecycle(),
                message: __('ichava/ichava-core::commands.info.status.resetting'),
            );
            $this->success(__('ichava/ichava-core::commands.info.status.reset'));
        }

        $status = spin(
            callback: fn () => $this->infoService->getLifecycleStatus(),
            message: __('ichava/ichava-core::commands.info.status.checking'),
        );

        $this->line(
            CheckList::make()
                ->check(__('ichava/ichava-core::commands.info.status.migrations'), (bool) $status['checks']['migrations'])
                ->check(__('ichava/ichava-core::commands.info.status.seeds'), (bool) $status['checks']['seeds'])
                ->check(__('ichava/ichava-core::commands.info.status.cache'), (bool) $status['checks']['cache'])
                ->render(),
        );
        $this->newLine();

        $this->detail(__('ichava/ichava-core::commands.info.status.stage', [
            'stage' => StatusBadge::fromMap(self::STAGE_MAP, $status['stage'])->label($status['stage'])->withoutSymbol()->render(),
        ]));
        $this->detail(__('ichava/ichava-core::commands.info.status.ready', [
            'ready' => StatusBadge::of((bool) $status['is_ready'])
                ->label($status['is_ready'] ? __('ichava/ichava-core::commands.common.yes') : __('ichava/ichava-core::commands.common.no'))
                ->withoutSymbol()
                ->render(),
        ]));

        // Icon count
        if ($status['icon_count'] !== null) {
            $iconCount = is_numeric($status['icon_count']) ? $this->formatNumber($status['icon_count']) : $status['icon_count'];
            $this->detail(__('ichava/ichava-core::commands.info.status.icon_count', ['count' => $iconCount]));
        }

        $this->newLine();

        // Next steps
        if (! $status['is_ready'] && ! empty($status['next_steps'])) {
            warning(__('ichava/ichava-core::commands.info.status.next_steps'));
            foreach ($status['next_steps'] as $index => $step) {
                $this->detail(($index + 1) . ". {$step}");
            }
        } elseif ($status['is_ready']) {
            outro(__('ichava/ichava-core::commands.info.status.operational'));
        }

        return self::SUCCESS;
    }

    /**
     * List PostgreSQL FTS languages
     */
    protected function handleLanguages(): int
    {
        intro(__('ichava/ichava-core::commands.info.languages.intro'));

        $languages = spin(
            callback: fn () => $this->infoService->getFtsLanguages(),
            message: __('ichava/ichava-core::commands.info.languages.loading'),
        );

        if (empty($languages)) {
            warning(__('ichava/ichava-core::commands.info.languages.none'));

            return self::SUCCESS;
        }

        table(
            headers: [
                __('ichava/ichava-core::commands.info.languages.table.language'),
                __('ichava/ichava-core::commands.info.languages.table.owner'),
                __('ichava/ichava-core::commands.info.languages.table.description'),
            ],
            rows: array_map(fn ($lang) => [
                $lang['language'],
                $lang['owner'],
                $lang['description'] ?? '',
            ], $languages),
        );

        $currentLang = $this->infoService->getCurrentFtsLanguage();
        info(__('ichava/ichava-core::commands.info.languages.current', ['language' => $currentLang]));
        $this->tip(__('ichava/ichava-core::commands.info.languages.configure'));

        return self::SUCCESS;
    }

    /**
     * Discover packages from filesystem
     */
    protected function handleDiscover(): int
    {
        intro(__('ichava/ichava-core::commands.info.discover.intro'));

        $discovered = spin(
            callback: fn () => $this->infoService->discoverPackages(),
            message: __('ichava/ichava-core::commands.info.discover.scanning'),
        );

        if (empty($discovered)) {
            warning(__('ichava/ichava-core::commands.info.discover.none'));

            return self::SUCCESS;
        }

        $yes = __('ichava/ichava-core::commands.common.yes');
        $no = __('ichava/ichava-core::commands.common.no');

        $rows = [];
        foreach ($discovered as $name => $data) {
            $rows[] = [
                $name,
                $this->truncatePath($data['path']),
                StatusBadge::of((bool) $data['registered'])->label($data['registered'] ? $yes : $no)->render(),
            ];
        }

        table(
            headers: [
                __('ichava/ichava-core::commands.info.table.package'),
                __('ichava/ichava-core::commands.info.table.path'),
                __('ichava/ichava-core::commands.info.table.registered'),
            ],
            rows: $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Display statistics
     */
    protected function handleStats(): int
    {
        intro(__('ichava/ichava-core::commands.info.stats.intro'));

        $stats = spin(
            callback: fn () => $this->infoService->getStatistics(),
            message: __('ichava/ichava-core::commands.info.stats.gathering'),
        );

        $this->statisticsTable($stats)->render($this->output);

        // Top packages by icon count
        $topPackages = spin(
            callback: fn () => $this->infoService->getTopPackages(5),
            message: __('ichava/ichava-core::commands.info.stats.loading_top'),
        );

        if (! empty($topPackages)) {
            $this->newLine();
            info(__('ichava/ichava-core::commands.info.stats.top', ['count' => 5]));

            table(
                headers: [
                    __('ichava/ichava-core::commands.info.table.package'),
                    __('ichava/ichava-core::commands.info.table.icon_count'),
                ],
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
            headers: [
                __('ichava/ichava-core::commands.info.table.package'),
                __('ichava/ichava-core::commands.info.table.path'),
                __('ichava/ichava-core::commands.info.table.icons'),
                __('ichava/ichava-core::commands.info.table.status'),
            ],
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
            headers: [
                __('ichava/ichava-core::commands.info.table.name'),
                __('ichava/ichava-core::commands.info.table.package'),
                __('ichava/ichava-core::commands.info.table.path'),
            ],
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
