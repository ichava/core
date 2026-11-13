<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconRegistry;

/*
 * The registration path refactor (C-03, Proposal 2) must not change what any
 * pack registers. The snapshot was written by the code before the refactor,
 * from the config.json of every public estate pack (copied under
 * fixtures/RegistrationGolden) and every core fixture pack. Registering the
 * same directories now must produce the same metadata, key for key.
 *
 * Regenerate only on purpose: ICHAVA_WRITE_GOLDEN=1 vendor/bin/pest --filter=RegistrationGolden
 */

function goldenPackDirectories(): array
{
    $root = dirname(__DIR__, 2);
    $dirs = array_merge(
        glob($root . '/tests/fixtures/RegistrationGolden/*', GLOB_ONLYDIR) ?: [],
        [$root . '/resources/assets/svg/test-icons'],
        array_map('dirname', glob($root . '/tests/fixtures/*/config.json') ?: []),
        array_map('dirname', glob($root . '/tests/fixtures/*/*/config.json') ?: []),
        array_map('dirname', glob($root . '/tests/fixtures/*/resources/assets/svg/config.json') ?: []),
    );
    $dirs = array_values(array_filter(array_unique($dirs), static fn (string $dir): bool => is_file($dir . '/config.json')));
    sort($dirs);

    return $dirs;
}

/** @return array<string, mixed> */
function goldenMetadataFor(string $dir): array
{
    $root = dirname(__DIR__, 2);
    $registry = app(IconRegistry::class);

    try {
        $before = array_keys($registry->all());
        $registry->fromDirectory($dir);
        $added = array_values(array_diff(array_keys($registry->all()), $before));
        $name = $added[0] ?? json_decode((string) file_get_contents($dir . '/config.json'), true)['package']['name'];
        $metadata = $registry->all()[$name];
    } catch (Throwable $e) {
        return ['exception' => $e::class, 'message' => str_replace($root, '<root>', $e->getMessage())];
    }

    unset($metadata['registered_at']);
    $metadata['base_path'] = str_replace($root, '<root>', (string) $metadata['base_path']);
    ksort($metadata);

    return ['aliases' => $registry->aliases($name), 'metadata' => $metadata];
}

it('registers every estate and fixture pack exactly as before the refactor', function (): void {
    $root = dirname(__DIR__, 2);
    $snapshotPath = $root . '/tests/fixtures/RegistrationGolden.json';
    $dirs = goldenPackDirectories();

    expect(count($dirs))->toBeGreaterThanOrEqual(31);

    $actual = [];
    foreach ($dirs as $dir) {
        app()->forgetInstance(IconRegistry::class);
        $actual[str_replace($root, '<root>', $dir)] = goldenMetadataFor($dir);
    }

    if (getenv('ICHAVA_WRITE_GOLDEN') === '1') {
        file_put_contents($snapshotPath, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    expect($actual)->toBe(json_decode((string) file_get_contents($snapshotPath), true));
});
