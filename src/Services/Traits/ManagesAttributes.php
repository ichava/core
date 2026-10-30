<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services\Traits;

use Stringable;
use InvalidArgumentException;

/**
 * ManagesAttributes Trait
 *
 * HTML attribute handling and formatting.
 */
trait ManagesAttributes
{
    /**
     * Stored attributes
     *
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * Build HTML attributes string
     *
     * @param array<array-key, mixed> $attributes
     */
    public function buildHtml(array $attributes): string
    {
        $formatted = $this->formatAttributes($this->filterAttributes($attributes));

        return collect($formatted)
            ->map(function (mixed $value, string|int $key): ?string {
                // The closure used to declare `?string` and lean on the
                // collection's non-strict call site to coerce scalars;
                // stringifyAttribute() performs that same coercion explicitly.
                $value = $value === null ? null : $this->stringifyAttribute($value);

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

    public function removeAttribute(string $key): self
    {
        unset($this->attributes[$key]);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Format attributes (escape quotes)
     *
     * @param array<array-key, mixed> $attributes
     *
     * @return array<array-key, mixed>
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

    /**
     * An attribute value as the string PHP's own scalar coercion produces
     * (true -> "1", false -> "", 2 -> "2"). Anything that has no string form
     * is refused rather than rendered as "Array".
     */
    protected function stringifyAttribute(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value === null || is_int($value) || is_float($value) || is_bool($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException('SVG attribute value must be a scalar, got ' . get_debug_type($value) . '.');
    }
}
