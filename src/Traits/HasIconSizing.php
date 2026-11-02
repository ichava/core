<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Traits;

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * Trait for handling icon sizing in Blade components
 *
 * Provides consistent size parsing logic across all icon components
 */
trait HasIconSizing
{
    /**
     * Parse size-related attributes (width, height, size).
     *
     * @return array<string, mixed>
     */
    protected function parseSizeAttributes(SvgProcessingService $svgProcessor): array
    {
        // Handle explicit width/height
        if ($this->width || $this->height) {
            $attrs = [];

            if ($this->width) {
                $widthParsed = $svgProcessor->parseSize($this->width, false);
                $attrs = array_merge($attrs, $widthParsed);
            }

            if ($this->height) {
                $heightParsed = $svgProcessor->parseSize($this->height, false);
                $attrs = array_merge($attrs, $heightParsed);
            }

            // If only width is provided and aspect ratio should be locked
            if ($this->width && ! $this->height && $this->shouldLockAspectRatio()) {
                $parsed = $svgProcessor->parseSize($this->width, true);
                $attrs = array_merge($attrs, $parsed);
            }

            return $attrs;
        }

        // Handle size preset
        if ($this->size) {
            return $svgProcessor->parseSize($this->size, $this->shouldLockAspectRatio());
        }

        return [];
    }

    /**
     * Determine if aspect ratio should be locked by default.
     */
    protected function shouldLockAspectRatio(): bool
    {
        return $this->lockAspectRatio ?? true;
    }
}
