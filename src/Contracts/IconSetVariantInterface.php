<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

/**
 * Interface for icon set variants/categories
 *
 * Provides type safety and consistency across all icon packages.
 * Implement this interface on your enum to ensure your variants
 * follow the ichava standard.
 *
 * Example:
 * ```php
 * enum TablerVariant: string implements IconSetVariantInterface
 * {
 *     case OUTLINE = 'outline';
 *     case FILLED = 'filled';
 *
 *     public function getValue(): string { return $this->value; }
 *     public function isDefault(): bool { return $this === self::OUTLINE; }
 *     // ... implement other methods
 * }
 * ```
 */
interface IconSetVariantInterface
{
    /**
     * Get the variant/category value as a string
     *
     * This is the value used in paths, config lookups, etc.
     * For backed enums, this typically returns the backing value.
     *
     * @return string The variant identifier (e.g., 'outline', 'filled', 'brand-logos')
     */
    public function getValue(): string;

    /**
     * Check if this is the default variant for the icon set
     *
     * @return bool True if this is the default variant
     */
    public function isDefault(): bool;

    /**
     * Get the full filesystem path to this variant's icons
     *
     * Null when the variant or category has no directory of its own: a grouping
     * of whole sets, say, or a category the pack's config.json does not describe.
     * Callers must handle null rather than build a path from it.
     *
     * Widened from `string` in 0.1.2. An implementation that still returns
     * `string` satisfies it unchanged, because a narrower return type is covariant.
     *
     * @return string|null Absolute path to the variant's SVG directory, or null when there is none
     */
    public function getPath(): ?string;

    /**
     * Get the CSS class name for this variant
     *
     * Used for styling icons differently based on variant.
     * Example: 'ti-outline', 'ti-filled', 'mi-brand'
     *
     * @return string The CSS class for this variant
     */
    public function getClass(): string;
}
