<?php

declare(strict_types=1);

// bin/sync-icon-sets.php writes icon_counts {icon, illustration} per set into icon-sets.json, so
// ichava.com and the installer can say how many of a set's files are illustrations. The rule must
// match the registry's: the first directory under files/ is the category, and the category's kind
// wins over the pack's.

require_once dirname(__DIR__, 2) . '/bin/sync-icon-sets.php';

/**
 * @param list<string> $paths
 *
 * @return array<string, mixed>
 */
function treeResponse(array $paths, bool $truncated = false): array
{
    return [
        'truncated' => $truncated,
        'tree'      => array_map(static fn (string $path): array => ['path' => $path, 'type' => 'blob'], $paths),
    ];
}

it('counts a category declared as illustrations apart from the pack default', function () {
    $config = [
        'package'  => ['kind' => 'icon'],
        'metadata' => ['data' => ['categories' => [
            'flags'         => ['name' => 'Flags'],
            'illustrations' => ['name' => 'Illustrations', 'kind' => 'illustration'],
        ]]],
    ];

    $counts = countsByKind(['flags/ke.svg', 'flags/us.svg', 'illustrations/scene.svg', 'illustrations/scene-dark.svg'], $config);

    expect($counts)->toBe(['icon' => 2, 'illustration' => 2]);
});

it('uses the pack kind when a category declares none, and icon when the pack declares none', function () {
    expect(countsByKind(['art/a.svg', 'b.svg'], ['package' => ['kind' => 'illustration']]))
        ->toBe(['icon' => 0, 'illustration' => 2])
        ->and(countsByKind(['outline/a.svg', 'b.svg'], []))
        ->toBe(['icon' => 2, 'illustration' => 0]);
});

it('ignores an unknown kind value instead of inventing a bucket', function () {
    $config = ['package' => ['kind' => 'sticker'], 'metadata' => ['data' => ['categories' => ['x' => ['kind' => 'photo']]]]];

    expect(countsByKind(['x/a.svg'], $config))->toBe(['icon' => 1, 'illustration' => 0]);
});

it('reads only SVG blobs under files/ and refuses a truncated tree', function () {
    $tree = treeResponse([
        'resources/assets/svg/files/flags/ke.svg',
        'resources/assets/svg/files/flags/README.md',
        'resources/assets/svg/config.json',
        'tests/fixtures/files/x.svg',
    ]);

    expect(svgPathsInTree($tree, 'resources/assets/svg/config.json'))->toBe(['flags/ke.svg'])
        ->and(svgPathsInTree(treeResponse(['resources/assets/svg/files/a.svg'], true), 'resources/assets/svg/config.json'))->toBeNull();
});

it('keeps both totals in step: the per-kind counts add up to icon_count', function () {
    $paths = ['a/1.svg', 'a/2.svg', 'b/3.svg'];
    $counts = countsByKind($paths, ['metadata' => ['data' => ['categories' => ['b' => ['kind' => 'illustration']]]]]);

    expect(array_sum($counts))->toBe(count($paths));
});
