<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Traits;

use Simtabi\Laranail\Ichava\Contracts\IconSetVariantInterface;

/**
 * Reusable trait for icon set variant/category enums.
 *
 * Provides consistent value/class/default helpers across icon packages so
 * each enum only has to declare its cases and the two accessor methods.
 *
 * @see IconSetVariantInterface
 */
trait HasIconSetVariants
{
    /**
     * Get all variant values as an array
     *
     * Useful for config, validation, etc.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $case) => $case->getValue(),
            self::cases(),
        );
    }

    /**
     * Get the default variant case
     *
     * @return static|null
     */
    public static function default(): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->isDefault()) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Try to create variant from string value
     *
     * @return static|null
     */
    public static function tryFromValue(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * Get the variant value (uses backing value from backed enum)
     *
     * For backed enums (enum MyEnum: string), this returns the backing value.
     * This is the string used in paths, config keys, etc.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Check if this is the default variant
     *
     * Compares this variant's value against the default value
     * defined by the implementing enum.
     */
    public function isDefault(): bool
    {
        return $this->value === static::getDefaultValue();
    }

    /**
     * Get CSS class for this variant
     *
     * Generates a CSS class name by combining the icon set prefix
     * with the variant value.
     *
     * Example: 'ti' + 'outline' = 'ti-outline'
     */
    public function getClass(): string
    {
        $prefix = static::getClassPrefix();

        if (empty($prefix)) {
            return $this->value;
        }

        return "{$prefix}-{$this->value}";
    }

    /**
     * Backing value of the default case (typically read from the package's config.json).
     */
    abstract protected static function getDefaultValue(): string;

    /**
     * CSS prefix prepended to case values by getClass(). Return '' to disable prefixing.
     */
    abstract protected static function getClassPrefix(): string;
}
