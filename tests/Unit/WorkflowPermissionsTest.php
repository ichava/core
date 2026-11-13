<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every workflow declares least-privilege permissions at the top
|--------------------------------------------------------------------------
|
| A workflow with no top-level `permissions:` runs with whatever the
| repository's or the organisation's default token grants, which can be write
| on everything. Each file states its own ceiling instead, and the ceiling is
| read-only (`contents: read`, or `{}` where no step uses GITHUB_TOKEN at all).
| A job that needs a write asks for it in its own `permissions:` block, so the
| grant is visible beside the step that uses it.
|
| Read line by line rather than through a YAML parser, so the guard depends on
| nothing the package does not already require.
|
*/

/**
 * @return array<string, string>
 */
function ichavaWorkflowFiles(): array
{
    $dir = dirname(__DIR__, 2) . '/.github/workflows';
    $files = array_merge(glob($dir . '/*.yml') ?: [], glob($dir . '/*.yaml') ?: []);
    sort($files);

    $contents = [];
    foreach ($files as $file) {
        $contents[basename($file)] = (string) file_get_contents($file);
    }

    return $contents;
}

/**
 * The top-level `permissions:` block, as its inline value plus its indented lines,
 * or null when the file has none.
 */
function ichavaTopLevelPermissions(string $yaml): ?string
{
    $lines = preg_split('/\R/', $yaml) ?: [];
    $block = null;

    foreach ($lines as $line) {
        if ($block === null) {
            if (preg_match('/^permissions:(.*)$/', $line, $m) === 1) {
                $block = trim($m[1]);
            }

            continue;
        }

        // The block ends at the next top-level key.
        if (preg_match('/^[A-Za-z_]/', $line) === 1) {
            break;
        }

        $block .= "\n" . $line;
    }

    return $block;
}

it('inspects every workflow file', function () {
    // A glob that stops matching would pass the checks below over nothing.
    expect(count(ichavaWorkflowFiles()))->toBeGreaterThanOrEqual(6);
});

it('declares a top-level permissions block in every workflow', function () {
    $missing = [];
    foreach (ichavaWorkflowFiles() as $name => $yaml) {
        if (ichavaTopLevelPermissions($yaml) === null) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([]);
});

it('grants no write at the top level, only per job', function () {
    $writes = [];
    foreach (ichavaWorkflowFiles() as $name => $yaml) {
        $block = (string) ichavaTopLevelPermissions($yaml);

        if (preg_match('/\b(write|write-all)\b/', $block) === 1 || str_contains($block, 'read-all')) {
            $writes[] = $name;
        }
    }

    expect($writes)->toBe([]);
});
