<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Contracts\Console\Kernel;
use Simtabi\Laranail\Ichava\Commands\DatabaseCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;
use Simtabi\Laranail\Ichava\Tests\Support\RunsCommandsForCharacterization;

/*
|--------------------------------------------------------------------------
| Characterization: `ichava::ichava-core.database`
|--------------------------------------------------------------------------
|
| Pins what the command prints and asks TODAY, before the console refactor
| touches it. This is not a specification: where current behaviour looks
| wrong it is pinned anyway and marked, so the refactor changes it on purpose
| and the diff shows up here rather than nowhere.
|
*/

uses(RunsCommandsForCharacterization::class);

const DATABASE_COMMAND = 'ichava::ichava-core.database';

/**
 * DDL inside RefreshDatabase's transaction is only safe where DDL is
 * transactional. MySQL and MariaDB commit implicitly on DROP/CREATE, which
 * would leave the schema altered for every later test.
 */
function databaseCommandDdlIsTransactional(): bool
{
    return in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true);
}

/**
 * Bind a DatabaseOperationsService whose truncateTables() throws, and register
 * a fresh command so it receives it -- Artisan constructed the original during
 * TestCase::setUp(), before this binding existed.
 */
function bindThrowingDatabaseOperations(): void
{
    $service = Mockery::mock(DatabaseOperationsService::class)->makePartial();
    $service->shouldReceive('truncateTables')->andThrow(new RuntimeException('disk on fire'));
    app()->instance(DatabaseOperationsService::class, $service);

    app(Kernel::class)->registerCommand(app(DatabaseCommand::class));
}

it('prompts for an action when none is given, defaulting to stats', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, [], ['stats']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'What database operation would you like to perform? [Stats - Show database statistics]',
        'Seed - Populate database with icons and terms',
        'Truncate - Clear all tables',
        '📊 Ichava Database Statistics',
    ]);
});

it('prints the statistics table for stats', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'stats']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '📊 Ichava Database Statistics',
        'Metric',
        'Value',
        'Total Icons',
        'Total Packages',
        'Categories',
        'Variants',
        'Term Relationships',
        'Database Size',
    ]);

    // characterization: the size query is pg_total_relation_size() on every
    // driver, so only PostgreSQL reports a size; the rest print N/A.
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->assertMatchesRegularExpression('/Database Size\s*│?\s*[\d.]+ (bytes|kB|MB|GB)/u', $display);
    } else {
        $this->assertDisplayContains($display, ['N/A']);
    }
});

it('reports an invalid action and returns INVALID when the offered select is cancelled', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'bogus'], ['cancel']);

    $this->assertSame(2, $exit);
    $this->assertDisplayContains($display, [
        'Invalid action: bogus',
        'Valid actions: seed, seed:icons, seed:terms, migrate, unseed, refresh, truncate, stats',
        'Would you like to select a valid action? [Cancel operation]',
    ]);
});

it('re-runs with the action picked from the invalid-action select', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'bogus'], ['stats']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['Invalid action: bogus', '📊 Ichava Database Statistics']);
});

it('returns INVALID silently under --quiet', function (): void {
    [$exit, $display] = $this->runCommand(
        DATABASE_COMMAND,
        ['action' => 'bogus'],
        verbosity: OutputInterface::VERBOSITY_QUIET,
    );

    $this->assertSame(2, $exit);
    $this->assertSame('', $display);
});

it('runs migrations for migrate, and loses its own outro to the nested migrate call', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'migrate']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['🔄 Running Ichava migrations', 'Running migrations...']);

    // characterization: runMigrations() goes through Artisan::call('migrate'),
    // and the nested command re-points Laravel Prompts at ITS buffered output
    // without restoring it. Everything this command prints through Prompts
    // afterwards -- the success outro included -- lands in that buffer and
    // never reaches the user. Changes in the refactor.
    $this->assertDisplayLacks($display, ['✅ Migrations completed successfully']);
    $this->assertStringContainsString('✅ Migrations completed successfully', Artisan::output());
});

it('cancels migrate --fresh when the drop is declined', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'migrate', '--fresh' => true], ['no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'This will DROP all Ichava tables and re-run migrations. Continue? (yes/no) [no]',
        'Operation cancelled.',
    ]);
    $this->assertDisplayLacks($display, ['🔄 Running fresh Ichava migration']);
});

it('runs migrate --fresh under --force without asking', function (): void {
    // --force answers the drop confirmation; it used to prompt anyway and then
    // ignore the answer, so a "no" still dropped every table.
    [$exit, $display] = $this->runCommand(
        DATABASE_COMMAND,
        ['action' => 'migrate', '--fresh' => true, '--force' => true],
    );

    $this->assertSame(0, $exit);
    $this->assertDisplayLacks($display, ['This will DROP all Ichava tables and re-run migrations. Continue?']);
    $this->assertDisplayContains($display, [
        '🔄 Running fresh Ichava migration',
        'Dropping and recreating tables...',
    ]);

    // characterization: as with plain migrate, the table and outro printed
    // after the nested Artisan::call('migrate') land in that call's buffer,
    // not in this command's display. Changes in the refactor.
    // Artisan::output() drains the buffer, so read it once.
    $nested = Artisan::output();

    $this->assertDisplayLacks($display, ['Dropped Tables']);
    $this->assertDisplayContains($nested, [
        'Dropped Tables',
        'ichava_icon_termables',
        '✅ Fresh migration completed successfully',
    ]);

    // characterization: the drop leaves the rows in `migrations`, so the
    // re-run reports "Nothing to migrate" and the tables stay dropped while
    // the command reports success. Changes in the refactor.
    $this->assertStringContainsString('Nothing to migrate', $nested);
    $this->assertFalse(Schema::hasTable('ichava_icons'));
})->skip(fn (): bool => ! databaseCommandDdlIsTransactional(), 'DDL is not transactional on this driver');

it('fails a table-backed action when a table is missing', function (): void {
    DB::connection()->getSchemaBuilder()->drop('ichava_icon_termables');

    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'stats']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, [
        '✗ Required tables do not exist: ichava_icon_termables',
        '💡 Run migrations first: php artisan ichava::ichava-core.database migrate',
    ]);
})->skip(fn (): bool => ! databaseCommandDdlIsTransactional(), 'DDL is not transactional on this driver');

it('asks before truncating and cancels on no', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'truncate'], ['no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'This will delete all icons and terms. Continue? (yes/no) [no]',
        'Operation cancelled.',
    ]);
    $this->assertDisplayLacks($display, ['Tables truncated:']);
});

it('truncates on yes', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'truncate'], ['yes']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🗑️  Truncating tables...',
        'Tables truncated: ichava_icon_termables, ichava_icon_terms, ichava_icons',
    ]);
});

it('truncates without asking under --force', function (): void {
    // truncate honoured --force before the other five destructive actions did.
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'truncate', '--force' => true]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['Tables truncated:']);
    $this->assertDisplayLacks($display, ['This will delete all icons and terms. Continue?']);
});

it('reports the tryExecute failure message when truncation throws', function (): void {
    bindThrowingDatabaseOperations();

    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'truncate', '--force' => true]);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['Failed to truncate: disk on fire']);
    $this->assertDisplayLacks($display, ['File: ', '#0 ']);
});

it('adds the file and line at -v, but no stack trace', function (): void {
    // Traces are held back to -vvv; tryExecute used to print them at -v.
    bindThrowingDatabaseOperations();

    [$exit, $display] = $this->runCommand(
        DATABASE_COMMAND,
        ['action' => 'truncate', '--force' => true],
        verbosity: OutputInterface::VERBOSITY_VERBOSE,
    );

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['Failed to truncate: disk on fire', 'File: ']);
    $this->assertDisplayLacks($display, ['#0 ']);
});

it('offers a choice for unseed without --package and cancels', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'unseed'], ['cancel']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'What would you like to unseed? [Cancel - Do nothing]',
        'All packages - Remove all Ichava data',
        'Specific package - Choose a package to unseed',
        'Operation cancelled.',
    ]);
});

it('unseeds everything after choosing all and confirming', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'unseed'], ['all', 'yes']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'This will remove ALL Ichava data. Continue? (yes/no) [no]',
        '🗑️  Unseeding all packages',
        'Metric',
        'Count',
        'Icons deleted',
        'Term relations deleted',
        'Terms deleted',
        '✅ All packages unseeded successfully',
    ]);
});

it('asks for a package name after choosing package, then confirms', function (): void {
    [$exit, $display] = $this->runCommand(
        DATABASE_COMMAND,
        ['action' => 'unseed'],
        ['package', 'ichava/test-icons', 'no'],
    );

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'Enter the package name to unseed',
        "This will remove all data for package 'ichava/test-icons'. Continue? (yes/no) [no]",
        'Operation cancelled.',
    ]);
});

it('unseeds everything under --force without the choice or the confirmation', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'unseed', '--force' => true]);

    $this->assertSame(0, $exit);
    $this->assertDisplayLacks($display, ['What would you like to unseed?', 'This will remove ALL Ichava data. Continue?']);
    $this->assertDisplayContains($display, ['✅ All packages unseeded successfully']);
});

it('cancels unseeding everything when the confirmation is declined', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'unseed'], ['all', 'no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['This will remove ALL Ichava data. Continue?', 'Operation cancelled.']);
    $this->assertDisplayLacks($display, ['🗑️  Unseeding all packages']);
});

it('unseeds a package under --force without asking', function (): void {
    [$exit, $display] = $this->runCommand(
        DATABASE_COMMAND,
        ['action' => 'unseed', '--package' => 'ichava/test-icons', '--force' => true],
    );

    $this->assertSame(0, $exit);
    $this->assertDisplayLacks($display, ["This will remove all data for package 'ichava/test-icons'. Continue?"]);
    $this->assertDisplayContains($display, [
        '🗑️  Unseeding package: ichava/test-icons',
        'Icons deleted',
        'Term relations deleted',
        'Orphaned terms deleted',
        '✅ Package unseeded successfully',
    ]);
});

it('cancels refresh when declined', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'refresh'], ['no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'This will delete all existing data and re-seed. Continue? (yes/no) [no]',
        'Operation cancelled.',
    ]);
});

it('asks twice for refresh without --force: once to refresh, once to truncate', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'refresh', '--sync' => true], ['yes', 'yes']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'This will delete all existing data and re-seed. Continue?',
        'This will delete all icons and terms. Continue?',
        '🔄 Refreshing database',
        '✅ Database seeded successfully',
    ]);
});

it('refreshes under --force without asking, then truncates and seeds', function (): void {
    [$exit, $display] = $this->runCommand(
        DATABASE_COMMAND,
        ['action' => 'refresh', '--force' => true, '--sync' => true],
    );

    $this->assertSame(0, $exit);
    $this->assertDisplayLacks($display, ['This will delete all existing data and re-seed. Continue?']);
    $this->assertDisplayContains($display, [
        '🔄 Refreshing database',
        'Tables truncated:',
        '🌱 Seeding Ichava database',
        '✅ Database seeded successfully',
    ]);
    $this->assertDisplayLacks($display, ['This will delete all icons and terms. Continue?']);
});

it('seeds terms then icons synchronously and prints the stats table', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'seed', '--sync' => true]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🌱 Seeding Ichava database',
        '🏷️  Seeding terms...',
        '📦 Seeding icons...',
        '✅ Database seeded successfully',
        '⏱️  Completed in',
        'Total Icons',
        'Term Relationships',
    ]);
    $this->assertDisplayLacks($display, ['Icon seeding jobs are queued.']);
    $this->assertLessThan(
        strpos($display, '📦 Seeding icons...'),
        strpos($display, '🏷️  Seeding terms...'),
        'Terms are seeded before icons.',
    );
});

it('prints the queued-jobs guidance instead of stats when seeding through the queue', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'seed']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '✅ Database seeded successfully',
        'Icon seeding jobs are queued. Stats will be accurate after jobs complete.',
        'Monitor jobs: php artisan ichava::ichava-core.job-status',
        'View stats: php artisan ichava::ichava-core.database stats',
    ]);
    $this->assertDisplayLacks($display, ['Term Relationships']);
});

it('cancels seed --fresh when declined', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'seed', '--fresh' => true], ['no']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🌱 Seeding Ichava database',
        'This will delete all existing data before seeding. Continue? (yes/no) [no]',
        'Operation cancelled.',
    ]);
    $this->assertDisplayLacks($display, ['🏷️  Seeding terms...']);
});

it('seeds --fresh under --force without asking', function (): void {
    [$exit, $display] = $this->runCommand(
        DATABASE_COMMAND,
        ['action' => 'seed', '--fresh' => true, '--force' => true, '--sync' => true],
    );

    $this->assertSame(0, $exit);
    $this->assertDisplayLacks($display, [
        'This will delete all existing data before seeding. Continue?',
        'This will delete all icons and terms. Continue?',
    ]);
    $this->assertDisplayContains($display, [
        'Tables truncated:',
        '✅ Database seeded successfully',
    ]);
});

it('marks seed:icons --update as force-update mode', function (): void {
    [$exit, $display] = $this->runCommand(
        DATABASE_COMMAND,
        ['action' => 'seed:icons', '--update' => true, '--sync' => true],
    );

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['📦 Seeding icons... (force update mode)']);
    $this->assertDisplayLacks($display, ['🏷️  Seeding terms...']);
});

it('seeds terms alone for seed:terms', function (): void {
    [$exit, $display] = $this->runCommand(DATABASE_COMMAND, ['action' => 'seed:terms']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['🏷️  Seeding terms...']);
    $this->assertDisplayLacks($display, ['📦 Seeding icons...']);
});
