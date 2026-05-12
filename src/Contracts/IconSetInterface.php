<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Data\IconSetConfig;

interface IconSetInterface
{
    /**
     * Get the icon set name
     */
    public function name(): string;

    /**
     * Get the icon set configuration
     */
    public function config(): IconSetConfig;

    /**
     * Check if icon exists
     */
    public function has(string $name, ?string $variant = null, ?string $category = null): bool;

    /**
     * Get icon data
     */
    public function get(string $name, ?string $variant = null, ?string $category = null): ?IconData;

    /**
     * Get all icons (optionally filtered by variant/category)
     */
    public function all(?string $variant = null, ?string $category = null): array;

    /**
     * Get supported variants
     */
    public function variants(): array;

    /**
     * Get supported categories
     */
    public function categories(?string $variant = null): array;

    /**
     * Get the base path for icons
     */
    public function basePath(): string;
}
