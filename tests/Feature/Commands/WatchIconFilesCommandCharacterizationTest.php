<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Models\Icon;
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

it('reports no changes on a second scan over unchanged files', function (): void {
    // The second run used to re-insert every icon the first stored and fail
    // on the unique index on ichava_icons.path: disk icons were keyed by
    // absolute path, database rows by relative path, so none ever matched.
    $this->runCommand(WATCH_COMMAND);
    $stored = Icon::count();

    [$exit, $display] = $this->runCommand(WATCH_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertSame($stored, Icon::count(), 'The second scan changed the number of stored icons.');
    $this->assertMatchesRegularExpression('/New Icons\s*│\s*0\s/u', $display);
    $this->assertMatchesRegularExpression('/Deleted Icons\s*│\s*0\s/u', $display);
    $this->assertDisplayContains($display, ['✨ No changes detected, database up to date.']);
    $this->assertDisplayLacks($display, ['SQLSTATE[']);
});

it('picks up an icon whose file changed since the last scan', function (): void {
    $this->runCommand(WATCH_COMMAND);

    $icon = Icon::query()->firstOrFail();
    $icon->forceFill(['file_hash' => 'stale'])->save();

    [$exit, $display] = $this->runCommand(WATCH_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertMatchesRegularExpression('/Updated Icons\s*│\s*1\s/u', $display);
    $this->assertNotSame('stale', $icon->fresh()->file_hash);
});
