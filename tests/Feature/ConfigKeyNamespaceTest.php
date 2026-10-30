<?php

declare(strict_types=1);

/**
 * Core's config lives at `ichava.ichava-core.*` (C-F2).
 *
 * The provider names the package `ichava-core` and ships
 * `config/ichava-core.php`, so package-tools merges it at exactly
 * `ichava.ichava-core`. A read site using any other root -- `ichava.core.*`, or a
 * bare `ichava.<section>.*` -- gets null and falls through to its inline default,
 * and no other test notices because the defaults make the result look right.
 */
it('merges the shipped config at ichava.ichava-core', function (): void {
    expect(config('ichava.ichava-core'))->toBeArray()
        ->and(config('ichava.ichava-core.prefix'))->toBe('ichava')
        ->and(config('ichava.ichava-core.cache.prefix'))->toBe('ichava')
        ->and(config('ichava.ichava-core.database.batch_size'))->toBeInt()
        ->and(config('ichava.ichava-core.logging.channel'))->toBeString();
});

it('does not merge anything at the old ichava.core key', function (): void {
    expect(config('ichava.core'))->toBeNull();
});

it('reads every ichava config key under ichava.ichava-core in src', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

    $scanned = 0;
    $reads = 0;
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;
        $code = (string) file_get_contents($file->getPathname());

        // config('ichava.…'), config("ichava.…"), config()->get('ichava.…'), Config::get('ichava.…')
        preg_match_all('/(?:\bconfig\(|->get\(|Config::get\()\s*([\'"])(ichava(?:\.[^\'"]*)?)\1/', $code, $matches);

        foreach ($matches[2] as $key) {
            $reads++;

            if ($key !== 'ichava.ichava-core' && ! str_starts_with($key, 'ichava.ichava-core.')) {
                $offenders[] = substr($file->getPathname(), strlen($src) + 1) . ': ' . $key;
            }
        }
    }

    // A glob that stops matching would report a clean tree. 96 files and 65 reads
    // were measured on 2026-10-30; the floors sit below both.
    $this->assertGreaterThanOrEqual(80, $scanned, "Only {$scanned} PHP files scanned under src/");
    $this->assertGreaterThanOrEqual(50, $reads, "Only {$reads} ichava config reads found under src/");
    $this->assertSame([], $offenders, "Config reads outside ichava.ichava-core:\n" . implode("\n", $offenders));
});
