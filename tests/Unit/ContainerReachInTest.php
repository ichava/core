<?php

declare(strict_types=1);

/*
 * C-05. A collaborator is injected, not fetched with app() inside a method.
 * The files below were cleared of reach-ins; a ceiling of 0 keeps them so.
 * The rest of src/ still holds reach-ins (71 measured 2026-11-05, from 76):
 * the model, the seeder, the static HostCapabilities singleton and the
 * IconCacheService -> IconWatcherService edge, which stays lazy because the
 * watcher's registry needs SvgDriver, which needs the cache service.
 */

it('keeps the cleared services free of container reach-ins', function (string $file): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/' . $file);
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    expect(preg_match_all('/\bapp\(/', (string) $code))->toBe(0);
})->with([
    'Services/IconRegistry.php',
    'Services/IconWatcherService.php',
    'Services/ConfigurationService.php',
    'Support/IchavaSessionManager.php',
    'Support/PackConfigCache.php',
    'Support/Pack/PackBuilder.php',
    'Support/Pack/PackSpec.php',
]);
