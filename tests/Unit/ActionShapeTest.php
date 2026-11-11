<?php

declare(strict_types=1);

/*
 * C-13. An action is one intent: `final`, and one public entry point named
 * handle(). Read by reflection over every class in src/Actions, with a floor
 * on how many were inspected. SvgFileCounter (was Actions\CountSvgFiles) has two named
 * entry points, so it lives in Support, not here.
 */

it('gives every action exactly one public method, handle()', function (): void {
    $files = glob(dirname(__DIR__, 2) . '/src/Actions/*.php') ?: [];

    expect(count($files))->toBeGreaterThanOrEqual(6);

    foreach ($files as $file) {
        $class = new ReflectionClass('Simtabi\\Laranail\\Ichava\\Actions\\' . basename($file, '.php'));
        $public = array_values(array_filter(
            array_map(fn (ReflectionMethod $m): string => $m->getName(), $class->getMethods(ReflectionMethod::IS_PUBLIC)),
            fn (string $name): bool => $name !== '__construct',
        ));

        expect($class->isFinal())->toBeTrue("{$class->getShortName()} is not final")
            ->and($public)->toBe(['handle'], "{$class->getShortName()} exposes " . implode(', ', $public));
    }
});
