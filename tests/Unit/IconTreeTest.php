<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconBrowserService;

/**
 * W1-16 (React data-client wiring). `buildIconTree()` -> `scanFolderTree()` had
 * no tests at all, which is how it shipped serving each folder node's
 * ABSOLUTE server filesystem path in the `GET /icons/tree` response body --
 * found while writing the React REST client's tree normalizer, which had to
 * explicitly discard the field rather than forward it.
 *
 * `scanFolderTree` is exercised directly via reflection, against the real
 * `test-icons/` package on disk, rather than through `buildIconTree()`'s full
 * registry+DB path: `buildIconTree()` short-circuits to an empty tree unless
 * `Icon` rows AND their category terms are seeded, which is real setup that
 * belongs to whatever eventually tests the DB-driven half of this method, not
 * to a test whose only claim is "the path key is gone".
 */
function scanTestIconsFolder(): array
{
    $service = app(IconBrowserService::class);
    $method = (new ReflectionClass($service))->getMethod('scanFolderTree');
    $method->setAccessible(true);

    $basePath = realpath(__DIR__.'/../../resources/assets/svg/test-icons');

    return $method->invoke($service, $basePath, 'ichava/test-icons', []);
}

function assertNoPathKeyRecursive(array $nodes): void
{
    foreach ($nodes as $node) {
        expect($node)->not->toHaveKey('path');
        if (! empty($node['children'])) {
            assertNoPathKeyRecursive($node['children']);
        }
    }
}

it('never serialises a folder node\'s server filesystem path', function () {
    $tree = scanTestIconsFolder();

    expect($tree)->not->toBeEmpty();
    assertNoPathKeyRecursive($tree);
});

it('still carries everything the client actually renders a tree from', function () {
    $tree = scanTestIconsFolder();
    $folder = $tree[0];

    expect($folder)->toHaveKeys(['id', 'type', 'name', 'label', 'icon_count', 'package', 'depth', 'children'])
        ->and($folder['icon_count'])->toBeGreaterThan(0);
});
