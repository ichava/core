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

use Simtabi\Laranail\Ichava\Support\Coerce;
use Simtabi\Laranail\Ichava\Data\StatusData;
use Simtabi\Laranail\Console\Tools\Support\Status;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Console\Tools\Widgets\CheckList;
use Simtabi\Laranail\Ichava\Support\Auth\IchavaGates;
use Simtabi\Laranail\Console\Tools\Widgets\StatusBadge;
use Simtabi\Laranail\Ichava\Services\InformationService;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Simtabi\Laranail\Ichava\Support\IconPathStructureDetector;

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
                            {type? : Type: packages, icons, status, doctor, languages, discover, stats}
                            {--search= : Search filter}
                            {--package= : Filter by package}
                            {--limit=50 : Limit results}
                            {--format=table : Output format: table, json, csv}
                            {--export= : Export to file}
                            {--reset : Reset lifecycle state (for status type)}
                            {--force : Force operation without confirmation}';

    protected $description = 'Display Ichava information (packages, icons, status, stats)';

    /**
     * @var list<string>
     */
    protected array $validTypes = ['packages', 'icons', 'status', 'doctor', 'languages', 'discover', 'stats'];

    public function __construct(
        protected InformationService $infoService,
        protected IchavaLogger $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $type = Coerce::string($this->argument('type'));

        // If no type provided, prompt user to select
        if (empty($type)) {
            $type = select(
                label: __('ichava/ichava-core::commands.info.select'),
                options: [
                    'stats'     => __('ichava/ichava-core::commands.info.options.stats'),
                    'packages'  => __('ichava/ichava-core::commands.info.options.packages'),
                    'icons'     => __('ichava/ichava-core::commands.info.options.icons'),
                    'status'    => __('ichava/ichava-core::commands.info.options.status'),
                    'doctor'    => __('ichava/ichava-core::commands.info.options.doctor'),
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
            'doctor'    => $this->handleDoctor(),
            'languages' => $this->handleLanguages(),
            'discover'  => $this->handleDiscover(),
            'stats'     => $this->handleStats(),
            default     => $this->handleInvalidType((string) $type, $this->validTypes),
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
        $searchTerm = Coerce::string($this->option('search'));
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
            $this->line(json_encode($packages, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
            $this->line(json_encode($icons, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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

        $checks = is_array($status['checks'] ?? null) ? $status['checks'] : [];

        $this->line(
            CheckList::make()
                ->check(__('ichava/ichava-core::commands.info.status.migrations'), (bool) ($checks['migrations'] ?? false))
                ->check(__('ichava/ichava-core::commands.info.status.seeds'), (bool) ($checks['seeds'] ?? false))
                ->check(__('ichava/ichava-core::commands.info.status.cache'), (bool) ($checks['cache'] ?? false))
                ->render(),
        );
        $this->newLine();

        $this->detail(__('ichava/ichava-core::commands.info.status.stage', [
            'stage' => StatusBadge::fromMap(self::STAGE_MAP, Coerce::stringOrNull($status['stage']), valueAsLabel: true)->withoutSymbol()->render(),
        ]));
        $this->detail(__('ichava/ichava-core::commands.info.status.ready', [
            'ready' => StatusBadge::of((bool) $status['is_ready'])
                ->label($status['is_ready'] ? __('ichava/ichava-core::commands.common.yes') : __('ichava/ichava-core::commands.common.no'))
                ->withoutSymbol()
                ->render(),
        ]));

        // Icon count
        if ($status['icon_count'] !== null) {
            $iconCount = $this->formatCount($status['icon_count']);
            $this->detail(__('ichava/ichava-core::commands.info.status.icon_count', ['count' => $iconCount]));
        }

        $this->newLine();

        // Next steps
        if (! $status['is_ready'] && ! empty($status['next_steps']) && is_array($status['next_steps'])) {
            warning(__('ichava/ichava-core::commands.info.status.next_steps'));
            foreach (array_values($status['next_steps']) as $index => $step) {
                $this->detail(($index + 1) . '. ' . Coerce::string($step));
            }
        } elseif ($status['is_ready']) {
            outro(__('ichava/ichava-core::commands.info.status.operational'));
        }

        $this->displayHealth($this->infoService->status());

        return self::SUCCESS;
    }

    /**
     * The health block `status` ends with: what StatusData knows beyond the
     * lifecycle stage.
     */
    protected function displayHealth(StatusData $health): void
    {
        $never = __('ichava/ichava-core::commands.info.health.never');
        $logging = $health->logging;

        $this->newLine();
        $this->line('<options=bold>' . __('ichava/ichava-core::commands.info.health.title') . '</>');
        $this->detail(__('ichava/ichava-core::commands.info.health.packages', ['count' => $health->packages]));
        $this->detail(__('ichava/ichava-core::commands.info.health.conflicts', ['count' => count($health->conflicts, COUNT_RECURSIVE) - count($health->conflicts)]));
        $this->detail(__('ichava/ichava-core::commands.info.health.orphans', ['list' => $health->orphans === [] ? '-' : implode(', ', $health->orphans)]));
        $this->detail(__('ichava/ichava-core::commands.info.health.unregistered', ['count' => $health->discovery['total_unregistered']]));
        $this->detail(__('ichava/ichava-core::commands.info.health.last_scan', ['time' => $health->lastScan ?? $never]));
        $this->detail(__('ichava/ichava-core::commands.info.health.last_rebuild', ['time' => Coerce::stringOrNull($health->lastRebuild['at'] ?? null) ?? $never]));
        $this->detail(__('ichava/ichava-core::commands.info.health.logging', [
            'state'   => $logging['enabled'] ? __('ichava/ichava-core::commands.common.yes') : __('ichava/ichava-core::commands.common.no'),
            'channel' => $logging['channel'],
            'seeding' => $logging['seeding_channel'],
        ]));
        $this->detail(__('ichava/ichava-core::commands.info.health.render', ['level' => $health->optimizationLevel, 'fingerprint' => $health->renderFingerprint]));
    }

    /**
     * A pass/fail check of what a working install needs, ending non-zero
     * when something is broken. Warnings (an orphan, an undefined Gate) do
     * not fail it.
     */
    protected function handleDoctor(): int
    {
        intro(__('ichava/ichava-core::commands.info.doctor.intro'));

        $health = $this->infoService->status();
        $checks = is_array($health->lifecycle['checks'] ?? null) ? $health->lifecycle['checks'] : [];
        $queueConnection = Coerce::stringOrNull(config('ichava.ichava-core.queue.connection')) ?? Coerce::string(config('queue.default'));
        $queueOk = is_array(config("queue.connections.{$queueConnection}"));
        $gateDefined = app()->bound(GateContract::class) && app(GateContract::class)->has(IchavaGates::ADMIN);
        $routes = app()->bound('router')
            ? array_filter(array_keys(app('router')->getRoutes()->getRoutesByName()), static fn (string $name): bool => str_starts_with($name, 'ichava.'))
            : [];

        $list = CheckList::make()
            ->check(__('ichava/ichava-core::commands.info.doctor.packages'), $health->packages > 0, (string) $health->packages)
            ->check(__('ichava/ichava-core::commands.info.status.migrations'), (bool) ($checks['migrations'] ?? false))
            ->check(__('ichava/ichava-core::commands.info.status.seeds'), (bool) ($checks['seeds'] ?? false))
            ->check(__('ichava/ichava-core::commands.info.status.cache'), (bool) ($checks['cache'] ?? false))
            ->check(__('ichava/ichava-core::commands.info.doctor.queue'), $queueOk, $queueConnection)
            ->check(__('ichava/ichava-core::commands.info.doctor.auth'), app()->bound('auth') ? true : Status::Warning, Coerce::stringOrNull(config('ichava.ichava-core.auth.guard')) ?? __('ichava/ichava-core::commands.info.doctor.default_guard'))
            ->check(__('ichava/ichava-core::commands.info.doctor.gates'), $gateDefined ? true : Status::Warning)
            ->check(__('ichava/ichava-core::commands.info.doctor.routes'), $routes !== [] ? true : Status::Warning, (string) count($routes))
            ->check(__('ichava/ichava-core::commands.info.doctor.orphans'), $health->orphans === [] ? true : Status::Warning, $health->orphans === [] ? null : implode(', ', $health->orphans));

        $this->line($list->render());

        $failed = $health->packages === 0 || ! ($checks['migrations'] ?? false) || ! $queueOk;

        $failed
            ? warning(__('ichava/ichava-core::commands.info.doctor.failed'))
            : outro(__('ichava/ichava-core::commands.info.doctor.passed'));

        return $failed ? self::FAILURE : self::SUCCESS;
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
            rows: array_values(array_map(fn ($lang) => [
                Coerce::string($lang['language']),
                Coerce::string($lang['owner']),
                Coerce::string($lang['description'] ?? ''),
            ], $languages)),
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
                // CLI only: the structure info carries absolute paths, which
                // never belong in an HTTP payload.
                IconPathStructureDetector::getStructureInfo($data['path'])['type'],
                StatusBadge::of((bool) $data['registered'])->label($data['registered'] ? $yes : $no)->render(),
            ];
        }

        table(
            headers: [
                __('ichava/ichava-core::commands.info.table.package'),
                __('ichava/ichava-core::commands.info.table.path'),
                __('ichava/ichava-core::commands.info.table.layout'),
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
                rows: array_values(array_map(fn ($pkg) => [
                    Coerce::string(is_array($pkg) ? $pkg['package'] ?? '' : ''),
                    $this->formatCount(is_array($pkg) ? $pkg['count'] ?? 0 : 0),
                ], $topPackages)),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Display packages table
     *
     * @param array<array-key, mixed> $packages
     */
    protected function displayPackagesTable(array $packages): void
    {
        $rows = [];
        foreach ($packages as $name => $data) {
            $data = is_array($data) ? $data : [];

            $rows[] = [
                Coerce::string($data['name'] ?? $name),
                $this->truncatePath(Coerce::string($data['base_path'] ?? '-')),
                Coerce::string($data['icon_count'] ?? '-'),
                $this->formatStatus(Coerce::string($data['status'] ?? 'active')),
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
     *
     * @param array<array-key, mixed> $icons
     */
    protected function displayIconsTable(array $icons): void
    {
        $rows = array_values(array_map(fn ($icon) => [
            Coerce::string(is_array($icon) ? $icon['name'] ?? '-' : '-'),
            Coerce::string(is_array($icon) ? $icon['package'] ?? '-' : '-'),
            $this->truncatePath(Coerce::string(is_array($icon) ? $icon['path'] ?? '-' : '-')),
        ], $icons));

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
     *
     * @param array<array-key, mixed> $data
     */
    protected function handleExport(array $data): void
    {
        $exportPath = Coerce::string($this->option('export'));

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

    /**
     * A count for display: numbers through formatNumber(), whether the driver
     * returned an int or a numeric string, and anything else (an error
     * marker) as it is.
     */
    private function formatCount(mixed $count): string
    {
        if (is_int($count) || is_float($count)) {
            return $this->formatNumber($count);
        }

        return is_numeric($count) ? $this->formatNumber((float) $count) : Coerce::string($count);
    }
}
