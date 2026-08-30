<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

use Simtabi\Laranail\Ichava\Support\PathResolver;

/**
 * IconPathResult - Value object for parsed icon paths
 *
 * Immutable data structure containing the parsed components of an icon path.
 * Replaces the old readonly IconPath class with a more descriptive name.
 *
 * **Properties:**
 * - `set`: Full icon set identifier (vendor/package)
 * - `name`: Icon filename (always the last segment)
 * - `variant`: Optional variant subdirectory
 * - `category`: Optional category subdirectory
 * - `vendor`: Vendor name (extracted from set)
 * - `package`: Package name (extracted from set)
 * - `fullPath`: Complete flexible path from designer's structure
 *
 * **Example:**
 * ```php
 * // Input: "simtabi/tabler-icons::outline/arrows/arrow-left"
 * $result = new IconPathResult(
 *     set: 'simtabi/tabler-icons',
 *     name: 'arrow-left',
 *     vendor: 'simtabi',
 *     package: 'tabler-icons',
 *     fullPath: 'outline/arrows/arrow-left'
 * );
 * ```
 *
 * @api
 */
final readonly class IconPathResult
{
    public function __construct(
        public ?string $set,
        public string $name,
        public ?string $variant = null,
        public ?string $category = null,
        public ?string $vendor = null,
        public ?string $package = null,
        public ?string $fullPath = null,  // Full flexible path (e.g., "variant/category/sub/icon")
    ) {}

    /**
     * Magic method for string casting
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Convert to string representation
     *
     * Reconstructs the original icon path format.
     *
     * @return string Icon path in vendor/package::path/to/icon format
     */
    public function toString(): string
    {
        return app(PathResolver::class)->buildIconPath(
            $this->name,
            $this->set,
            $this->variant,
            $this->category,
            $this->vendor,
            $this->package,
        );
    }
}
