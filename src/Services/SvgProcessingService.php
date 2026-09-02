<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use DOMDocument;
use Simtabi\Laranail\Ichava\Drivers\SvgDriver;
use Simtabi\Laranail\Ichava\Enums\OptimizationLevel;
use Simtabi\Laranail\Ichava\Services\Traits\ManagesAttributes;
use Simtabi\Laranail\Ichava\Services\Traits\ManagesSizes;
use Simtabi\Laranail\Ichava\Services\Traits\NamespacesSvgIds;
use Simtabi\Laranail\Ichava\Services\Traits\NormalisesSvgSizing;
use Simtabi\Laranail\Ichava\Services\Traits\OptimizesSvg;
use Simtabi\Laranail\Ichava\Services\Traits\ParsesSvg;
use Simtabi\Laranail\Ichava\Services\Traits\SanitizesSvg;

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
    use NormalisesSvgSizing;
    use OptimizesSvg;
    use ParsesSvg;
    use SanitizesSvg;

    /**
     * Bump when a code change alters the bytes this service emits for unchanged
     * input: the id-prefix scheme, the sizing rules, the parser's recovery
     * behaviour, an optimizer pass.
     *
     * Policy changes do NOT need a bump -- the allow-lists are hashed directly
     * below, so editing config invalidates on its own. This constant exists for
     * the changes config cannot see, and it sits in the class whose output it
     * describes so that a reader changing that output has it in front of them.
     */
    public const RENDER_PIPELINE_VERSION = 1;

    public function __construct(
        protected OptimizationLevel $optimizationLevel = OptimizationLevel::BASIC,
    ) {
        $this->initializeSanitizer();
        $this->initializeOptimizer();
    }

    /**
     * A short, stable digest of everything that decides the output bytes for a
     * given input file.
     *
     * The rendered SVG is not the file on disk: ids are namespaced, sizing is
     * normalised and the allow-list policy is applied. So a file hash alone does
     * not identify the response -- widen the policy and every icon changes while
     * every file hash stays put. Anything keyed on content (an HTTP cache, the
     * server-side icon cache) needs both halves or it serves bytes produced by a
     * policy that no longer exists.
     *
     * Deliberately not memoised. This service is bound as a singleton, so a
     * memo would survive a `setAllowedTags()` / `setOptimizationLevel()` call
     * and hand out a fingerprint for a policy that is no longer in effect --
     * the precise failure this method exists to prevent. Hashing five short
     * strings is microseconds; a stale cache key is a year of wrong bytes.
     */
    public function renderFingerprint(): string
    {
        return mb_substr(hash('sha256', implode('|', [
            self::RENDER_PIPELINE_VERSION,
            $this->optimizationLevel->value,
            json_encode($this->getAllowedTags()),
            json_encode($this->getAllowedAttributes()),
            json_encode($this->getForbiddenTags()),
        ])), 0, 12);
    }

    /**
     * Process SVG: sanitize, optimize, and apply attributes
     *
     * @param  string  $content  Raw SVG content
     * @param  array<string, mixed>  $attributes  HTML attributes to apply
     * @param  bool  $optimize  Whether to optimize the SVG
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
     * @param  string  $svg  SVG content
     * @param  array<string, mixed>  $attributes  Attributes to apply
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
