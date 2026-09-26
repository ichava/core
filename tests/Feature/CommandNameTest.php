<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Simtabi\Laranail\Ichava\Support\CommandName;
use Simtabi\Laranail\Ichava\Commands\CacheCommand;
use Simtabi\Laranail\Ichava\Commands\InstallCommand;
use Simtabi\Laranail\Ichava\Commands\DatabaseCommand;
use Simtabi\Laranail\Ichava\Commands\JobStatusCommand;

/*
 * CommandName is only worth having if it names the command the way Artisan
 * holds it, so this reads the live registry rather than `$signature`.
 */
it('names each command by the key Artisan registers it under', function (string $class) {
    $name = CommandName::of($class);
    $registered = app(Kernel::class)->all();

    expect($registered)->toHaveKey($name)
        ->and($registered[$name])->toBeInstanceOf($class);
})->with([
    CacheCommand::class,
    DatabaseCommand::class,
    InstallCommand::class,
    JobStatusCommand::class,
]);

it('refuses a class that is not a console command', function () {
    CommandName::of(stdClass::class);
})->throws(InvalidArgumentException::class);
