<?php

declare(strict_types=1);

/*
 * C-07. Every service provider sits in a Providers/ directory and declares a
 * namespace ending in \Providers. Two separate facts, both checked, because a
 * bad move can leave them disagreeing. Read from the filesystem: a provider
 * at the root of src/ boots perfectly well, which is why nothing else notices.
 */

it('keeps every service provider in a Providers namespace and directory', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS));
    $providers = [];

    foreach ($files as $file) {
        if (str_ends_with($file->getFilename(), 'ServiceProvider.php')) {
            $providers[] = $file->getPathname();
        }
    }

    expect(count($providers))->toBeGreaterThanOrEqual(4);

    foreach ($providers as $path) {
        preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($path), $match);

        expect(basename(dirname($path)))->toBe('Providers', $path)
            ->and(str_ends_with($match[1] ?? '', '\\Providers'))->toBeTrue($path);
    }
});
