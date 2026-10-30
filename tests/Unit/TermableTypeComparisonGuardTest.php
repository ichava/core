<?php

declare(strict_types=1);

/*
 * `termable_type` holds the morph alias (`icon`), never the Icon class name.
 *
 * Three readers compared it with the class name -- the PostgreSQL search
 * clause, `IconTerm::getTotalIconCount()` and the search-text functions the
 * migration installs -- so on PostgreSQL no category or variant name was ever
 * searchable. Each spelled the class name differently (`Icon::class`, a
 * constant set to it, an escaped string literal), which is why this guard
 * looks for all three rather than one pattern.
 *
 * Comments are stripped first, so prose explaining the bug does not trip it.
 */
function phpFilesUnder(string ...$dirs): array
{
    $files = [];

    foreach ($dirs as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

function codeWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

it('never compares termable_type with the Icon class name', function (): void {
    $root = dirname(__DIR__, 2);
    $files = phpFilesUnder($root . '/src', $root . '/database');
    $offenders = [];
    $termableFiles = 0;

    // The class name as a string literal, with any amount of backslash escaping.
    $fqcnLiteral = '/[\'"]\\\\*Simtabi\\\\+Laranail\\\\+Ichava\\\\+Models\\\\+Icon[\'"]/';

    foreach ($files as $path) {
        $code = codeWithoutComments($path);
        $name = substr($path, strlen($root) + 1);

        if (preg_match($fqcnLiteral, $code)) {
            $offenders[] = "{$name}: spells the Icon class name as a string literal";
        }

        if (! str_contains($code, 'termable_type')) {
            continue;
        }

        $termableFiles++;

        foreach (explode(';', $code) as $statement) {
            if (str_contains($statement, 'termable_type') && str_contains($statement, 'Icon::class')) {
                $offenders[] = "{$name}: compares termable_type with Icon::class";
            }
        }

        if (preg_match('/const\s+(?:string\s+)?\w+\s*=\s*Icon::class/', $code)) {
            $offenders[] = "{$name}: holds Icon::class in a constant beside a termable_type comparison";
        }
    }

    expect(count($files))->toBeGreaterThanOrEqual(100)
        ->and($termableFiles)->toBeGreaterThanOrEqual(5)
        ->and($offenders)->toBe([]);
});
