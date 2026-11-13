<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;

/**
 * A `--path` handed to `migrate` must resolve on disk.
 *
 * `runMigrations()` passed the literal `platform/ichava/ichava/database/migrations`,
 * a layout from another project entirely. `migrate --path` against a directory
 * that does not exist runs nothing and **exits 0** — so the command printed its
 * success outro and created no tables, in every consuming application, for the
 * whole life of the package.
 *
 * Nothing caught it: the suite runs migrations through Testbench, which loads the
 * registered path directly and never calls this service. It surfaced only by
 * running `ichava::ichava-core.database migrate` in a real application and then
 * looking at `sqlite_master` rather than at the exit code.
 */
it('ships the migration directory the service resolves', function (): void {
    $resolved = realpath(dirname((new ReflectionClass(DatabaseOperationsService::class))->getFileName()) . '/../../database/migrations');

    expect($resolved)->not->toBeFalse()
        ->and(glob($resolved . '/*.php'))->not->toBeEmpty();
});

it('passes migrate no relative --path literal', function (): void {
    // A relative path is resolved against the *host application's* working
    // directory, not this package's, so it can only ever be right by accident.
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $n => $line) {
            if (! preg_match("/'--path'\s*=>\s*'([^']+)'/", $line, $m)) {
                continue;
            }

            if (! str_starts_with($m[1], '/')) {
                $offenders[] = sprintf('%s:%d passes --path %s', $file->getFilename(), $n + 1, $m[1]);
            }
        }
    }

    expect($offenders)->toBe([]);
});
