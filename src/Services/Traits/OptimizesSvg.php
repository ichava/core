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
        // Remove XML declaration
        if ($this->runsOptimizerPass('remove_xml_declaration')) {
            $content = preg_replace('/^<\?xml.+?\?>\s*/i', '', $content) ?? $content;
        }

        // Remove comments
        if ($this->runsOptimizerPass('remove_comments')) {
            $content = preg_replace('/<!--.*?-->/s', '', $content) ?? $content;
        }

        // Remove <metadata> (editor provenance). <title> and <desc> carry the
        // accessible name and are never touched.
        if ($this->runsOptimizerPass('remove_metadata')) {
            $content = preg_replace('/<metadata.*?<\/metadata>/is', '', $content) ?? $content;
        }

        // Minify
        if ($this->runsOptimizerPass('minify')) {
            $content = preg_replace('/\s+/', ' ', $content) ?? $content;
            $content = preg_replace('/>\s+</', '><', $content) ?? $content;
        }

        return $this->optimizationLevel === OptimizationLevel::NONE && $this->optimizationOverrides === []
            ? $content
            : trim($content);
    }

    /**
     * Initialize optimizer
     */
    protected function initializeOptimizer(): void
    {
        // No initialization needed
    }
}
