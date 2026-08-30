<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Exception;
use Illuminate\Contracts\Support\Htmlable;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * Fluent renderer that collects icon options (class, size, color, ARIA, …)
 * and resolves them to an HTML-safe SVG string on render() / toHtml() /
 * (string) cast.
 *
 * See README § "Icon Path Format" and § "Global Helper Function" for the
 * full fluent API and accessibility / deferred-loading patterns.
 *
 * @see IconRegistry
 * @see DeferredIconsRegistry
 */
final class IconRenderer implements Htmlable
{
    private string $name;

    private ?string $variant = null;

    private ?string $category = null;

    private array $classes = [];

    private array $attributes = [];

    private ?string $title = null;

    private ?string $ariaLabel = null;

    private ?string $role = 'img';

    private ?string $size = null;

    private ?string $width = null;

    private ?string $height = null;

    private bool $defer = false;

    public function __construct(
        private IconRegistry $registry,
        private readonly DeferredIconsRegistry $deferredRegistry,
        private readonly PathResolver $pathResolver,
        private readonly SvgProcessingService $svgProcessor,
    ) {}

    /**
     * Auto-convert to string
     */
    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Create a new instance (for static usage)
     */
    public static function make(string $name, ?string $variant = null, ?string $category = null): static
    {
        $instance = app(self::class);
        $instance->icon($name, $variant, $category);

        return $instance;
    }

    /**
     * Set the icon to render.
     *
     * **Path format:** `vendor/package::category/icon-name`
     * ```
     * vendor/package  ::  category/icon-name
     *    │      │          │          └── filename (without .svg)
     *    │      │          └──────────── category/variant (nestable)
     *    │      └─────────────────────── package name (required)
     *    └────────────────────────────── vendor name  (required)
     * ```
     *
     * Both slash and dot notation are accepted and auto-normalised:
     * - `'ichava/ui-icons::ui-icons/home'`
     * - `'ichava/icons-bundle::fontawesome/solid/check'`
     * - `'ichava/icons-bundle::fontawesome.solid.check'`
     *
     * Separator rules:
     * - `/` separates vendor from package
     * - `::` separates package from icon path
     * - `/` or `.` separates segments within the icon path
     *
     * @param string $name Icon identifier in `vendor/package::category/icon-name` format
     * @param string|null $variant Optional variant (if not already in $name)
     * @param string|null $category Optional category (if not already in $name)
     */
    public function icon(string $name, ?string $variant = null, ?string $category = null): static
    {
        $this->name = $name;
        $this->variant = $variant;
        $this->category = $category;

        return $this;
    }

    /**
     * Set the icon by name. Accepts the same path format as icon().
     *
     * Flexible overload: if the second argument is an array it is treated as
     * HTML attributes rather than a variant string.
     *
     * @param string $name Icon identifier
     * @param string|array|null $variantOrAttributes Variant string, or attributes array
     * @param string|null $category Optional category
     */
    public function name(string $name, string|array|null $variantOrAttributes = null, ?string $category = null): static
    {
        // Handle flexible parameters
        if (is_array($variantOrAttributes)) {
            // Second parameter is attributes array
            $this->icon($name);

            return $this->attributes($variantOrAttributes);
        }

        // Normal variant/category parameters
        return $this->icon($name, $variantOrAttributes, $category);
    }

    /**
     * Add CSS class(es) to the icon
     *
     * @param string|array $classes Single class string or array of classes
     */
    public function class(string|array $classes): static
    {
        if (is_array($classes)) {
            $this->classes = array_merge($this->classes, $classes);
        } else {
            $this->classes[] = $classes;
        }

        return $this;
    }

    /**
     * Set HTML attributes for the icon
     *
     * @param array<string, mixed> $attributes Associative array of HTML attributes
     */
    public function attributes(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    /**
     * Set a single HTML attribute
     *
     * @param string $key Attribute name
     * @param mixed $value Attribute value
     */
    public function attribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Set title for accessibility (WCAG 2.1)
     *
     * The title is injected as a <title> element inside the SVG for screen readers
     *
     * @param string $title Descriptive title for the icon
     */
    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Set aria-label for accessibility (WCAG 2.1)
     *
     * Provides accessible name for screen readers
     *
     * @param string $label Accessible label for the icon
     */
    public function aria(string $label): static
    {
        $this->ariaLabel = $label;

        return $this;
    }

    /**
     * Set ARIA role attribute
     *
     * Default is 'img'. Common values: 'img', 'presentation', 'none'
     *
     * @param string $role ARIA role value
     */
    public function role(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    /**
     * Set icon size using preset or custom dimensions
     *
     * Presets: 'xs', 'sm', 'md', 'lg', 'xl', '2xl', etc.
     * Custom: '24', '48px', '2rem', etc.
     *
     * @param string $size Size preset or custom dimension
     */
    public function size(string $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Set icon width explicitly
     *
     * @param string $width Width value (e.g., '24', '48px', '2rem')
     */
    public function width(string $width): static
    {
        $this->width = $width;

        return $this;
    }

    /**
     * Set icon height explicitly
     *
     * @param string $height Height value (e.g., '24', '48px', '2rem')
     */
    public function height(string $height): static
    {
        $this->height = $height;

        return $this;
    }

    /**
     * Enable defer loading for performance optimization
     *
     * When enabled, icons are converted to SVG symbols and referenced using <use>,
     * resulting in ~70% smaller HTML output. Use @ichava_defs directive to output
     * the symbol definitions.
     *
     * @param bool $defer Whether to enable defer loading
     */
    public function defer(bool $defer = true): static
    {
        $this->defer = $defer;

        return $this;
    }

    /**
     * Set ID attribute
     */
    public function id(string $id): static
    {
        return $this->attribute('id', $id);
    }

    /**
     * Add a data-* attribute
     *
     * @param string $key Data attribute key (without 'data-' prefix)
     * @param mixed $value Attribute value
     */
    public function data(string $key, mixed $value): static
    {
        return $this->attribute("data-{$key}", $value);
    }

    /**
     * Add inline CSS styles to the icon
     *
     * Useful for colors, opacity, transformations, etc.
     *
     * @param string $style Inline CSS (e.g., 'color: red; opacity: 0.8')
     *
     * @example
     * // Change icon color
     * ichava('tabler:home')->style('color: #3B82F6')
     *
     * // Multiple styles
     * ichava('tabler:home')->style('color: red; opacity: 0.5; transform: rotate(45deg)')
     *
     * // With Tailwind (use classes instead)
     * ichava('tabler:home')->class('text-blue-500 opacity-50')
     */
    public function style(string $style): static
    {
        return $this->attribute('style', $style);
    }

    /**
     * Set icon color using inline style
     *
     * Convenience method for setting color
     *
     * @param string $color Color value (hex, rgb, named color, etc.)
     *
     * @example
     * ichava('tabler:home')->color('#3B82F6')
     * ichava('tabler:home')->color('rgb(59, 130, 246)')
     * ichava('tabler:home')->color('blue')
     */
    public function color(string $color): static
    {
        return $this->style("color: {$color}");
    }

    /**
     * Render the icon to an SVG string with all queued attributes applied.
     *
     * @throws IchavaException When the icon name is not set.
     */
    public function render(): string
    {
        if (! isset($this->name)) {
            throw IchavaException::invalidConfiguration('Icon name is required. Call icon() first.');
        }

        // Build attributes array
        $attributes = $this->buildAttributes();

        // Handle defer mode
        if ($this->defer) {
            return $this->renderDeferred($attributes);
        }

        // Render using registry
        try {
            return $this->registry->render(
                $this->name,
                $this->variant,
                $this->category,
                $attributes,
            );
        } catch (Exception $e) {
            // Return empty string or fallback icon on error
            if (config('app.debug', false)) {
                return "<!-- Icon render error: {$e->getMessage()} -->";
            }

            return '';
        }
    }

    /**
     * Convert to HTML string
     */
    public function toHtml(): string
    {
        return $this->render();
    }

    /**
     * Return the current renderer state as an array, useful for debugging and testing.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'       => $this->name ?? null,
            'variant'    => $this->variant,
            'category'   => $this->category,
            'classes'    => $this->classes,
            'attributes' => $this->attributes,
            'title'      => $this->title,
            'ariaLabel'  => $this->ariaLabel,
            'role'       => $this->role,
            'size'       => $this->size,
        ];
    }

    /**
     * Render icon in defer mode (using <use> reference)
     */
    private function renderDeferred(array $attributes): string
    {
        // Generate unique ID for this icon
        $iconPath = $this->pathResolver->parseIconPath($this->name);
        $set = $iconPath->set ?? config('ichava.default_set');
        $iconId = $this->deferredRegistry->generateId($set, $iconPath->name, $this->variant ?? $iconPath->variant);

        // Register icon if not already registered
        if (! $this->deferredRegistry->has($iconId)) {
            try {
                // Get the full SVG to convert to symbol
                $svg = $this->registry->render(
                    $this->name,
                    $this->variant,
                    $this->category,
                    [], // No attributes for the symbol definition
                );

                $this->deferredRegistry->register($iconId, $svg);
            } catch (Exception $e) {
                // If registration fails, fall back to normal rendering
                return $this->registry->render($this->name, $this->variant, $this->category, $attributes);
            }
        }

        // Render as <use> reference
        return $this->deferredRegistry->render($iconId, $attributes);
    }

    /**
     * Build attributes array for rendering
     */
    private function buildAttributes(): array
    {
        $attributes = $this->attributes;

        // Add classes
        if (! empty($this->classes)) {
            $existingClass = $attributes['class'] ?? '';
            $newClasses = implode(' ', array_filter($this->classes));
            $attributes['class'] = trim("{$existingClass} {$newClasses}");
        }

        // Add size attributes
        if ($this->size) {
            $sizeAttrs = $this->svgProcessor->parseSize($this->size, true);
            $attributes = array_merge($attributes, $sizeAttrs);
        } elseif ($this->width || $this->height) {
            if ($this->width) {
                $attributes['width'] = $this->width;
            }
            if ($this->height) {
                $attributes['height'] = $this->height;
            }
        }

        // Add accessibility attributes
        if ($this->title) {
            $attributes['title'] = $this->title;
        }

        if ($this->ariaLabel) {
            $attributes['aria-label'] = $this->ariaLabel;
        }

        if ($this->role) {
            $attributes['role'] = $this->role;
        }

        // Add defer flag (will be handled by driver later)
        if ($this->defer) {
            $attributes['data-ichava-defer'] = 'true';
        }

        return $attributes;
    }
}
