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
 * php artisan ichava::ichava-core.install ichava/tabler-icons --force
 * php artisan ichava::ichava-core.install tabler --no-seed
 */
final class InstallCommand extends BaseCommand
{
    /** @var list<string> */
    protected array $commandAliases = ['ichava:install'];

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
        intro('🧩 Install Ichava Icon Set');

        try {
            $sets = $this->catalog->all();
        } catch (Throwable $e) {
            $this->failure("Could not load icon set catalog: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($sets === []) {
            warning('No icon sets declared in icon-sets.json.');

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
            warning("'{$set['title']}' looks already installed."
                . ($set['installed_version'] ? " (version {$set['installed_version']})" : ''));
            note('Re-running will require the latest release and re-seed its icons.');

            if (! $this->option('force') && ! confirm(
                label: 'Continue with reinstall?',
                default: false,
            )) {
                warning('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        $package = (string) $set['package'];

        $latest = $set['latest_version'] ?? null;

        if ($latest !== null) {
            $this->line("  <fg=white>Latest release:</fg=white> <fg=cyan>{$latest}</fg=cyan>");
        } else {
            note('Could not resolve the latest release tag; composer will install the newest stable release.');
        }

        $target = $this->catalog->requireTarget($package);

        if (! $this->option('force') && ! confirm(
            label: "Require '{$target}' via Composer?",
            default: true,
        )) {
            warning('Operation cancelled.');

            return self::SUCCESS;
        }

        if ($this->runComposerRequire($target) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->option('no-seed')) {
            note("Skipped seeding. Seed later with: php artisan ichava:database seed --package={$package}");
            outro("✅ {$set['title']} required successfully");

            return self::SUCCESS;
        }

        $this->line('');
        $this->line("  <fg=white>Seeding icons for</fg=white> <fg=cyan>{$package}</fg=cyan>...");

        $seedExit = $this->call('ichava:database', array_filter([
            'action'    => 'seed',
            '--package' => $package,
            '--sync'    => $this->option('sync') ?: null,
            '--force'   => $this->option('force') ?: null,
        ], fn ($value) => $value !== null));

        if ($seedExit !== 0) {
            $this->failure("Composer require succeeded but seeding '{$package}' failed.");
            $this->tip("Retry seeding with: php artisan ichava:database seed --package={$package}");

            return self::FAILURE;
        }

        outro("✅ {$set['title']} installed and seeded successfully");

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
                $this->failure("Unknown icon set '{$requested}'.");
                note('Available: ' . implode(', ', array_map(
                    fn (array $set) => (string) $set['key'],
                    $sets,
                )));

                return null;
            }

            return $found;
        }

        $options = [];
        foreach ($sets as $set) {
            $installed = $set['installed'] === true
                ? '✅ installed' . (is_string($set['installed_version'] ?? null) && $set['installed_version'] !== '' ? " ({$set['installed_version']})" : '')
                : '○ not installed';
            $seeded = ($set['seeded'] ?? null) === true
                ? '✅ seeded' . (isset($set['seeded_count']) ? ' (' . $this->formatNumber((int) $set['seeded_count']) . ')' : '')
                : '○ not seeded';

            $options[(string) $set['key']] = "{$set['title']} ("
                . $this->formatNumber((int) $set['icon_count']) . ' icons, '
                . implode(' + ', (array) $set['variants']) . ') — '
                . "{$installed} · {$seeded}";
        }

        $key = select(
            label: 'Which icon set would you like to install?',
            options: $options,
            hint: 'Pick a set to require via Composer and seed',
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

        warning('Core database tables are missing. Icons cannot be seeded until core migrations have run.');
        note('Missing tables: ' . implode(', ', $this->database->getMissingTables()));

        $runNow = $this->option('force') || confirm(
            label: 'Run core migrations now?',
            default: true,
        );

        if (! $runNow) {
            warning('Operation cancelled.');
            note('Re-run this command once migration is done: php artisan ichava::ichava-core.install');

            return null;
        }

        $exit = $this->call('ichava:database', ['action' => 'migrate']);

        if ($exit !== 0 || ! $this->database->tablesExist()) {
            $this->failure('Core migrations did not complete.');

            return false;
        }

        $this->success('Core migrations completed.');

        return true;
    }

    protected function runComposerRequire(string $target): int
    {
        if (! $this->isValidRequireTarget($target)) {
            $this->failure("Refusing to run composer with unexpected target '{$target}'.");

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
                message: "Running composer require {$target}...",
            );

            if ($exit !== 0) {
                $this->failure('Composer require failed. Run it manually to see full output:');
                $this->tip("{$composer} require {$target}");

                return self::FAILURE;
            }

            $this->success("Composer require completed: {$target}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->failure("Composer require failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function isValidRequireTarget(string $target): bool
    {
        return preg_match('{^[a-z0-9](?:[a-z0-9\-_./]*[a-z0-9])?(?::\^[0-9A-Za-z.\-_+]+)?$}i', $target) === 1;
    }
}
