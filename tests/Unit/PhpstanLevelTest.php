<?php

declare(strict_types=1);

/**
 * PHPStan runs at `level: max` with no baseline (Wave 2, workstream B).
 *
 * The level is read from phpstan.neon.dist itself, so lowering it, or
 * re-including a baseline file, fails here instead of passing quietly in CI.
 */
it('analyses at level max with no baseline', function (): void {
    $config = file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon.dist');

    $this->assertIsString($config, 'phpstan.neon.dist could not be read');

    preg_match_all('/^\s*level:\s*(\S+)\s*$/m', $config, $levels);

    $this->assertSame(['max'], $levels[1], 'phpstan.neon.dist must declare exactly one level, and it must be max');
    $this->assertStringNotContainsString('baseline', $config, 'phpstan.neon.dist must not include a baseline');
    $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/phpstan-baseline.neon');
});

/**
 * Every directory under src/ is analysed. Providers, Commands, Jobs, Listeners,
 * View and Facades were excluded until they were brought up to `level: max`;
 * an `excludePaths` entry naming any of them (or anything inside one) fails
 * here, so the exclusion cannot quietly return.
 */
it('excludes none of the once-excluded src directories from analysis', function (): void {
    $root = dirname(__DIR__, 2);
    $config = file_get_contents($root . '/phpstan.neon.dist');

    $this->assertIsString($config, 'phpstan.neon.dist could not be read');

    $guarded = ['src/Providers', 'src/Commands', 'src/Jobs', 'src/Listeners', 'src/View', 'src/Facades'];

    foreach ($guarded as $directory) {
        $this->assertDirectoryExists($root . '/' . $directory, "{$directory} is gone; update this guard rather than let it check nothing");
    }

    // Every list item under an `excludePaths:` key, at whatever nesting
    // (`excludePaths:` directly, or its `analyse:` / `analyseAndScan:` forms).
    $excluded = [];
    $inExclude = false;
    $excludeIndent = 0;

    foreach (preg_split('/\R/', $config) ?: [] as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }

        $indent = strlen($line) - strlen(ltrim($line));

        if (preg_match('/^\s*excludePaths:/', $line) === 1) {
            $inExclude = true;
            $excludeIndent = $indent;

            continue;
        }

        if ($inExclude && $indent <= $excludeIndent) {
            $inExclude = false;
        }

        if ($inExclude && preg_match('/^\s*-\s*[\'"]?([^\'"#\s]+)/', $line, $match) === 1) {
            $excluded[] = trim($match[1], '/');
        }
    }

    foreach ($excluded as $entry) {
        foreach ($guarded as $directory) {
            $this->assertFalse(
                $entry === $directory || str_starts_with($entry, $directory . '/') || str_starts_with($directory, $entry . '/') || $entry === 'src',
                "phpstan.neon.dist excludes {$entry}, which takes {$directory} out of analysis",
            );
        }
    }

    preg_match_all('/^\s*paths:\s*\R\s*-\s*(\S+)/m', $config, $paths);
    $this->assertSame(['src'], $paths[1], 'phpstan.neon.dist must analyse src/');
});

/**
 * An ignore is allowed only when pinned to one file and an exact count, so it
 * covers the occurrence it was written for and nothing that arrives later.
 */
it('pins every ignored error to a path and a count', function (): void {
    $config = file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon.dist');

    $this->assertIsString($config, 'phpstan.neon.dist could not be read');

    $identifiers = preg_match_all('/^\s*-?\s*identifier:/m', $config);
    $paths = preg_match_all('/^\s*path:\s*src\/\S+\.php\s*$/m', $config);
    $counts = preg_match_all('/^\s*count:\s*\d+\s*$/m', $config);

    $this->assertSame($identifiers, $paths, 'every ignoreErrors entry must name the one file it applies to');
    $this->assertSame($identifiers, $counts, 'every ignoreErrors entry must carry a count');
    $this->assertDoesNotMatchRegularExpression('/^\s*-\s*[\'"]#/m', $config, 'no message-pattern ignores; use an identifier, a path and a count');
});
