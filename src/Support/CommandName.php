<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;

/**
 * The name a command answers to, read from the command rather than written
 * into the text that tells a user to run it.
 *
 * Every Ichava command's name carries the vendor and slug
 * (`ichava::ichava-core.database`), and those names have already changed once.
 * A hint printed as a literal -- "run php artisan ichava::ichava-core.database
 * migrate" -- goes stale silently when the name moves, because nothing
 * executes the text a command prints. Deriving it from the class keeps the
 * hint and the registration one fact.
 *
 * Resolved through the container and `getName()` rather than by walking
 * `Kernel::all()`: in a host application `all()` instantiates every lazily
 * loaded command just to find one. `getName()` is what Artisan keys the command
 * by, which `CommandNameTest` asserts against the live registry.
 */
final class CommandName
{
    /** @var array<class-string<Command>, string> */
    private static array $names = [];

    /**
     * @param class-string<Command> $command
     */
    public static function of(string $command): string
    {
        if (isset(self::$names[$command])) {
            return self::$names[$command];
        }

        $instance = app($command);

        if (! $instance instanceof Command || ($name = $instance->getName()) === null) {
            throw new InvalidArgumentException("{$command} is not a named console command.");
        }

        return self::$names[$command] = $name;
    }
}
