<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services\Traits;

use Simtabi\Laranail\Ichava\Enums\ComponentSize;

/**
 * ManagesSizes Trait
 *
 * Icon sizing and dimension management.
 */
trait ManagesSizes
{
    /**
     * Parse size value into width/height attributes
     *
     * @return array{width?: string, height?: string, class?: string}
     */
    public function parseSize(string|int|null $size, bool $lockAspectRatio = true): array
    {
        if ($size === null) {
            return [];
        }

        if (is_int($size)) {
            $size = (string) $size;
        }

        // Named sizes
        if ($this->isNamedSize($size)) {
            $enum = ComponentSize::from($size);
            $pixels = $enum->getPixels() . 'px';

            if ($lockAspectRatio) {
                return [
                    'width'  => $pixels,
                    'height' => $pixels,
                    'class'  => "icon-{$size}",
                ];
            }

            return [
                'width' => $pixels,
                'class' => "icon-{$size}",
            ];
        }

        // Fixed sizes
        $parsed = $this->parseFixedSize($size);

        if ($parsed === null) {
            return [];
        }

        if ($lockAspectRatio) {
            return [
                'width'  => $parsed,
                'height' => $parsed,
            ];
        }

        return ['width' => $parsed];
    }

    /**
     * Convert size to inline style
     */
    public function toInlineStyle(
        string|int|null $width = null,
        string|int|null $height = null,
        bool $lockAspectRatio = true,
    ): string {
        $styles = [];

        if ($width !== null) {
            $parsedWidth = $this->parseFixedSize((string) $width);
            if ($parsedWidth) {
                $styles[] = "width: {$parsedWidth}";

                if ($lockAspectRatio && $height === null) {
                    $styles[] = "height: {$parsedWidth}";
                }
            }
        }

        if ($height !== null && ! $lockAspectRatio) {
            $parsedHeight = $this->parseFixedSize((string) $height);
            if ($parsedHeight) {
                $styles[] = "height: {$parsedHeight}";
            }
        }

        return implode('; ', $styles);
    }

    /**
     * Merge size attributes with existing
     */
    public function mergeWithAttributes(array $sizeAttrs, array $existingAttrs): array
    {
        if (isset($sizeAttrs['class']) && isset($existingAttrs['class'])) {
            $existingAttrs['class'] = trim($existingAttrs['class'] . ' ' . $sizeAttrs['class']);
            unset($sizeAttrs['class']);
        }

        return array_merge($existingAttrs, $sizeAttrs);
    }

    /**
     * Check if size is named
     */
    protected function isNamedSize(string $size): bool
    {
        return ComponentSize::isNamed($size);
    }

    /**
     * Parse fixed size with units
     */
    protected function parseFixedSize(string $size): ?string
    {
        return ComponentSize::format($size);
    }
}
