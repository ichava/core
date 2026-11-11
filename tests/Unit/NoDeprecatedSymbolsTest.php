<?php

declare(strict_types=1);

/**
 * The ichava estate is forward-only: nothing in core's source is kept working
 * beside its replacement. A superseded symbol is removed and its callers are
 * moved in the same change, so an `@deprecated` tag in `src/` is a symbol that
 * was kept instead of removed.
 *
 * Counts the files it read and fails below a floor, so a glob that stops
 * matching cannot pass over the whole tree.
 */
it('carries no @deprecated tag anywhere in src/', function (): void {
    $root = dirname(__DIR__, 2) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $inspected = 0;
    $found = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $inspected++;
        $contents = (string) file_get_contents($file->getPathname());

        if (str_contains($contents, '@deprecated')) {
            $found[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    expect($inspected)->toBeGreaterThanOrEqual(100, "read only {$inspected} PHP files under src/")
        ->and($found)->toBe([], 'remove the symbol and migrate its callers instead: ' . implode(', ', $found));
});
