<?php

declare(strict_types=1);

/**
 * The package names Simtabi LLC, never a person (W-20, XR-3).
 *
 * Decided 2026-10-28: no personal name or email is published. Contact goes
 * through role addresses such as `ichava@simtabi.com`. This reads every file
 * that ships or documents the package -- the manifest, README, LICENSE, docs/
 * and src/ -- and fails on a personal name or address. CHANGELOG.md is history
 * and is not read.
 */
it('publishes no personal name or email', function (): void {
    $root = dirname(__DIR__, 2);

    $files = [$root . '/composer.json', $root . '/README.md', $root . '/LICENSE'];

    foreach (['docs', 'src'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
    }

    $offenders = [];

    foreach ($files as $path) {
        $this->assertFileExists($path);

        foreach (file($path) ?: [] as $number => $line) {
            if (preg_match('/imani@|manyara/i', $line) === 1) {
                $offenders[] = substr($path, strlen($root) + 1) . ':' . ($number + 1);
            }
        }
    }

    // 3 root files plus 116 under docs/ and src/ on 2026-10-30. A glob that
    // stopped matching would otherwise pass over the whole tree.
    $this->assertGreaterThanOrEqual(100, count($files), 'Only ' . count($files) . ' files read');
    $this->assertSame([], $offenders, "Personal name or email published in:\n" . implode("\n", $offenders));
});

it('names Simtabi LLC as the author', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['authors'])->toBe([[
        'name'     => 'Simtabi LLC',
        'email'    => 'ichava@simtabi.com',
        'homepage' => 'https://simtabi.com',
        'role'     => 'Developer',
    ]]);
});
