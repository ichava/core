<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;
use Simtabi\Laranail\Ichava\Tests\Support\RunsCommandsForCharacterization;

/*
|--------------------------------------------------------------------------
| Characterization: `ichava::ichava-core.check-updates`
|--------------------------------------------------------------------------
|
| Pins what the command prints TODAY, before the console refactor.
| CheckIconUpdatesCommandTest already pins the exit-code contract; this file
| pins the rendered text. The checker is replaced by a stub so nothing
| touches the network -- the command resolves it in handle(), so a binding
| made after boot takes effect without re-registering the command. One test
| runs the real checker, which the suite's only pack (no `upstream` block)
| answers without a request.
|
*/

uses(RunsCommandsForCharacterization::class);

const CHECK_UPDATES_COMMAND = 'ichava::ichava-core.check-updates';

/**
 * @param list<array<string, mixed>> $rows
 */
function bindUpdateCheckerRows(array $rows): void
{
    app()->instance(IconPackUpdateChecker::class, new class($rows) extends IconPackUpdateChecker
    {
        /**
         * @param list<array<string, mixed>> $rows
         */
        public function __construct(public array $rows = [])
        {
            // No parent constructor: the stub needs no registry.
        }

        public function checkAll(?string $packageFilter = null): array
        {
            return $this->rows;
        }
    });
}

/**
 * @return array<string, mixed>
 */
function updateCheckerRow(string $status, array $overrides = []): array
{
    return array_merge([
        'package'     => 'ichava/icon-sets-tabler',
        'source'      => 'primary',
        'status'      => $status,
        'current'     => '3.0.0',
        'latest'      => '3.44.0',
        'release_url' => null,
        'reason'      => null,
    ], $overrides);
}

it('reports a pack without an upstream block through the real checker', function (): void {
    [$exit, $display] = $this->runCommand(CHECK_UPDATES_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🔍 Checking icon-pack upstream sources',
        'Polling upstream sources (12h cache on hit)…',
        'Package',
        'Status',
        'Current',
        'Latest',
        'Notes',
        'ichava/test-icons',
        'no-upstream',
        'Pack does not declare an upstream block in config.json',
        '✅ All packs up to date',
    ]);
    $this->assertDisplayLacks($display, ['Source']);
});

it('notes an empty registry', function (): void {
    bindUpdateCheckerRows([]);

    [$exit, $display] = $this->runCommand(CHECK_UPDATES_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['No registered packs to check.']);
    $this->assertDisplayLacks($display, ['✅ All packs up to date']);
});

it('counts stale packs in the outro', function (): void {
    bindUpdateCheckerRows([updateCheckerRow('update-available')]);

    [$exit, $display] = $this->runCommand(CHECK_UPDATES_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['update-available', '3.44.0', '⚠️  1 pack(s) behind upstream']);
});

it('counts unreachable packs in the outro and shows the reason', function (): void {
    bindUpdateCheckerRows([updateCheckerRow('unreachable', ['latest' => null, 'reason' => 'connection refused'])]);

    [$exit, $display] = $this->runCommand(CHECK_UPDATES_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'unreachable',
        'connection refused',
        '⚠️  1 pack(s) unreachable; rest up to date',
    ]);
});

it('adds a Source column only when a row has a secondary source', function (): void {
    bindUpdateCheckerRows([
        updateCheckerRow('up-to-date', ['latest' => '3.0.0']),
        updateCheckerRow('up-to-date', ['source' => 'openmoji', 'latest' => '3.0.0']),
    ]);

    [$exit, $display] = $this->runCommand(CHECK_UPDATES_COMMAND);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['Source', 'primary', 'openmoji', '✅ All packs up to date']);
});

it('prints nothing but the JSON document under --format=json', function (): void {
    // The intro and outro used to frame the JSON, so stdout was not parseable
    // on its own and callers had to regex the array out.
    bindUpdateCheckerRows([updateCheckerRow('update-available')]);

    [$exit, $display] = $this->runCommand(CHECK_UPDATES_COMMAND, ['--format' => 'json']);

    $this->assertSame(0, $exit);

    $decoded = json_decode($display, true, flags: JSON_THROW_ON_ERROR);

    $this->assertCount(1, $decoded);
    $this->assertSame('ichava/icon-sets-tabler', $decoded[0]['package']);
    $this->assertSame('update-available', $decoded[0]['status']);
    $this->assertDisplayLacks($display, ['🔍 Checking icon-pack upstream sources', 'Polling upstream sources', 'pack(s) behind upstream']);
});

it('prints an empty JSON array, not a note, when no pack is registered', function (): void {
    bindUpdateCheckerRows([]);

    [$exit, $display] = $this->runCommand(CHECK_UPDATES_COMMAND, ['--format' => 'json']);

    $this->assertSame(0, $exit);
    $this->assertSame([], json_decode($display, true, flags: JSON_THROW_ON_ERROR));
});

it('keeps the --fail-on-stale exit code under --format=json', function (): void {
    bindUpdateCheckerRows([updateCheckerRow('update-available')]);

    [$exit, $display] = $this->runCommand(CHECK_UPDATES_COMMAND, ['--format' => 'json', '--fail-on-stale' => true]);

    $this->assertSame(1, $exit);
    $this->assertCount(1, json_decode($display, true, flags: JSON_THROW_ON_ERROR));
});
