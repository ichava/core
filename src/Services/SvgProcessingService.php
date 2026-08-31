<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use DOMDocument;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Simtabi\Laranail\Ichava\Enums\OptimizationLevel;
use Simtabi\Laranail\Ichava\Services\Traits\ManagesSizes;
use Simtabi\Laranail\Ichava\Services\Traits\NamespacesSvgIds;
use Simtabi\Laranail\Ichava\Services\Traits\OptimizesSvg;
use Simtabi\Laranail\Ichava\Services\Traits\SanitizesSvg;
use Simtabi\Laranail\Ichava\Services\Traits\ManagesAttributes;

/**
 * SvgProcessingService - Unified SVG Processing Pipeline
 *
 * Injectable service that composes four single-responsibility traits into
 * a single process() call. Used by SvgDriver immediately after loading a
 * file, before the result is cached or returned to the caller.
 *
 * Processing pipeline (in order):
 * - SanitizesSvg     , strips dangerous tags, attributes, and protocols (XSS prevention)
 * - OptimizesSvg     , removes comments, redundant whitespace, and unnecessary attributes
 * - ManagesAttributes, merges and applies HTML attributes onto the SVG root element
 * - ManagesSizes     , parses size, width, and height props into concrete pixel values
 *
 * @see SvgDriver
 * @see SanitizesSvg
 */
final class SvgProcessingService
{
    use ManagesAttributes;
    use ManagesSizes;
    use NamespacesSvgIds;
    use OptimizesSvg;
    use SanitizesSvg;

    public function __construct(
        protected OptimizationLevel $optimizationLevel = OptimizationLevel::BASIC,
    ) {
        $this->initializeSanitizer();
        $this->initializeOptimizer();
    }

    /**
     * Process SVG: sanitize, optimize, and apply attributes
     *
     * @param string $content Raw SVG content
     * @param array<string, mixed> $attributes HTML attributes to apply
     * @param bool $optimize Whether to optimize the SVG
     *
     * @return string Processed SVG content
     */
    public function process(string $content, array $attributes = [], bool $optimize = true): string
    {
        // Step 1: Sanitize (security first)
        $content = $this->sanitize($content);

        // Step 2: Optimize (if enabled)
        if ($optimize) {
            $content = $this->optimize($content);
        }

        // Step 3: Apply attributes (if provided)
        if (! empty($attributes)) {
            $content = $this->applyAttributes($content, $attributes);
        }

        return $content;
    }

    /**
     * Set optimization level
     */
    public function setOptimizationLevel(OptimizationLevel $level): self
    {
        $this->optimizationLevel = $level;

        return $this;
    }

    /**
     * Get optimization level
     */
    public function getOptimizationLevel(): OptimizationLevel
    {
        return $this->optimizationLevel;
    }

    /**
     * Apply HTML attributes to SVG root element
     *
     * @param string $svg SVG content
     * @param array<string, mixed> $attributes Attributes to apply
     *
     * @return string SVG with attributes
     */
    protected function applyAttributes(string $svg, array $attributes): string
    {
        if (empty($attributes)) {
            return $svg;
        }

        // Load SVG into DOM
        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadXML($svg, LIBXML_NONET);

        if ($dom->documentElement === null) {
            return $svg; // Return original if parsing fails
        }

        // Apply attributes to root <svg> element
        foreach ($attributes as $key => $value) {
            if ($key === 'class') {
                // Merge class attributes
                $existing = $dom->documentElement->getAttribute('class');
                $merged = $this->mergeClasses($existing, (string) $value);
                $dom->documentElement->setAttribute('class', $merged);
            } else {
                $dom->documentElement->setAttribute($key, (string) $value);
            }
        }

        $result = $dom->saveXML($dom->documentElement);

        return $result !== false ? $result : $svg;
    }
}
