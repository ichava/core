<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Simtabi\Laranail\Ichava\Commands\InstallCommand;
use Simtabi\Laranail\Ichava\Services\IconSetCatalogService;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;
use Simtabi\Laranail\Ichava\Tests\Support\RunsCommandsForCharacterization;

/*
|--------------------------------------------------------------------------
| Characterization: `ichava::ichava-core.install`
|--------------------------------------------------------------------------
|
| Pins what the command prints and asks TODAY, before the console refactor.
| Every path here stops before `composer require` runs: that step shells out
| and needs the network, so it is deliberately out of reach. The catalog's
| pinned release comes from the shipped icon-sets.json snapshot, which a
| scheduled workflow rewrites, so it is read from the service rather than
| written down.
|
*/

uses(RunsCommandsForCharacterization::class);

const INSTALL_COMMAND = 'ichava::ichava-core.install';

/**
 * Register a fresh InstallCommand so services bound after boot reach it --
 * Artisan built the original during TestCase::setUp().
 */
function reRegisterInstallCommand(): void
{
    app(Kernel::class)->registerCommand(app(InstallCommand::class));
}

/**
 * @param list<array<string, mixed>>|Throwable $sets
 */
function bindInstallCatalog(array|Throwable $sets): void
{
    $catalog = Mockery::mock(IconSetCatalogService::class)->makePartial();

    if ($sets instanceof Throwable) {
        $catalog->shouldReceive('all')->andThrow($sets);
    } else {
        $catalog->shouldReceive('all')->andReturn($sets);
        $catalog->shouldReceive('find')->andReturnUsing(
            fn (string $key): ?array => collect($sets)->first(
                fn (array $set): bool => $set['key'] === $key || $set['package'] === $key,
            ),
        );
    }

    app()->instance(IconSetCatalogService::class, $catalog);
    reRegisterInstallCommand();
}

it('lists the catalog and stops when Composer is declined', function (): void {
    $target = app(IconSetCatalogService::class)->requireTarget('ichava/icon-sets-tabler');

    [$exit, $display] = $this->runCommand(INSTALL_COMMAND, [], ['tabler', 'no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🧩 Install Ichava Icon Set',
        'Which icon set would you like to install?',
        'Tabler Icons (',
        ' icons, outline + filled) — ○ not installed · ○ not seeded',
        'Flag Icons (',
        'Emoji Sets (',
        'Latest release: ',
        "Require '{$target}' via Composer? (yes/no) [yes]",
        'Operation cancelled.',
    ]);
    $this->assertDisplayLacks($display, ['Running composer require']);
});

it('skips the list when a catalog key is given', function (): void {
    [$exit, $display] = $this->runCommand(INSTALL_COMMAND, ['set' => 'tabler'], ['no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayLacks($display, ['Which icon set would you like to install?']);
    $this->assertDisplayContains($display, ['via Composer?', 'Operation cancelled.']);
});

it('resolves a set by its composer package name', function (): void {
    [$exit, $display] = $this->runCommand(INSTALL_COMMAND, ['set' => 'ichava/icon-sets-flag'], ['no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ["Require 'ichava/icon-sets-flag", 'Operation cancelled.']);
});

it('fails for an unknown set and lists the available keys', function (): void {
    [$exit, $display] = $this->runCommand(INSTALL_COMMAND, ['set' => 'nope']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ["✗ Unknown icon set 'nope'.", 'Available: tabler, flags, emoji']);
});

it('fails when the catalog cannot be loaded', function (): void {
    bindInstallCatalog(new RuntimeException('catalog is corrupt'));

    [$exit, $display] = $this->runCommand(INSTALL_COMMAND, ['set' => 'tabler']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['✗ Could not load icon set catalog: catalog is corrupt']);
});

it('succeeds with a warning when the catalog is empty', function (): void {
    bindInstallCatalog([]);

    [$exit, $display] = $this->runCommand(INSTALL_COMMAND, ['set' => 'tabler']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['No icon sets declared in icon-sets.json.']);
});

it('asks before reinstalling a set that looks installed', function (): void {
    bindInstallCatalog([[
        'key'               => 'tabler',
        'package'           => 'ichava/icon-sets-tabler',
        'title'             => 'Tabler Icons',
        'icon_count'        => 6202,
        'variants'          => ['outline', 'filled'],
        'installed'         => true,
        'installed_version' => '0.3.4',
        'latest_version'    => '0.3.4',
        'seeded'            => false,
    ]]);

    [$exit, $display] = $this->runCommand(INSTALL_COMMAND, ['set' => 'tabler'], ['no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        "'Tabler Icons' looks already installed. (version 0.3.4)",
        'Re-running will require the latest release and re-seed its icons.',
        'Continue with reinstall? (yes/no) [no]',
        'Operation cancelled.',
    ]);
});

it('offers to migrate when the core tables are missing, and stops when declined', function (): void {
    $database = Mockery::mock(DatabaseOperationsService::class)->makePartial();
    $database->shouldReceive('tablesExist')->andReturn(false);
    $database->shouldReceive('getMissingTables')->andReturn(['ichava_icons']);
    $this->app->instance(DatabaseOperationsService::class, $database);
    reRegisterInstallCommand();

    [$exit, $display] = $this->runCommand(INSTALL_COMMAND, ['set' => 'tabler'], ['no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'Core database tables are missing. Icons cannot be seeded until core migrations have run.',
        'Missing tables: ichava_icons',
        'Run core migrations now? (yes/no) [yes]',
        'Operation cancelled.',
        'Re-run this command once migration is done: php artisan ichava::ichava-core.install',
    ]);
});

it('fails when migrating did not produce the tables', function (): void {
    $database = Mockery::mock(DatabaseOperationsService::class)->makePartial();
    $database->shouldReceive('tablesExist')->andReturn(false);
    $database->shouldReceive('getMissingTables')->andReturn(['ichava_icons']);
    $this->app->instance(DatabaseOperationsService::class, $database);
    reRegisterInstallCommand();

    [$exit, $display] = $this->runCommand(INSTALL_COMMAND, ['set' => 'tabler'], ['yes']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['🔄 Running Ichava migrations', '✗ Core migrations did not complete.']);
});
