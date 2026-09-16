<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\View\Components;

use Closure;
use Throwable;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Deferred icon HTML.
 *
 * Blade compiles `<x-ichava::icon ... />` so the component's `render()` runs
 * before `withAttributes()` populates the attribute bag. Building the SVG
 * eagerly would therefore drop every Blade attribute (`class`, `data-*`,
 * ...). This wrapper defers building until `toHtml()` is echoed, which the
 * compiled template does after the bag is set.
 */
final class DeferredIconHtml implements Htmlable
{
    public function __construct(
        private readonly Closure $builder,
    ) {}

    public function __toString(): string
    {
        try {
            return $this->toHtml();
        } catch (Throwable) {
            return '';
        }
    }

    public function toHtml(): string
    {
        return ($this->builder)();
    }
}
