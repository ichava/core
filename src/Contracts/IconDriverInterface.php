<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * Reads SVG files safely and renders icons from them.
 *
 * Bound to {@see SvgDriver}. The registry, the served-SVG reader and the
 * directory driver depend on this, so a host substitutes the driver (a
 * remote store, an encrypted one) without subclassing. The signatures are
 * SvgDriver's: until 2026-11-05 this interface described a directory API no
 * driver in use implemented (C-17).
 */
interface IconDriverInterface
{
    /**
     * Render $icon with $attributes applied to its root element.
     *
     * @param array<string, mixed> $attributes
     * @param string|null          $baseDir    the pack directory the file must stay inside
     * @param int|null             $maxBytes   the size ceiling for the icon's kind; null keeps the global one
     *
     * @throws IchavaException when the file is refused or cannot be read
     */
    public function render(IconData $icon, array $attributes = [], ?string $baseDir = null, ?int $maxBytes = null): string;

    /**
     * The raw, unsanitised content of the SVG at $path, after the safety
     * checks: no symlink, contained in $baseDir, within the size bounds.
     *
     * @param array<string, mixed> $options reserved for future loaders
     *
     * @throws IchavaException when the file is refused or cannot be read
     */
    public function load(string $path, array $options = [], ?string $baseDir = null, ?int $maxBytes = null): string;
}
