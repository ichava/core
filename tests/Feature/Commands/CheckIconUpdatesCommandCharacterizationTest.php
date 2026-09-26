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

it('prints JSON with no spinner under --format=json, framed by the intro and outro', function (): void {
    // characterization: the intro and outro still print around the JSON, so
    // stdout under --format=json is not parseable as JSON on its own (the
    // exit-code test in CheckIconUpdatesCommandTest regex-extracts the array
    // for this reason). Changes in the refactor.
    bindUpdateCheckerRows([updateCheckerRow('update-available')]);

    [$exit, $display] = $this->runCommand(CHECK_UPDATES_COMMAND, ['--format' => 'json']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🔍 Checking icon-pack upstream sources',
        '"package": "ichava/icon-sets-tabler"',
        '"status": "update-available"',
        '⚠️  1 pack(s) behind upstream',
    ]);
    $this->assertDisplayLacks($display, ['Polling upstream sources']);
});
