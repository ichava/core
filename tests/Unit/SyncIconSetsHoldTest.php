<?php

declare(strict_types=1);

// bin/sync-icon-sets.php reads each pack at its latest release tag. When a pack's
// default branch has moved past that tag (icon-sets-twemoji dropped OpenMoji after
// v0.1.0), the catalogue records the branch's values by hand and the nightly sync
// would put the tag's old values back (core#176 proposed 10,567 "Emoji Sets" over
// 3,435 "Twemoji"). A set's `hold` keeps its snapshot while the tag it names is
// still the latest and still points at the recorded commit.

require_once dirname(__DIR__, 2) . '/bin/sync-icon-sets.php';

/**
 * @param array<string, string> $tags name => commit sha
 *
 * @return list<array{name: string, commit: array{sha: string}}>
 */
function holdTagsResponse(array $tags): array
{
    $response = [];

    foreach ($tags as $name => $sha) {
        $response[] = ['name' => $name, 'commit' => ['sha' => $sha]];
    }

    return $response;
}

/**
 * @return array<string, mixed>
 */
function heldSet(?array $hold): array
{
    return ['key' => 'twemoji', 'icon_count' => 3435] + ($hold === null ? [] : ['hold' => $hold]);
}

it('holds a set while its tag is the latest release at the recorded commit', function (): void {
    $set = heldSet(['tag' => 'v0.1.0', 'commit' => 'aaa', 'reason' => 'branch ahead of tag']);

    expect(isHeld($set, holdTagsResponse(['src/v0.1.0' => 'aaa', 'v0.1.0' => 'aaa'])))->toBeTrue();
});

it('lifts the hold once a newer release is tagged', function (): void {
    $set = heldSet(['tag' => 'v0.1.0', 'commit' => 'aaa']);

    expect(isHeld($set, holdTagsResponse(['v0.1.0' => 'aaa', 'v0.2.0' => 'bbb'])))->toBeFalse();
});

it('lifts the hold once the tag points at a different commit', function (): void {
    $set = heldSet(['tag' => 'v0.1.0', 'commit' => 'aaa']);

    expect(isHeld($set, holdTagsResponse(['v0.1.0' => 'ccc'])))->toBeFalse();
});

it('never holds a set without a complete hold', function (?array $hold): void {
    expect(isHeld(heldSet($hold), holdTagsResponse(['v0.1.0' => 'aaa'])))->toBeFalse();
})->with([
    'no hold'        => [null],
    'no commit'      => [['tag' => 'v0.1.0']],
    'no tag'         => [['commit' => 'aaa']],
    'an empty value' => [['tag' => '', 'commit' => 'aaa']],
]);

it('records a complete hold for every held set in icon-sets.json', function (): void {
    $catalog = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/icon-sets.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($catalog['sets'])->not->toBeEmpty();

    foreach ($catalog['sets'] as $set) {
        if (! array_key_exists('hold', $set)) {
            continue;
        }

        expect($set['hold']['tag'] ?? null)->toMatch('/^v\d+\.\d+\.\d+$/', "{$set['key']} hold has no release tag")
            ->and($set['hold']['commit'] ?? null)->toMatch('/^[0-9a-f]{40}$/', "{$set['key']} hold has no commit")
            ->and($set['hold']['reason'] ?? '')->not->toBe('', "{$set['key']} hold has no reason");
    }
});
