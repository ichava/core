<?php

declare(strict_types=1);

// bin/sync-icon-sets.php rewrote icon-sets.json alone, so a count change opened a
// pull request that InstallationPackTableTest failed (core#179: tabler 6,257 -> 6,287,
// openmoji 8,568 -> 8,990). The sync now sets the guide's count cells from the
// catalogue it writes.

require_once dirname(__DIR__, 2) . '/bin/sync-icon-sets.php';

function docTable(string $tabler, string $openmoji): string
{
    return "Intro.\n\n| Package | Icons | Repository |\n|---|---|---|\n"
        . "| `ichava/icon-sets-tabler` | {$tabler} | `https://github.com/ichava/icon-sets-tabler` |\n"
        . "| `ichava/icon-sets-openmoji` | {$openmoji} | `https://github.com/ichava/icon-sets-openmoji` |\n"
        . "\nOutro mentions 6,257 icons.\n";
}

it('sets each row count from the catalogue', function () {
    $sets = [
        ['package' => 'ichava/icon-sets-tabler', 'icon_count' => 6287],
        ['package' => 'ichava/icon-sets-openmoji', 'icon_count' => 8990],
    ];

    expect(withPackTableCounts(docTable('6,257', '8,568'), $sets))->toBe(docTable('6,287', '8,990'));
});

it('leaves a row the catalogue does not name, and the prose around the table', function () {
    $doc = docTable('6,257', '8,568');

    expect(withPackTableCounts($doc, [['package' => 'ichava/icon-sets-tabler', 'icon_count' => 6257]]))->toBe($doc)
        ->and(withPackTableCounts($doc, []))->toBe($doc);
});

it('keeps the real guide in step with the real catalogue', function () {
    $root = dirname(__DIR__, 2);
    $sets = json_decode((string) file_get_contents($root . '/icon-sets.json'), true, flags: JSON_THROW_ON_ERROR)['sets'];
    $doc = (string) file_get_contents($root . '/docs/installation.md');

    expect(withPackTableCounts($doc, $sets))->toBe($doc);
});
