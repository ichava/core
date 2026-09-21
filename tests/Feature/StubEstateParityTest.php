<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

/**
 * The stub tree must not drift from the estate it scaffolds into.
 *
 * Eight defects shipped because nothing compared the two. Every one of them was
 * fixed in the five real packs and never propagated to `stubs/icon-package/`,
 * so the scaffolder kept emitting conventions the estate had already retired:
 *
 *   • `"ichava/core": "^1.0"`            -- a version that has never existed
 *   • `"php": "^8.3"`                    -- floor two minors below the estate
 *   • `illuminate/support: ^10|^12|^13`  -- stale, and skipping 11
 *   • `laranail/package-tools` omitted   -- while the provider imports it
 *   • `Simtabi\Laranail\PackageTools\*`  -- the pre-migration namespace
 *   • `ichava:update-<pack>-icons`       -- the bare name `V59` forbids
 *   • `orchestra/testbench: ^8|^10|^11`  -- stale
 *   • `pestphp/pest: ^2|^3`              -- two majors behind
 *
 * So this asserts against **a real pack's manifest**, read off disk, rather
 * than against literals. Hardcoded expectations are what let the drift happen:
 * a literal encodes the estate as it was on the day the test was written, and
 * then ages silently alongside the thing it was supposed to guard. A fixture
 * that IS the estate cannot.
 *
 * When this fails, the stub is behind (or ahead of) the packs. Fix whichever
 * is actually wrong -- the test does not care which, only that they agree.
 */
beforeEach(function () {
    $this->scaffoldRoot = sys_get_temp_dir() . '/ichava-stub-parity-' . uniqid();
});

afterEach(function () {
    if (! empty($this->scaffoldRoot) && is_dir($this->scaffoldRoot)) {
        (new Filesystem)->deleteDirectory($this->scaffoldRoot);
    }
});

/**
 * A real, shipped pack to measure the stub against.
 *
 * `flag-icons` rather than any other: it is public, it carries the full
 * manifest shape (repositories, scripts, branch-alias, support.security) and
 * all four workflows, and it is the pack whose `<use>` elements the SVG policy
 * corpus run already tracks, so it is unlikely to be quietly retired.
 */
function estatePackPath(): ?string
{
    $candidate = dirname(__DIR__, 3) . '/flag-icons';

    return is_dir($candidate) && is_file($candidate . '/composer.json') ? $candidate : null;
}

function estatePackManifest(): ?array
{
    $path = estatePackPath();

    return $path === null ? null : json_decode((string) file_get_contents($path . '/composer.json'), true);
}

function scaffoldForParity(string $root): void
{
    test()->artisan('ichava::ichava-core.make:icon-package', [
        'name'     => 'Parity',
        '--vendor' => 'Ichava',
        '--email'  => 'opensource@simtabi.com',
        '--prefix' => 'pa',
        '--type'   => 'single',
        '--path'   => $root,
        '--force'  => true,
    ])->assertSuccessful();
}

/**
 * The monorepo sibling is not present in a standalone checkout of `core`, and
 * CI clones one repo. Skipping is correct there: this guard is a working-tree
 * check, and a false red in CI would train people to ignore it.
 */
function skipWithoutEstate(): void
{
    if (estatePackPath() === null) {
        test()->markTestSkipped('flag-icons sibling not present; parity guard needs the monorepo working tree.');
    }
}

it('scaffolds the same runtime constraints a real pack declares', function () {
    skipWithoutEstate();
    scaffoldForParity($this->scaffoldRoot);

    $generated = json_decode((string) file_get_contents($this->scaffoldRoot . '/composer.json'), true);
    $estate = estatePackManifest();

    foreach (['php', 'illuminate/support', 'ichava/core', 'laranail/package-tools'] as $dependency) {
        // toHaveKey($key, $value) asserts presence AND equality in one step, so
        // a stub that omits the dependency and one that pins it differently
        // both fail here, which is what drift looks like in either direction.
        expect($estate['require'])->toHaveKey($dependency);
        expect($generated['require'] ?? [])->toHaveKey($dependency, $estate['require'][$dependency]);
    }
});

it('scaffolds the same dev toolchain a real pack declares', function () {
    skipWithoutEstate();
    scaffoldForParity($this->scaffoldRoot);

    $generated = json_decode((string) file_get_contents($this->scaffoldRoot . '/composer.json'), true);
    $estate = estatePackManifest();

    foreach (['orchestra/testbench', 'pestphp/pest'] as $dependency) {
        expect($estate['require-dev'])->toHaveKey($dependency);
        expect($generated['require-dev'] ?? [])->toHaveKey($dependency, $estate['require-dev'][$dependency]);
    }
});

it('scaffolds the VCS repositories a pack needs, because nothing here is on Packagist', function () {
    skipWithoutEstate();
    scaffoldForParity($this->scaffoldRoot);

    $generated = json_decode((string) file_get_contents($this->scaffoldRoot . '/composer.json'), true);

    // Without these a generated package cannot resolve `ichava/core` at all:
    // `repo.packagist.org` answers 404 for every `ichava/*` and `laranail/*`.
    $urls = array_column($generated['repositories'] ?? [], 'url');

    expect($urls)->not->toBeEmpty('Scaffolded packages have no VCS repositories and cannot resolve ichava/core.');
    expect(implode(' ', $urls))->toContain('ichava/core');
});

it('scaffolds a provider importing the namespace package-tools actually publishes', function () {
    skipWithoutEstate();
    scaffoldForParity($this->scaffoldRoot);

    $generated = (string) file_get_contents($this->scaffoldRoot . '/src/Providers/IconsServiceProvider.php');
    $estate = (string) file_get_contents(estatePackPath() . '/src/Providers/IconsServiceProvider.php');

    // Read the namespace out of the real pack rather than naming it here, so an
    // upstream rename is caught instead of being encoded twice.
    preg_match('/^use (Simtabi\\\\Laranail\\\\[A-Za-z\\\\]+)\\\\Package;$/m', $estate, $matches);
    expect($matches)->toHaveCount(2, 'flag-icons no longer imports a package-tools Package class.');

    // Scaffolded provider must import the same package-tools namespace
    // flag-icons does, and must not carry the pre-migration spelling.
    expect($generated)->toContain("use {$matches[1]}\\Package;");
    expect($generated)->not->toContain('Laranail\\PackageTools\\');
});

it('scaffolds a namespaced update command, never a bare one', function () {
    skipWithoutEstate();
    scaffoldForParity($this->scaffoldRoot);

    $command = (string) file_get_contents($this->scaffoldRoot . '/src/Commands/UpdateIconsCommand.php');

    // `V59`: Artisan's command table is a flat map, so a bare generic slug does
    // not collide loudly -- it replaces whatever got there first.
    expect($command)->toMatch('/\$signature\s*=\s*\'ichava::[a-z0-9-]+\.[a-z-]+/');
    expect($command)->not->toMatch('/\$signature\s*=\s*\'ichava:[a-z]/');
});

it('scaffolds the workflows every real pack ships', function () {
    skipWithoutEstate();
    scaffoldForParity($this->scaffoldRoot);

    $estateWorkflows = glob(estatePackPath() . '/.github/workflows/*.yml') ?: [];
    expect($estateWorkflows)->not->toBeEmpty('flag-icons ships no workflows to compare against.');

    foreach ($estateWorkflows as $workflow) {
        $name = basename($workflow);
        expect(file_exists($this->scaffoldRoot . '/.github/workflows/' . $name))->toBeTrue(
            "Scaffolded packages are missing {$name}, which flag-icons ships.",
        );
    }
});

it('scaffolds the docs pages a real pack ships, and no docs index', function () {
    skipWithoutEstate();
    scaffoldForParity($this->scaffoldRoot);

    // One README per repo. The index is the package README's own docs section,
    // and a standalone docs/README.md duplicates it and then drifts -- which is
    // why no pack in the estate has one. The stub shipped one from `Initial
    // release`, so every scaffolded pack diverged on its first commit.
    expect(file_exists($this->scaffoldRoot . '/docs/README.md'))->toBeFalse(
        'Scaffolded packages must not ship a docs/README.md index; the package README carries it.',
    );

    // Deleting the index must not take its links with it: the pages it listed
    // still have to be scaffolded, and the README still has to reach them.
    foreach (glob(estatePackPath() . '/docs/*.md') ?: [] as $page) {
        $name = basename($page);
        expect(file_exists($this->scaffoldRoot . '/docs/' . $name))->toBeTrue(
            "Scaffolded packages are missing docs/{$name}, which flag-icons ships.",
        );
        // toContain() takes variadic needles, not a message, so assert the
        // predicate instead -- otherwise the message becomes a second needle.
        $readme = (string) file_get_contents($this->scaffoldRoot . '/README.md');
        expect(str_contains($readme, 'docs/' . $name))->toBeTrue(
            "The package README does not link docs/{$name}; nothing else indexes it now.",
        );
    }
});

it('scaffolds workflows that trigger on pull_request, never on a branch push', function () {
    skipWithoutEstate();
    scaffoldForParity($this->scaffoldRoot);

    foreach (glob($this->scaffoldRoot . '/.github/workflows/*.yml') ?: [] as $workflow) {
        $body = (string) file_get_contents($workflow);
        $name = basename($workflow);

        // The only legitimate push trigger is a tag, for the release workflow.
        if (preg_match('/^\s*push:/m', $body)) {
            expect($body)->toMatch(
                '/push:\s*\n\s*tags:/',
                "{$name} triggers on push without a tag filter; CI must run on pull_request.",
            );
        }
    }
});
