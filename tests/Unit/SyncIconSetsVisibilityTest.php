<?php

declare(strict_types=1);

// bin/sync-icon-sets.php records each catalogued pack's GitHub visibility, so
// a consumer reads one source for which packs are public and which private
// (F-02). The value comes from the repos API; a response that does not state
// it keeps the previous value rather than erasing it.

require_once dirname(__DIR__, 2) . '/bin/sync-icon-sets.php';

it('reads public and private from the repos API response', function (string $visibility): void {
    expect(visibilityOf(['visibility' => $visibility], null))->toBe($visibility);
})->with(['public', 'private']);

it('keeps the recorded visibility when the API does not say', function (?array $response): void {
    expect(visibilityOf($response, 'private'))->toBe('private');
})->with([
    'a failed request'        => [null],
    'a 404 for a hidden repo' => [['message' => 'Not Found']],
    'an unknown value'        => [['visibility' => 'internal']],
]);

it('records no visibility it has never seen', function (): void {
    expect(visibilityOf(null, null))->toBeNull();
});

it('records a visibility for every catalogued pack', function (): void {
    $catalog = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/icon-sets.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($catalog['sets'])->not->toBeEmpty();

    foreach ($catalog['sets'] as $set) {
        expect($set['visibility'] ?? null)->toBeIn(['public', 'private'], "{$set['key']} has no visibility");
    }
});
