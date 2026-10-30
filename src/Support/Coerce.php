<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Stringable;

/**
 * Narrows an untyped value -- a config entry, an env var, a console option, a
 * cached payload -- to the scalar type the caller needs.
 *
 * Each method keeps PHP's own cast for every value a cast is defined on
 * (scalars and null), so `Coerce::int($v)` returns exactly what `(int) $v`
 * did. Only a value the cast is not meant for (an array, an object) falls back
 * to the default instead of producing a warning or a meaningless `1`.
 *
 * @internal
 */
final class Coerce
{
    /**
     * `(int) $value` for a scalar or null, otherwise `$default`.
     */
    public static function int(mixed $value, int $default = 0): int
    {
        return is_scalar($value) || $value === null ? (int) $value : $default;
    }

    /**
     * `(float) $value` for a scalar or null, otherwise `$default`.
     */
    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_scalar($value) || $value === null ? (float) $value : $default;
    }

    /**
     * `(string) $value` for a scalar, null or Stringable, otherwise `$default`.
     */
    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) || $value === null || $value instanceof Stringable ? (string) $value : $default;
    }

    /**
     * The value when it is already a string, otherwise null.
     *
     * For console options and arguments, which arrive as `string|bool|array|null`:
     * a string is the only shape a typed `?string` parameter could ever accept.
     */
    public static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
