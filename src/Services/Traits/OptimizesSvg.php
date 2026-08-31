<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services\Traits;

use Simtabi\Laranail\Ichava\Enums\OptimizationLevel;

/**
 * OptimizesSvg Trait
 *
 * SVG optimization and minification.
 */
trait OptimizesSvg
{
    /**
     * Optimize SVG content
     */
    public function optimize(string $content): string
    {
        if ($this->optimizationLevel === OptimizationLevel::NONE) {
            return $content;
        }

        // Remove XML declaration
        $content = preg_replace('/^<\?xml.+?\?>\s*/i', '', $content) ?? $content;

        // Remove comments
        if ($this->optimizationLevel->shouldRemoveComments()) {
            $content = preg_replace('/<!--.*?-->/s', '', $content) ?? $content;
        }

        // Remove metadata
        if ($this->optimizationLevel->shouldRemoveMetadata()) {
            $content = preg_replace('/<metadata.*?<\/metadata>/is', '', $content) ?? $content;
        }

        // Minify
        if ($this->optimizationLevel->shouldMinify()) {
            $content = preg_replace('/\s+/', ' ', $content) ?? $content;
            $content = preg_replace('/>\s+</', '><', $content) ?? $content;
        }

        return trim($content);
    }

    /**
     * Initialize optimizer
     */
    protected function initializeOptimizer(): void
    {
        // No initialization needed
    }
}
