<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Ichava;
use Simtabi\Laranail\Ichava\Support\IconRenderer;

if (! function_exists('ichava')) {
    /**
     * Fluent entry point for Ichava icon management.
     *
     * Pass an icon path (e.g. `vendor/package::variant/name`) to start a render
     * chain immediately. Pass nothing to access the full Ichava facade, every
     * public method on Ichava is reachable: render, register, defs, packages,
     * icons, search, browser, cache, sets, and so on.
     *
     * ```blade
     * {!! ichava('ichava/tabler-icons::outline/home')->class('w-6 h-6') !!}
     * {!! ichava()->register('ichava/social-icons')->fromDirectory(storage_path('icons/social')) !!}
     * {!! ichava()->defs() !!}
     * ```
     */
    function ichava(?string $name = null): IconRenderer|Ichava
    {
        /** @var Ichava $ichava */
        $ichava = app(Ichava::class);

        return $name !== null ? $ichava->render($name) : $ichava;
    }
}
