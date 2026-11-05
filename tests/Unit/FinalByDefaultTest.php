<?php

declare(strict_types=1);

/*
 * C-14. A concrete class in src/ is `final` unless it is listed here with the
 * reason it stays open. Each reason was measured on 2026-11-05 across
 * ichava/packages, demos included: an `extends` of the class (direct or
 * through an import alias), or a Mockery mock of it. An entry whose reason
 * goes away should be removed, and the class made final.
 */

const ICHAVA_OPEN_CLASSES = [
    // 24 extenders: every pack's component plus core's test component.
    'IconComponent' => 'the class every pack component extends',
    // icon-browser type-hints both and its tests bind a subclass of the
    // checker; they close once icon-browser uses the contracts (C-06).
    'IconPackUpdateChecker'     => 'test seam in icon-browser; core consumers use Contracts\ChecksPackUpdates',
    'DatabaseOperationsService' => 'partial mocks in core and icon-browser; core consumers use Contracts\DatabaseOperations',
    // Partial mocks in core's command characterization tests.
    'CacheOperationsService' => 'Mockery partial mock in CacheCommandCharacterizationTest',
    'IconSetCatalogService'  => 'Mockery partial mock in InstallCommandCharacterizationTest',
    'IchavaLifecycleManager' => 'Mockery mock in LoggerErrorCallSitesTest',
    'IconDiscoveryService'   => 'Mockery mock in LoggerErrorCallSitesTest',
];

it('makes every concrete class in src final unless it is listed with a reason', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS));
    $inspected = 0;
    $open = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $inspected++;

        if (preg_match('/^(?:readonly\s+)?class\s+(\w+)/m', (string) file_get_contents($file->getPathname()), $match) === 1) {
            $open[] = $match[1];
        }
    }

    expect($inspected)->toBeGreaterThanOrEqual(130)
        ->and(array_values(array_diff($open, array_keys(ICHAVA_OPEN_CLASSES))))->toBe([])
        ->and(array_values(array_diff(array_keys(ICHAVA_OPEN_CLASSES), $open)))->toBe([]);
});
