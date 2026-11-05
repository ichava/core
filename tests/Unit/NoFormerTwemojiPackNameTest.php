<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Twemoji pack is named by its current name
|--------------------------------------------------------------------------
|
| The emoji pack was renamed to `ichava/icon-sets-twemoji` (2026-11-05),
| forward only: no alias, no fallback catalogue key, no `replaces`. Core names
| it in its catalogue, docs, comments and fixtures, so the old name has no
| business here except in the history that records it:
|
|   - CHANGELOG.md, whose entries keep the name the pack had at the time;
|   - one comment in .github/workflows/changelog.yml, byte-identical across
|     the estate, which cites a past CHANGELOG defect by the pack's name then.
|
| The former name is assembled from parts so this file does not match itself.
|
*/

function core_former_twemoji_pack_name(): string
{
    return 'icon-sets-' . 'emoji';
}

/** @return list<string> paths relative to the repository root */
function core_former_name_scanned_files(): array
{
    $root = dirname(__DIR__, 2);
    $skip = ['.git', 'vendor', 'node_modules', '.phpstan.cache', '.phpunit.cache', '.idea', 'build'];
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), $skip, true)),
        ),
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    sort($files);

    return $files;
}

it('names the Twemoji pack by its current name everywhere outside history', function (): void {
    $root = dirname(__DIR__, 2);
    $former = core_former_twemoji_pack_name();
    $files = core_former_name_scanned_files();
    $offenders = [];

    foreach ($files as $path) {
        if ($path === 'CHANGELOG.md') {
            continue;
        }

        $lines = file($root . '/' . $path, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $number => $line) {
            if (! str_contains($line, $former)) {
                continue;
            }

            if ($path === '.github/workflows/changelog.yml' && str_contains($line, $former . ' had [0.2.6] filed')) {
                continue;
            }

            $offenders[] = $path . ':' . ($number + 1);
        }
    }

    // src, tests, fixtures, docs and workflows: 484 tracked files on
    // 2026-11-05. A scan that stops finding files reports a clean tree.
    expect(count($files))->toBeGreaterThan(400)
        ->and($offenders)->toBe([], 'the former Twemoji pack name is back in: ' . implode(', ', $offenders));
});

it('catalogues the Twemoji pack under its current name and key', function (): void {
    $sets = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/icon-sets.json'), true, flags: JSON_THROW_ON_ERROR)['sets'];
    $twemoji = array_values(array_filter($sets, static fn (array $set): bool => $set['package'] === 'ichava/icon-sets-twemoji'));

    expect($twemoji)->toHaveCount(1)
        ->and($twemoji[0]['key'])->toBe('twemoji')
        ->and($twemoji[0]['repository'])->toBe('ichava/icon-sets-twemoji');
});
