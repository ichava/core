<?php

declare(strict_types=1);

/**
 * docs/installation.md lists the public packs with their icon counts. The table read
 * tabler 6,184 and emoji "engine wiring only, assets pending" long after the packs
 * shipped 6,238 and 10,567, because nothing compared it with icon-sets.json, the
 * snapshot the nightly sync keeps current. This does: one row per set, in the same
 * order, each with the snapshot's package, count and repository.
 */
it('lists every set in icon-sets.json with its synced count and repository', function () {
    $root = dirname(__DIR__, 2);
    $sets = json_decode((string) file_get_contents($root . '/icon-sets.json'), true, flags: JSON_THROW_ON_ERROR)['sets'];
    $doc = (string) file_get_contents($root . '/docs/installation.md');

    preg_match_all('/^\| `(ichava\/[\w-]+)` \| ([^|]+) \| `https:\/\/github\.com\/([\w\/.-]+)` \|$/m', $doc, $rows, PREG_SET_ORDER);

    // Assert the size of what was read, so a table this pattern stops matching fails
    // rather than comparing nothing.
    expect($sets)->not->toBeEmpty();
    $this->assertCount(count($sets), $rows, 'docs/installation.md pack table rows vs icon-sets.json sets');

    foreach ($sets as $i => $set) {
        [, $package, $count, $repository] = $rows[$i];
        $this->assertSame($set['package'], $package, "row {$i} package");
        $this->assertSame(number_format($set['icon_count']), trim($count), "{$package} icon count");
        $this->assertSame($set['repository'], $repository, "{$package} repository");
    }
});
