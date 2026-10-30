<?php

declare(strict_types=1);

use Composer\Semver\Semver;

/*
|--------------------------------------------------------------------------
| Every ichava/* constraint admits 0.1.0
|--------------------------------------------------------------------------
|
| At release every ichava repository becomes one root commit tagged v0.1.0, so
| 0.1.0 is the only version of any sibling that exists. A constraint that
| excludes it, such as a floor raised to ^0.1.2, cannot resolve once released.
|
| CI tests against the siblings' main branches by aliasing them in the
| workflow and restoring composer.json after the install, so this reads the
| committed constraints, never the CI alias.
|
| Pest functions only: a PHPUnit class in the same file would stop being
| collected.
|
*/

/**
 * @return array<string, string> "section.package" => constraint
 */
function siblingConstraintsInComposerJson(): array
{
    $path = dirname(__DIR__, 2) . '/composer.json';
    $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $found = [];
    foreach (['require', 'require-dev'] as $section) {
        foreach ($composer[$section] ?? [] as $package => $constraint) {
            if (str_starts_with((string) $package, 'ichava/')) {
                $found["{$section}.{$package}"] = (string) $constraint;
            }
        }
    }

    return $found;
}

it('admits 0.1.0 in every ichava/* constraint', function (): void {
    $constraints = siblingConstraintsInComposerJson();

    $this->assertNotEmpty($constraints, 'composer.json names no ichava/* constraint, so nothing was checked.');

    foreach ($constraints as $where => $constraint) {
        $this->assertTrue(
            Semver::satisfies('0.1.0', $constraint),
            "{$where} is '{$constraint}', which the released 0.1.0 does not satisfy.",
        );
    }
});
