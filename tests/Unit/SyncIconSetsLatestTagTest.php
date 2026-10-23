<?php

declare(strict_types=1);

// bin/sync-icon-sets.php writes latest_version into icon-sets.json from a pack's
// tags. The tags API lists every tag, including names that are not releases
// (src/v0.1.0 Go aliases, archive/ or wip/ tags), in an order it does not
// document. Taking whichever comes first can publish "archive/v9.9.9" or a
// pre-reset version as the pack's latest.

require_once dirname(__DIR__, 2) . '/bin/sync-icon-sets.php';

/**
 * @param list<string> $names
 *
 * @return list<array{name: string}>
 */
function tagsResponse(array $names): array
{
    return array_map(static fn (string $name): array => ['name' => $name], $names);
}

it('ignores tags that are not a plain vX.Y.Z release, wherever the API lists them', function () {
    $tags = tagsResponse(['archive/v9.9.9', 'src/v0.1.0', 'wip/peer-merge-resolution', 'v0.1.0']);

    expect(highestVersionTag($tags))->toBe('v0.1.0');
});

it('picks the highest version by semver, not by listing order or string order', function () {
    $tags = tagsResponse(['v0.9.0', 'v0.10.0', 'v0.2.1']);

    expect(highestVersionTag($tags))->toBe('v0.10.0');
});

it('skips pre-releases and malformed names', function () {
    $tags = tagsResponse(['v1.0.0-rc.1', 'v1.0', 'version-2', 'v0.3.4']);

    expect(highestVersionTag($tags))->toBe('v0.3.4');
});

it('answers null when no tag is a release, so the previous snapshot is kept', function () {
    expect(highestVersionTag(tagsResponse(['archive/v0.3.4', 'src/v0.1.0'])))->toBeNull()
        ->and(highestVersionTag([]))->toBeNull()
        ->and(highestVersionTag([['commit' => []], ['name' => 42]]))->toBeNull();
});

it('asks the tags API for more than one tag', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/sync-icon-sets.php');

    expect($source)->not->toContain('tags?per_page=1"')
        ->and($source)->toContain('tags?per_page=100');
});
