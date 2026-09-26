<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Tests\Support\RunsCommandsForCharacterization;

/*
|--------------------------------------------------------------------------
| Characterization: `ichava::ichava-core.watch`
|--------------------------------------------------------------------------
|
| Pins what the command prints TODAY, before the console refactor. The suite
| registers one pack, `ichava/test-icons`, with 4 SVGs.
|
*/

uses(RunsCommandsForCharacterization::class);

const WATCH_COMMAND = 'ichava::ichava-core.watch';

it('syncs new icons on the first scan and prints the change table', function (): void {
    [$exit, $display] = $this->runCommand(WATCH_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '👁️ Watching icon files for changes',
        'Scanning for changes...',
        'Metric',
        'Count',
        'Packages Scanned',
        'New Icons',
        'Updated Icons',
        'Deleted Icons',
        'Total Changes',
        'Duration',
        '✅ Database synchronized with file system!',
    ]);
    $this->assertDisplayLacks($display, ['✨ No changes detected, database up to date.']);
});

it('warns and skips when another watcher holds the lock', function (): void {
    Cache::put('ichava:file_watcher:lock', true, 300);

    [$exit, $display] = $this->runCommand(WATCH_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['File watcher already running, skipped.']);
    $this->assertDisplayLacks($display, ['Packages Scanned']);
});

it('breaks the lock under --force and scans anyway', function (): void {
    Cache::put('ichava:file_watcher:lock', true, 300);

    [$exit, $display] = $this->runCommand(WATCH_COMMAND, ['--force' => true]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['Packages Scanned', '✅ Database synchronized with file system!']);
});

it('fails a second scan by re-inserting icons the first scan stored', function (): void {
    // characterization: a second run over unchanged files tries to INSERT the
    // same paths again and trips the unique index on ichava_icons.path, where
    // "✨ No changes detected, database up to date." was plainly intended. The
    // QueryException is not caught by the command and surfaces through the
    // laranail/console base. Changes in the refactor (or in the watcher).
    $this->runCommand(WATCH_COMMAND);

    [$exit, $display] = $this->runCommand(WATCH_COMMAND);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['Command failed: SQLSTATE[']);
    $this->assertDisplayLacks($display, ['✨ No changes detected, database up to date.']);
});
