<?php

declare(strict_types=1);

/**
 * Nothing in this package may invoke a command by a name that no longer exists.
 *
 * Renaming the commands broke six runtime call sites and the suite caught none
 * of them: `Artisan::call()`, `$this->call()`, `$schedule->command()` and a
 * `Str::contains()` guard that sniffs `$_SERVER['argv']` for the running command.
 * They are strings, so nothing type-checks them, and the paths that reach them
 * -- a scheduled task, the installer, a cache rebuild triggered from the browser
 * package -- are not exercised by this suite. The first report came from
 * ichava/browser, against a released tag.
 *
 * This reads the source rather than the container on purpose: the defect is a
 * string literal that is never evaluated until that code path runs, so there is
 * nothing to inspect at runtime until it is already too late.
 */
it('invokes no command by a retired name', function (): void {
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname()) as $n => $line) {
            // Only invocation sites. `ichava:job:progress:` and friends are cache
            // key prefixes that happen to share the separator, and are not commands.
            if (! preg_match('/(->command\(|->call\(|Artisan::call\()\s*[\'"]([^\'"]+)[\'"]/', $line, $m)) {
                continue;
            }

            $name = $m[2];

            if (str_starts_with($name, 'make:') || preg_match('/^ichava:(?!:)/', $name)) {
                $offenders[] = sprintf('%s:%d invokes "%s"', $file->getFilename(), $n + 1, $name);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('sniffs the running command by a name that can actually appear', function (): void {
    // AutoSeedIconsOnRegistration matches $_SERVER['argv'] against command names to
    // skip auto-seeding during an explicit database command. A retired name never
    // appears in argv, so the guard silently stops guarding.
    $src = file_get_contents(__DIR__ . '/../../src/Listeners/AutoSeedIconsOnRegistration.php');

    preg_match_all('/Str::contains\(\$command, \'([^\']+)\'\)/', $src, $m);

    expect($m[1])->not->toBeEmpty();

    foreach ($m[1] as $needle) {
        if (str_starts_with($needle, 'ichava')) {
            expect($needle)->toStartWith('ichava::');
        }
    }
});
