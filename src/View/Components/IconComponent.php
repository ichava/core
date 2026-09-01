<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\View\Components;

use Illuminate\Support\Str;
use Illuminate\View\Component;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;
use Simtabi\Laranail\Ichava\Traits\HasIconSizing;
use Throwable;

/**
 * IconComponent - Unified Icon Component
 *
 * Renders Ichava icons as inline SVG via the Blade component system. Works for both
 * generic usage (full vendor/package path) and package-specific shorthand (by extending
 * this class and returning a default vendor/package from getVendorPackage()).
 *
 * Icon path structure:
 *
 *   vendor/package::path/to/icon
 *     │      │       └──────────── FLEXIBLE path matching the designer's folder structure.
 *     │      │                     Icon name is ALWAYS the last segment.
 *     │      └────────────────── Package name (REQUIRED)
 *     └───────────────────────── Vendor name (REQUIRED)
 *
 * Usage examples (path mirrors the designer's folder structure):
 * ```blade
 * <x-ichava::icon name="ichava/tabler-icons::home" />
 * <x-ichava::icon name="ichava/tabler-icons::outline/home" />
 * <x-ichava::icon name="ichava/metronic-icons::brand-logos/google" />
 * <x-ichava::icon name="vendor/icons::ui/buttons/primary/large" />
 * ```
 *
 * With accessibility:
 * ```blade
 * <x-ichava::icon name="ichava/tabler-icons::home" title="Go Home" aria="Navigate home" />
 * ```
 *
 * With sizing:
 * ```blade
 * <x-ichava::icon name="ichava/tabler-icons::home" size="lg" />
 * <x-ichava::icon name="ichava/tabler-icons::home" width="24" height="24" />
 * ```
 *
 * With fallback:
 * ```blade
 * <x-ichava::icon name="ichava/tabler-icons::home" fallback="ichava/tabler-icons::circle-x" />
 * ```
 *
 * Extend to create package-scoped shorthand components:
 * ```php
 * class TablerIconComponent extends IconComponent
 * {
 *     protected function getVendorPackage(): string { return 'ichava/tabler-icons'; }
 * }
 * // Then use: <x-tabler-icons-icon name="outline/home" />
 * ```
 *
 * @see IconRegistry
 * @see HasIconSizing
 */
class IconComponent extends Component
{
    use HasIconSizing;

    /**
     * Create a new component instance.
     *
     * @param  string  $name  Full icon path (e.g. 'ichava/tabler-icons::outline/home')
     *                        or a short name when getVendorPackage() is overridden.
     * @param  string|null  $set  Override the icon set / vendor-package path.
     * @param  string|null  $variant  Optional variant segment prepended to the icon name (e.g. 'outline', 'solid').
     * @param  string|null  $category  Optional category segment prepended to the icon name (e.g. 'brand-logos').
     *                                 Takes precedence over $variant when both are provided.
     * @param  string|null  $size  Named size preset handled by HasIconSizing (xs, sm, md, lg, xl, 2xl, …).
     * @param  string|null  $width  Explicit pixel width (overrides $size width).
     * @param  string|null  $height  Explicit pixel height (overrides $size height).
     * @param  bool  $lockAspectRatio  Whether to enforce a 1:1 aspect ratio when only one dimension is set.
     * @param  string|null  $title  Accessibility title injected as `<title>` inside the SVG (WCAG 2.1).
     * @param  string|null  $aria  Value for the `aria-label` attribute (screen-reader accessible name).
     * @param  string|null  $role  ARIA role attribute (default: 'img'; use 'presentation' for decorative icons).
     * @param  string|null  $fallback  Fallback icon path rendered if the primary icon fails.
     *                                 Falls back further to config('ichava.core.fallback_icon') if this is also absent.
     * @param  string|null  $dark  Reserved for dark-mode variant support (not yet implemented).
     * @param  IconRegistry|null  $iconRegistry  Injected by Laravel's service container.
     * @param  SvgProcessingService|null  $svgProcessor  Injected by Laravel's service container.
     */
    public function __construct(
        public string $name,
        public ?string $set = null,
        public ?string $variant = null,
        public ?string $category = null,
        public ?string $size = null,
        public ?string $width = null,
        public ?string $height = null,
        public bool $lockAspectRatio = true,
        public ?string $title = null,
        public ?string $aria = null,
        public ?string $role = 'img',
        public ?string $fallback = null,
        public ?string $dark = null,
        private readonly ?IconRegistry $iconRegistry = null,
        private readonly ?SvgProcessingService $svgProcessor = null,
    ) {}

    /**
     * Render the icon to an HTML SVG string.
     *
     * Execution order:
     * 1. Build the full icon path via buildIconPath()
     * 2. Parse size attributes via HasIconSizing::parseSizeAttributes()
     * 3. Parse WCAG accessibility attributes (title, aria-label, role)
     * 4. Collect and smart-merge the Blade attributes bag (strips conflicting w-/h- classes)
     * 5. Merge all attribute layers (default → bag → a11y → size)
     * 6. Render via IconRegistry; fall back to $fallback or config('ichava.core.fallback_icon') on exception
     *
     * @return string Rendered HTML-safe SVG string
     *
     * @throws IchavaException if rendering fails and no valid fallback is configured
     */
    public function render(): string
    {
        if (! $this->iconRegistry) {
            throw IchavaException::dependencyNotInjected('IconRegistry', static::class);
        }

        if (! $this->svgProcessor) {
            throw IchavaException::dependencyNotInjected('SvgProcessingService', static::class);
        }

        // Build the full icon path (includes category/variant in path)
        $iconPath = $this->buildIconPath();

        // Parse size attributes
        $sizeAttrs = $this->parseSizeAttributes($this->svgProcessor);

        // Parse accessibility attributes
        $a11yAttrs = $this->parseAccessibilityAttributes();

        // Collect additional attributes from the component's attributes bag
        $additionalAttrs = $this->attributes?->getAttributes() ?? [];

        // Smart merge: size props override class width/height, but preserve other classes
        if (! empty($sizeAttrs) && isset($additionalAttrs['class'])) {
            // Remove width/height classes from additional attributes if size props are set
            $classString = $additionalAttrs['class'];
            $classString = preg_replace('/\b(w-\S+|h-\S+|size-\S+)\b/', '', $classString);
            $additionalAttrs['class'] = trim(preg_replace('/\s+/', ' ', $classString));
        }

        // Merge attributes in order of precedence:
        // 1. Default attributes (lowest priority)
        // 2. Additional attributes (medium priority)
        // 3. Accessibility attributes (high priority)
        // 4. Size attributes (highest priority)
        $attributes = array_merge(
            $this->getDefaultAttributes(),
            $additionalAttrs,
            $a11yAttrs,
            $sizeAttrs,
        );

        // Render, fall back to $fallback icon on exception, otherwise re-throw
        try {
            return $this->iconRegistry->render($iconPath, null, null, $attributes);
        } catch (Throwable $e) {
            $fallback = $this->fallback ?? config('ichava.core.fallback_icon');

            if ($fallback && $fallback !== $iconPath) {
                return $this->iconRegistry->render($fallback, null, null, $attributes);
            }

            throw $e;
        }
    }

    /**
     * Get the icon set name (for package-specific components)
     *
     * Override this in child components to provide default icon set.
     * Used when no set is specified in the name or $set parameter.
     *
     * @return string|null Icon set identifier (e.g., 'metronic', 'tabler')
     */
    protected function getIconSet(): ?string
    {
        return null;
    }

    /**
     * Get the full vendor/package path (for package-specific components)
     *
     * Override this in child components to provide full vendor/package path.
     * This enables cleaner syntax: <x-metronic name="icon" /> instead of full path.
     *
     * @return string|null Full vendor/package path (e.g., 'ichava/metronic-icons')
     */
    protected function getVendorPackage(): ?string
    {
        return null;
    }

    /**
     * Get default attributes for the icon
     *
     * Override this to provide package-specific default attributes.
     *
     * @return array<string, mixed>
     */
    protected function getDefaultAttributes(): array
    {
        return [];
    }

    /**
     * Build the full icon path
     *
     * Handles three scenarios:
     * 1. Full path already provided (e.g., 'ichava/metronic-icons::brand-logos/facebook')
     * 2. Vendor/package prefix needed (e.g., 'google' → 'ichava/metronic-icons::google')
     * 3. Fallback to set name only (e.g., 'google' → 'metronic::google')
     *
     * @return string Complete icon path
     */
    protected function buildIconPath(): string
    {
        // If name already contains vendor/package or ::, use as-is
        if (Str::contains($this->name, '/') || Str::contains($this->name, '::')) {
            return $this->name;
        }

        // Build the icon name with category/variant if provided
        $iconName = $this->name;

        // Prepend category to icon name if provided
        if ($this->category) {
            $iconName = "{$this->category}/{$iconName}";
        }
        // Prepend variant to icon name if provided (and no category)
        elseif ($this->variant) {
            $iconName = "{$this->variant}/{$iconName}";
        }

        // Try to get vendor/package prefix
        $vendorPackage = $this->set ?? $this->getVendorPackage();

        // If vendor/package available, use it
        if ($vendorPackage && Str::contains($vendorPackage, '/')) {
            return "{$vendorPackage}::{$iconName}";
        }

        // Fallback: use icon set name (for backward compatibility)
        $set = $this->set ?? $this->getIconSet();

        if ($set) {
            return "{$set}::{$iconName}";
        }

        // Last resort: return name as-is (will likely fail, but let IconRegistry handle error)
        return $iconName;
    }

    /**
     * Build the accessibility attribute array from component props.
     *
     * Maps component props to WCAG-compatible HTML attributes:
     * - $title  → 'title' (injected as a `<title>` element inside the SVG by SvgDriver)
     * - $aria   → 'aria-label' (accessible name for screen readers)
     * - $role   → 'role' (defaults to 'img'; use 'presentation' for decorative icons)
     *
     * @return array<string, string>
     */
    protected function parseAccessibilityAttributes(): array
    {
        $attrs = [];

        if ($this->title) {
            $attrs['title'] = $this->title;
        }

        if ($this->aria) {
            $attrs['aria-label'] = $this->aria;
        }

        if ($this->role) {
            $attrs['role'] = $this->role;
        }

        return $attrs;
    }
}
