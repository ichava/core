<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

/**
 * Command names, asserted against the registry Artisan actually holds.
 *
 * Artisan's command table is a flat map keyed by name: a second package claiming
 * a key does not collide loudly, it replaces the first, and the damage surfaces
 * far away as a command that runs someone else's code. So every name this
 * package registers carries its vendor and slug.
 *
 * These read `Kernel::all()` rather than `$signature`, because grepping the
 * source proves how registration was written, not what the framework ended up
 * with -- and the `::` name only survives because a trait writes it past
 * Symfony's validateName(). A test that reads the property would pass against a
 * registration that never took effect.
 */
/**
 * Commands declared by *this repository*, keyed by every name they answer to.
 *
 * Scoped by the file the class is declared in, not by namespace prefix. A
 * dependency can register a command into this vendor's namespace -- CI caught
 * `laranail/package-tools` doing exactly that with a bare `ichava:install` on a
 * version this suite does not resolve locally -- and that is upstream's to fix,
 * not something this suite can assert away.
 */
function ichavaCommands(): array
{
    $src = realpath(__DIR__ . '/../../src') . DIRECTORY_SEPARATOR;
    $owned = [];

    foreach (app(Kernel::class)->all() as $name => $command) {
        $file = new ReflectionClass($command)->getFileName();

        if ($file !== false && str_starts_with($file, $src)) {
            $owned[$name] = $command;
        }
    }

    return $owned;
}

it('gives every command a canonical name carrying the vendor and slug', function (): void {
    // Kernel::all() is keyed by every name a command answers to, aliases
    // included, so the canonical name has to be read off the command itself.
    $canonical = array_unique(array_map(
        fn ($command) => $command->getName(),
        ichavaCommands(),
    ));

    expect($canonical)->not->toBeEmpty();

    foreach (ichavaCommands() as $command) {
        $where = new ReflectionClass($command)->getFileName() ?: '(no file)';

        expect($command->getName())
            ->toMatch(
                '/^ichava::[a-z0-9-]+\./',
                sprintf('%s (declared in %s) registers a name outside the convention', $command::class, $where),
            );
    }
});

it('claims nothing canonical in a namespace the framework or another package owns', function (): void {
    foreach (ichavaCommands() as $key => $command) {
        $name = $command->getName();

        // `make:` belongs to Laravel and a bare `ichava:` slug is a generic key
        // any sibling package could claim. Neither may be a canonical name.
        expect($name)->not->toStartWith('make:')
            ->and($name)->not->toMatch('/^ichava:[^:]/');

        // A bare key is tolerated only as a retained alias, never as the name.
        if ($key !== $name) {
            expect($key)->not->toStartWith('make:');
        }
    }
});

it('registers the canonical name for each command', function (string $name): void {
    expect(ichavaCommands())->toHaveKey($name);
})->with([
    'ichava::ichava-core.cache',
    'ichava::ichava-core.database',
    'ichava::ichava-core.info',
    'ichava::ichava-core.job-status',
    'ichava::ichava-core.watch',
    'ichava::ichava-core.cleanup-logs',
    'ichava::ichava-core.icons:check-updates',
    'ichava::ichava-core.make:icon-package',
]);

it('keeps the previous name working as an alias', function (string $alias): void {
    // The compatibility promise is tested, not asserted. setAliases() is also
    // written past Symfony's validator, so it can fail independently of the name.
    $command = app(Kernel::class)->all()[$alias] ?? null;

    expect($command)->not->toBeNull("alias {$alias} is not registered")
        ->and($command::class)->toStartWith('Simtabi\Laranail\Ichava\\')
        ->and($command->getName())->toStartWith('ichava::ichava-core.');
})->with([
    'ichava:cache',
    'ichava:database',
    'ichava:info',
    'ichava:job-status',
    'ichava:watch',
    'ichava:cleanup-logs',
    'ichava:icons:check-updates',
]);

it('no longer squats Laravel\'s make: namespace', function (): void {
    // The one alias deliberately NOT retained. Keeping `make:icon-package` alive
    // would leave the defect in place under a nicer name.
    expect(array_keys(app(Kernel::class)->all()))->not->toContain('make:icon-package');
});
