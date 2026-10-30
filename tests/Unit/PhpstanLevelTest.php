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
