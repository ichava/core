<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services\Traits;

/**
 * ManagesAttributes Trait
 *
 * HTML attribute handling and formatting.
 */
trait ManagesAttributes
{
    /**
     * Stored attributes
     */
    private array $attributes = [];

    /**
     * Build HTML attributes string
     */
    public function buildHtml(array $attributes): string
    {
        $formatted = $this->formatAttributes($attributes);

        return collect($formatted)
            ->map(function (?string $value, string|int $key) {
                if (is_int($key)) {
                    return $value;
                }

                return sprintf('%s="%s"', $key, e($value));
            })
            ->implode(' ');
    }

    /**
     * Merge class attributes
     */
    public function mergeClasses(string ...$classes): string
    {
        return trim(implode(' ', array_filter($classes)));
    }

    /**
     * Fluent API methods
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function setAttributes(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    public function addClass(string $class): self
    {
        $existing = $this->attributes['class'] ?? '';
        $this->attributes['class'] = $this->mergeClasses($existing, $class);

        return $this;
    }

    public function removeAttribute(string $key): self
    {
        unset($this->attributes[$key]);

        return $this;
    }

    public function clearAttributes(): self
    {
        $this->attributes = [];

        return $this;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function renderAttributes(): string
    {
        return $this->buildHtml($this->attributes);
    }

    /**
     * Format attributes (escape quotes)
     */
    protected function formatAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $attributes[$key] = str_replace('"', '&quot;', $value);
            }
        }

        return $attributes;
    }
}
