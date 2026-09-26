<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

use Symfony\Component\Process\Process;
use Simtabi\Laranail\Ichava\Support\CommandName;
use Simtabi\Laranail\Console\Tools\Support\Status;
use Simtabi\Laranail\Ichava\Services\IconSetCatalogService;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;

/**
 * Install an Ichava icon set.
 *
 * Lists the sets declared in `icon-sets.json` with their install and seed
 * state, resolves the set's latest release tag via Packagist, runs
 * `composer require` for it, then seeds its icons into the database.
 *
 * @example
 * php artisan ichava::ichava-core.install              # Pick from a list
 * php artisan ichava::ichava-core.install tabler       # Install by catalog key
 * php artisan ichava::ichava-core.install ichava/icon-sets-tabler --force
 * php artisan ichava::ichava-core.install tabler --no-seed
 */
final class InstallCommand extends BaseCommand
{
    protected $signature = 'ichava::ichava-core.install
                            {set? : Catalog key or composer package name}
                            {--force : Skip confirmations}
                            {--no-seed : Only composer require, skip database seeding}
                            {--sync : Force synchronous seeding (no queue)}
                            {--timeout=300 : Composer process timeout in seconds}';

    protected $description = 'Install an Ichava icon set (composer require + seed)';

    public function __construct(
        protected IconSetCatalogService $catalog,
        protected DatabaseOperationsService $database,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        intro(__('ichava/ichava-core::commands.install.intro'));

        try {
            $sets = $this->catalog->all();
        } catch (Throwable $e) {
            $this->failure(__('ichava/ichava-core::commands.install.catalog_failed', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }

        if ($sets === []) {
            warning(__('ichava/ichava-core::commands.install.catalog_empty'));

            return self::SUCCESS;
        }

        $set = $this->resolveSet($sets);

        if ($set === null) {
            return self::FAILURE;
        }

        $migrated = $this->ensureMigrated();

        if ($migrated === null) {
            return self::SUCCESS;
        }

        if ($migrated === false) {
            return self::FAILURE;
        }

        if ($set['installed'] === true) {
            warning($set['installed_version']
                ? __('ichava/ichava-core::commands.install.already_installed_version', ['title' => $set['title'], 'version' => $set['installed_version']])
                : __('ichava/ichava-core::commands.install.already_installed', ['title' => $set['title']]));
            note(__('ichava/ichava-core::commands.install.reinstall_note'));

            if (! $this->option('force') && ! confirm(
                label: __('ichava/ichava-core::commands.install.reinstall_confirm'),
                default: false,
            )) {
                warning(__('ichava/ichava-core::commands.common.cancelled'));

                return self::SUCCESS;
            }
        }

        $package = (string) $set['package'];

        $latest = $set['latest_version'] ?? null;

        if ($latest !== null) {
            $this->detail(__('ichava/ichava-core::commands.install.latest', ['version' => $latest]));
        } else {
            note(__('ichava/ichava-core::commands.install.latest_unknown'));
        }

        $target = $this->catalog->requireTarget($package);

        if (! $this->option('force') && ! confirm(
            label: __('ichava/ichava-core::commands.install.require_confirm', ['target' => $target]),
            default: true,
        )) {
            warning(__('ichava/ichava-core::commands.common.cancelled'));

            return self::SUCCESS;
        }

        if ($this->runComposerRequire($target) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->option('no-seed')) {
            note(__('ichava/ichava-core::commands.install.seed_skipped', ['command' => CommandName::of(DatabaseCommand::class), 'package' => $package]));
            outro(__('ichava/ichava-core::commands.install.required', ['title' => $set['title']]));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->detail(__('ichava/ichava-core::commands.install.seeding', ['package' => $package]));

        $seedExit = $this->reclaimingPrompts(fn () => $this->call(CommandName::of(DatabaseCommand::class), array_filter([
            'action'    => 'seed',
            '--package' => $package,
            '--sync'    => $this->option('sync') ?: null,
            '--force'   => $this->option('force') ?: null,
        ], fn ($value) => $value !== null)));

        if ($seedExit !== 0) {
            $this->failure(__('ichava/ichava-core::commands.install.seed_failed', ['package' => $package]));
            $this->tip(__('ichava/ichava-core::commands.install.seed_retry', ['command' => CommandName::of(DatabaseCommand::class), 'package' => $package]));

            return self::FAILURE;
        }

        outro(__('ichava/ichava-core::commands.install.installed', ['title' => $set['title']]));

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $sets
     *
     * @return array<string, mixed>|null
     */
    protected function resolveSet(array $sets): ?array
    {
        $requested = $this->argument('set');

        if (is_string($requested) && $requested !== '') {
            $found = $this->catalog->find($requested);

            if ($found === null) {
                $this->failure(__('ichava/ichava-core::commands.install.unknown_set', ['set' => $requested]));
                note(__('ichava/ichava-core::commands.install.available', ['sets' => implode(', ', array_map(
                    fn (array $set) => (string) $set['key'],
                    $sets,
                ))]));

                return null;
            }

            return $found;
        }

        $options = [];
        foreach ($sets as $set) {
            $version = is_string($set['installed_version'] ?? null) && $set['installed_version'] !== ''
                ? $set['installed_version']
                : null;

            $installed = $set['installed'] === true
                ? Status::Success->symbol() . ' ' . ($version !== null
                    ? __('ichava/ichava-core::commands.install.state.installed_version', ['version' => $version])
                    : __('ichava/ichava-core::commands.install.state.installed'))
                : Status::Pending->symbol() . ' ' . __('ichava/ichava-core::commands.install.state.not_installed');
            $seeded = ($set['seeded'] ?? null) === true
                ? Status::Success->symbol() . ' ' . (isset($set['seeded_count'])
                    ? __('ichava/ichava-core::commands.install.state.seeded_count', ['count' => $this->formatNumber((int) $set['seeded_count'])])
                    : __('ichava/ichava-core::commands.install.state.seeded'))
                : Status::Pending->symbol() . ' ' . __('ichava/ichava-core::commands.install.state.not_seeded');

            $options[(string) $set['key']] = __('ichava/ichava-core::commands.install.option', [
                'title'     => $set['title'],
                'count'     => $this->formatNumber((int) $set['icon_count']),
                'variants'  => implode(' + ', (array) $set['variants']),
                'installed' => $installed,
                'seeded'    => $seeded,
            ]);
        }

        $key = select(
            label: __('ichava/ichava-core::commands.install.select'),
            options: $options,
            hint: __('ichava/ichava-core::commands.install.select_hint'),
        );

        return $this->catalog->find($key);
    }

    /**
     * Ensure core tables exist before installing.
     *
     * @return bool|null true when ready, false when migration failed, null when the user declined
     */
    protected function ensureMigrated(): ?bool
    {
        if ($this->database->tablesExist()) {
            return true;
        }

        warning(__('ichava/ichava-core::commands.install.tables_missing'));
        note(__('ichava/ichava-core::commands.install.missing_tables', ['tables' => implode(', ', $this->database->getMissingTables())]));

        $runNow = $this->option('force') || confirm(
            label: __('ichava/ichava-core::commands.install.migrate_confirm'),
            default: true,
        );

        if (! $runNow) {
            warning(__('ichava/ichava-core::commands.common.cancelled'));
            note(__('ichava/ichava-core::commands.install.rerun', ['command' => $this->getName()]));

            return null;
        }

        $exit = $this->reclaimingPrompts(fn () => $this->call(CommandName::of(DatabaseCommand::class), ['action' => 'migrate']));

        if ($exit !== 0 || ! $this->database->tablesExist()) {
            $this->failure(__('ichava/ichava-core::commands.install.migrate_failed'));

            return false;
        }

        $this->success(__('ichava/ichava-core::commands.install.migrated'));

        return true;
    }

    protected function runComposerRequire(string $target): int
    {
        if (! $this->isValidRequireTarget($target)) {
            $this->failure(__('ichava/ichava-core::commands.install.composer.refused', ['target' => $target]));

            return self::FAILURE;
        }

        $timeout = max(60, (int) $this->option('timeout'));
        $composer = (string) (getenv('COMPOSER_BINARY') ?: 'composer');

        $process = new Process([$composer, 'require', $target, '--no-interaction', '--no-progress']);
        $process->setTimeout($timeout);

        try {
            if ($this->output->isDecorated() && function_exists('posix_isatty')) {
                try {
                    $process->setTty(Process::isTtySupported());
                } catch (Throwable) {
                }
            }

            $exit = spin(
                callback: function () use ($process) {
                    $process->run(fn ($type, $buffer) => $this->output->write($buffer));

                    return $process->getExitCode() ?? Process::ERR;
                },
                message: __('ichava/ichava-core::commands.install.composer.running', ['target' => $target]),
            );

            if ($exit !== 0) {
                $this->failure(__('ichava/ichava-core::commands.install.composer.failed_manual'));
                $this->tip(__('ichava/ichava-core::commands.install.composer.manual', ['composer' => $composer, 'target' => $target]));

                return self::FAILURE;
            }

            $this->success(__('ichava/ichava-core::commands.install.composer.done', ['target' => $target]));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->failure(__('ichava/ichava-core::commands.install.composer.failed', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }
    }

    protected function isValidRequireTarget(string $target): bool
    {
        return preg_match('{^[a-z0-9](?:[a-z0-9\-_./]*[a-z0-9])?(?::\^[0-9A-Za-z.\-_+]+)?$}i', $target) === 1;
    }
}
