<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\OutputInterface;
use Simtabi\Laranail\Ichava\Tests\Support\RunsCommandsForCharacterization;

/*
|--------------------------------------------------------------------------
| Characterization: `ichava::ichava-core.info`
|--------------------------------------------------------------------------
|
| Pins what the command prints and asks TODAY, before the console refactor.
| The suite registers one pack, `ichava/test-icons` (4 SVGs); running the
| watch command populates the icons table from it.
|
*/

uses(RunsCommandsForCharacterization::class);

const INFO_COMMAND = 'ichava::ichava-core.info';

it('prompts for a type when none is given, defaulting to stats', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, [], ['stats']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'What information would you like to view? [Stats - Overview statistics]',
        'Packages - List registered icon packages',
        'Languages - PostgreSQL FTS languages',
        '📊 Ichava Statistics',
    ]);
});

it('reports an invalid type and returns INVALID when the offered select is cancelled', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'bogus'], ['cancel']);

    $this->assertSame(2, $exit);
    $this->assertDisplayContains($display, [
        'Invalid type: bogus',
        'Valid types: packages, icons, status, languages, discover, stats',
        'Would you like to select a valid type? [Cancel operation]',
    ]);
});

it('re-runs with the type picked from the invalid-type select', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'bogus'], ['stats']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['Invalid type: bogus', '📊 Ichava Statistics']);
});

it('prints statistics without the top-packages table when nothing is seeded', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'stats']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '📊 Ichava Statistics',
        'Metric',
        'Value',
        'Total Icons',
        'Total Packages',
        'Categories',
        'Variants',
        'Database Size',
        'Cache Driver',
    ]);
    $this->assertDisplayLacks($display, ['🏆 Top 5 Packages by Icon Count:']);
});

it('adds the top-packages table once icons are seeded', function (): void {
    [$seeded] = $this->runCommand('ichava::ichava-core.watch');
    $this->assertSame(0, $seeded, 'The watch command seeds the fixture icons.');

    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'stats']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🏆 Top 5 Packages by Icon Count:',
        'Icon Count',
        'ichava/test-icons',
    ]);
});

it('lists registered packages after an empty search', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'packages'], ['']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '📦 Registered Icon Packages',
        'Search packages (leave empty to show all)',
        'Package',
        'Path',
        'Icons',
        'Status',
        'ichava/test-icons',
        '● Active',
    ]);
});

it('prints an empty packages table when the search matches nothing', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'packages'], ['zzz-no-match']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['Package']);
    $this->assertDisplayLacks($display, ['ichava/test-icons']);
});

it('skips the search prompt when --search is given', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'packages', '--search' => 'test']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['ichava/test-icons']);
    $this->assertDisplayLacks($display, ['Search packages (leave empty to show all)']);
});

it('prints packages as JSON under --format=json', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'packages', '--format' => 'json'], ['']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['"name": "ichava\/test-icons"', '"status": "active"']);
});

it('exports packages to a JSON file under --export', function (): void {
    $path = sys_get_temp_dir() . '/ichava-info-characterization-' . uniqid() . '.json';

    try {
        [$exit, $display] = $this->runCommand(
            INFO_COMMAND,
            ['type' => 'packages', '--search' => 'test', '--export' => $path],
        );

        $this->assertSame(0, $exit);
        $this->assertFileExists($path);
        $this->assertDisplayContains($display, ["✓ Exported to: {$path}"]);
    } finally {
        File::delete($path);
    }
});

it('reports no icons when the table is empty', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'icons'], ['']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['🎨 Icon Browser', 'Search icons', 'No icons found.']);
});

it('lists icons with a limit note once seeded', function (): void {
    [$seeded] = $this->runCommand('ichava::ichava-core.watch');
    $this->assertSame(0, $seeded, 'The watch command seeds the fixture icons.');

    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'icons', '--limit' => 2], ['']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'Name',
        'Package',
        'Path',
        'ichava/test-icons',
        'Showing 2 icons. Use --limit to show more.',
    ]);
});

it('shows a not-ready lifecycle with next steps before seeding', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'status']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🔍 Ichava Lifecycle Status',
        '✓ Migrations: OK',
        '✗ Seeds:      NOT READY',
        '✓ Cache:      OK',
        'Current Stage: MIGRATED',
        'System Ready:  No',
        'Next Steps:',
        '1. Run: php artisan ichava::ichava-core.database seed',
    ]);
});

it('shows a ready lifecycle after seeding and --reset', function (): void {
    [$seeded] = $this->runCommand('ichava::ichava-core.watch');
    $this->assertSame(0, $seeded, 'The watch command seeds the fixture icons.');

    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'status', '--reset' => true]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'Resetting lifecycle state...',
        '✓ Lifecycle state reset',
        'Current Stage: READY',
        'System Ready:  Yes',
        'Icon Count:   4',
        '✅ Ichava is fully operational!',
    ]);
    $this->assertDisplayLacks($display, ['NOT READY', 'Next Steps:']);
});

it('reports no FTS languages off PostgreSQL', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'languages']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🌍 PostgreSQL FTS Languages',
        'No FTS languages found or not using PostgreSQL.',
    ]);
})->skip(fn (): bool => DB::connection()->getDriverName() === 'pgsql', 'PostgreSQL lists real languages');

it('reports no discovered packages in the test harness', function (): void {
    [$exit, $display] = $this->runCommand(INFO_COMMAND, ['type' => 'discover']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['🔍 Discovering Icon Packages', 'No packages discovered.']);
});

it('prints nothing at all under --quiet', function (): void {
    [$exit, $display] = $this->runCommand(
        INFO_COMMAND,
        ['type' => 'packages'],
        verbosity: OutputInterface::VERBOSITY_QUIET,
    );

    $this->assertSame(0, $exit);
    $this->assertSame('', $display);
});
