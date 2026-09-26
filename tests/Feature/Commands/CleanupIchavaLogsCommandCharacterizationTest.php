<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\OutputInterface;
use Simtabi\Laranail\Ichava\Tests\Support\RunsCommandsForCharacterization;

/*
|--------------------------------------------------------------------------
| Characterization: `ichava::ichava-core.cleanup-logs`
|--------------------------------------------------------------------------
|
| Pins what the command prints and asks TODAY, before the console refactor.
| Storage is pointed at a scratch directory so the command never touches the
| Testbench skeleton's own storage/logs.
|
*/

uses(RunsCommandsForCharacterization::class);

const CLEANUP_LOGS_COMMAND = 'ichava::ichava-core.cleanup-logs';

beforeEach(function (): void {
    $this->storage = sys_get_temp_dir() . '/ichava-cleanup-characterization-' . uniqid();
    File::ensureDirectoryExists($this->storage . '/logs');
    $this->app->useStoragePath($this->storage);

    $this->oldLog = $this->storage . '/logs/ichava-icons-2026-01-01.log';
    $this->newLog = $this->storage . '/logs/ichava-2026-09-26.log';
    $this->otherLog = $this->storage . '/logs/laravel.log';

    File::put($this->oldLog, 'old');
    touch($this->oldLog, time() - 30 * 86400);
    File::put($this->newLog, 'new');
    File::put($this->otherLog, 'not ours');
});

afterEach(function (): void {
    File::deleteDirectory($this->storage);
});

it('asks for the retention period when --days is absent', function (): void {
    [$exit, $display] = $this->runCommand(CLEANUP_LOGS_COMMAND, [], ['7']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'How many days of logs to retain? [7]',
        '🧹 Cleaning up Ichava logs older than 7 days',
    ]);
});

it('rejects an invalid retention answer with the validation message', function (): void {
    // Outside tests Laravel re-asks until the answer validates. Under the test
    // harness its promptUntilValid() throws PromptValidationException instead,
    // which the laranail/console base reports as an empty "Command failed:" --
    // so only the message and the non-zero exit are observable here.
    [$exit, $display] = $this->runCommand(CLEANUP_LOGS_COMMAND, [], ['0']);

    $this->assertSame(1, $exit);
    $this->assertFileExists($this->oldLog);
    $this->assertDisplayContains($display, ['Please enter a valid number of days (minimum 1)']);
    $this->assertDisplayLacks($display, ['🧹 Cleaning up Ichava logs']);
});

it('deletes old Ichava logs and keeps recent and foreign ones', function (): void {
    [$exit, $display] = $this->runCommand(CLEANUP_LOGS_COMMAND, ['--days' => 7]);

    $this->assertSame(0, $exit);
    $this->assertFileDoesNotExist($this->oldLog);
    $this->assertFileExists($this->newLog);
    $this->assertFileExists($this->otherLog);
    $this->assertDisplayContains($display, [
        '🧹 Cleaning up Ichava logs older than 7 days',
        'Processing log files...',
        'Metric',
        'Count',
        'Total log files',
        'Deleted',
        'Kept',
        'Failed',
        '✅ Cleaned up 1 old log file(s)',
    ]);
    $this->assertDisplayLacks($display, ['How many days of logs to retain?']);
});

it('counts a file matched by two globs once', function (): void {
    // `ichava-icons-*.log` also matches `ichava-*.log`; array_unique dedupes,
    // so two files on disk report as two, not three.
    [, $display] = $this->runCommand(CLEANUP_LOGS_COMMAND, ['--days' => 7]);

    $this->assertMatchesRegularExpression('/Total log files\s*│\s*2\s/u', $display);
});

it('only reports what it would delete under --dry-run', function (): void {
    [$exit, $display] = $this->runCommand(CLEANUP_LOGS_COMMAND, ['--days' => 7, '--dry-run' => true]);

    $this->assertSame(0, $exit);
    $this->assertFileExists($this->oldLog);
    $this->assertDisplayContains($display, [
        'DRY RUN MODE - No files will be deleted',
        'Analyzing log files...',
        'Would delete',
        'Would delete 1 file(s). Run without --dry-run to actually delete.',
    ]);
});

it('lists every file with its action at -v', function (): void {
    [$exit, $display] = $this->runCommand(
        CLEANUP_LOGS_COMMAND,
        ['--days' => 7],
        verbosity: OutputInterface::VERBOSITY_VERBOSE,
    );

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'File',
        'Age',
        'Action',
        'ichava-icons-2026-01-01.log',
        '✓ Deleted',
        '⊘ Kept',
        ' days',
    ]);
});

it('has nothing to do when every log is recent', function (): void {
    File::delete($this->oldLog);

    [$exit, $display] = $this->runCommand(CLEANUP_LOGS_COMMAND, ['--days' => 7]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['No old log files to clean up.']);
});

it('reports when no Ichava logs exist', function (): void {
    File::delete([$this->oldLog, $this->newLog]);

    [$exit, $display] = $this->runCommand(CLEANUP_LOGS_COMMAND, ['--days' => 7]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['✓ No Ichava log files found']);
});

it('fails when the log directory is missing', function (): void {
    File::deleteDirectory($this->storage . '/logs');

    [$exit, $display] = $this->runCommand(CLEANUP_LOGS_COMMAND, ['--days' => 7]);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ["✗ Log directory not found: {$this->storage}/logs"]);
});
