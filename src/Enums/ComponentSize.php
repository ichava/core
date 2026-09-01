<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Enums;

use Illuminate\Support\Str;
use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

/**
 * Predefined named icon sizes with pixel and rem values.
 */
enum ComponentSize: string
{
    use HasEnumeratorBehavior;

    case XS = 'xs';
    case SM = 'sm';
    case MD = 'md';
    case LG = 'lg';
    case XL = 'xl';
    case XXL = 'xxl';

    /**
     * Determine if the given string is a named size.
     */
    public static function isNamed(string $size): bool
    {
        return self::tryFrom($size) !== null;
    }

    /**
     * Parse an arbitrary size value with optional unit.
     *
     * Supports: 24, 24px, 2rem, 2em, 50%, 5vh, 5vw, vmin, vmax
     *
     * @return array{value: string, unit: string}|null
     */
    public static function parseArbitrary(string $size): ?array
    {
        $size = trim($size);

        if (self::isNamed($size)) {
            $enum = self::from($size);

            return [
                'value' => (string) $enum->getPixels(),
                'unit' => 'px',
            ];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(px|rem|em|%|vh|vw|vmin|vmax)?$/i', $size, $matches)) {
            $value = $matches[1];
            $unit = isset($matches[2]) ? Str::lower($matches[2]) : 'px';

            return [
                'value' => $value,
                'unit' => $unit,
            ];
        }

        return null;
    }

    /**
     * Format an arbitrary size value to a CSS-compatible string.
     */
    public static function format(string $size): ?string
    {
        $parsed = self::parseArbitrary($size);

        if ($parsed === null) {
            return null;
        }

        return $parsed['value'].$parsed['unit'];
    }

    /**
     * Get all named sizes with their pixel values.
     *
     * @return array<string, int>
     */
    public static function all(): array
    {
        return [
            self::XS->value => self::XS->getPixels(),
            self::SM->value => self::SM->getPixels(),
            self::MD->value => self::MD->getPixels(),
            self::LG->value => self::LG->getPixels(),
            self::XL->value => self::XL->getPixels(),
            self::XXL->value => self::XXL->getPixels(),
        ];
    }

    /**
     * Get the CSS class name for this size.
     */
    public function getClass(): string
    {
        return "icon-{$this->value}";
    }

    /**
     * Get the pixel value for this named size.
     */
    public function getPixels(): int
    {
        return match ($this) {
            self::XS => 12,
            self::SM => 16,
            self::MD => 20,
            self::LG => 24,
            self::XL => 32,
            self::XXL => 48,
        };
    }

    /**
     * Get the rem value for this named size.
     */
    public function getRem(): float
    {
        return $this->getPixels() / 16;
    }
}
