<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services\Traits;

use DOMNode;
use DOMElement;
use DOMDocument;
use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * SanitizesSvg - Whitelist-Based SVG Sanitization
 *
 * Parses raw SVG content through a DOMDocument and applies three layers
 * of defence to prevent XSS, external resource loading, and code injection.
 * Used as a composable trait inside SvgProcessingService.
 *
 * Defence layers:
 * - Element whitelist  , only tags in $allowedTags survive; all others are removed
 * - Attribute whitelist, only attributes in $allowedAttributes are kept per element
 * - Protocol blocklist , href / xlink:href / src values are rejected if they match
 *                         any entry in DANGEROUS_PROTOCOLS (e.g. javascript:, data:image/svg+xml)
 *
 * Event handler attributes (on*), formaction, and href are always stripped regardless
 * of whitelist contents. Allowed tags and attributes can be overridden via ichava.svg.* config.
 *
 * @see SvgProcessingService
 */
trait SanitizesSvg
{
    /**
     * Dangerous attributes
     */
    private const DANGEROUS_ATTRIBUTES = [
        'onload', 'onerror', 'onclick', 'onmouseover', 'onmouseout',
        'onmousemove', 'onmouseenter', 'onmouseleave', 'onmousedown',
        'onmouseup', 'onfocus', 'onblur', 'onchange', 'onsubmit',
        'onkeydown', 'onkeyup', 'onkeypress', 'onanimationstart',
        'onanimationend', 'onanimationiteration', 'ontransitionend',
        'formaction', 'form', 'xlink:href', 'href',
    ];

    /**
     * Dangerous protocols
     */
    private const DANGEROUS_PROTOCOLS = [
        'javascript:',
        'data:text/html',
        'data:text/xml',
        'data:text/javascript',
        'data:application/',
        'data:image/svg+xml',
        'vbscript:',
        'file:',
        'about:',
    ];

    /**
     * Allowed SVG elements (whitelist)
     */
    private array $allowedTags;

    /**
     * Allowed SVG attributes (whitelist)
     */
    private array $allowedAttributes;

    /**
     * Forbidden SVG elements (blacklist)
     */
    private array $forbiddenTags;

    /**
     * Sanitize SVG content
     *
     * @throws IchavaException
     */
    public function sanitize(string $content): string
    {
        if (empty(trim($content))) {
            throw IchavaException::invalidSvgContent('SVG content is empty');
        }

        // Remove XML declaration
        $content = preg_replace('/^<\?xml.*?\?>\s*/i', '', $content) ?? $content;

        // Load into DOM with security settings
        $dom = $this->loadSecureDom($content);

        // Sanitize the tree
        $this->sanitizeNode($dom->documentElement);

        // Export
        $sanitized = $dom->saveXML($dom->documentElement);

        if ($sanitized === false) {
            throw IchavaException::invalidSvgContent('Failed to serialize sanitized SVG');
        }

        return $sanitized;
    }

    /**
     * Setters for custom configuration
     */
    public function setAllowedTags(array $tags): self
    {
        $this->allowedTags = $tags;

        return $this;
    }

    public function setAllowedAttributes(array $attributes): self
    {
        $this->allowedAttributes = $attributes;

        return $this;
    }

    public function setForbiddenTags(array $tags): self
    {
        $this->forbiddenTags = $tags;

        return $this;
    }

    /**
     * Getters
     */
    public function getAllowedTags(): array
    {
        return $this->allowedTags;
    }

    public function getAllowedAttributes(): array
    {
        return $this->allowedAttributes;
    }

    public function getForbiddenTags(): array
    {
        return $this->forbiddenTags;
    }

    /**
     * Initialise the sanitizer lists from config, falling back to built-in defaults.
     */
    protected function initializeSanitizer(): void
    {
        $this->allowedTags = config('ichava.svg.allowed_tags', $this->getDefaultAllowedTags());
        $this->allowedAttributes = config('ichava.svg.allowed_attributes', $this->getDefaultAllowedAttributes());
        $this->forbiddenTags = config('ichava.svg.forbidden_tags', ['script', 'foreignObject', 'iframe']);
    }

    /**
     * Default allowed SVG tags (fallback if config not loaded)
     */
    private function getDefaultAllowedTags(): array
    {
        return [
            'svg', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon',
            'g', 'defs', 'clipPath', 'mask', 'use', 'symbol',
            'linearGradient', 'radialGradient', 'ellipse', 'text', 'tspan',
            'stop', 'title', 'desc',
        ];
    }

    /**
     * Default allowed SVG attributes (fallback if config not loaded)
     */
    private function getDefaultAllowedAttributes(): array
    {
        return [
            'viewBox', 'width', 'height', 'fill', 'stroke', 'd',
            'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'transform',
            'stroke-width', 'stroke-linecap', 'stroke-linejoin',
            'opacity', 'fill-opacity', 'stroke-opacity',
            'x1', 'x2', 'y1', 'y2', 'offset', 'stop-color',
            'fill-rule', 'clip-rule', 'points', 'style',
        ];
    }

    /**
     * Load SVG into secure DOMDocument
     */
    private function loadSecureDom(string $content): DOMDocument
    {
        $useErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->strictErrorChecking = false;
            $dom->validateOnParse = false;
            $dom->resolveExternals = false;
            $dom->substituteEntities = false;

            $loaded = $dom->loadXML($content, LIBXML_NONET);

            if (! $loaded || $dom->documentElement === null) {
                throw IchavaException::invalidSvgContent('Invalid SVG XML structure');
            }

            if ($dom->documentElement->nodeName !== 'svg') {
                throw IchavaException::invalidSvgContent('Root element must be <svg>');
            }

            return $dom;
        } finally {
            libxml_use_internal_errors($useErrors);
            libxml_clear_errors();
        }
    }

    /**
     * Sanitize DOM node recursively
     */
    private function sanitizeNode(DOMNode $node): void
    {
        if (! $node instanceof DOMElement) {
            return;
        }

        // Blacklist check
        if (in_array(Str::lower($node->nodeName), $this->forbiddenTags, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        // Whitelist check
        if (! in_array(Str::lower($node->nodeName), $this->allowedTags, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        // Sanitize attributes
        $this->sanitizeAttributes($node);

        // Recursively sanitize children
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->sanitizeNode($child);
        }
    }

    /**
     * Sanitize element attributes
     */
    private function sanitizeAttributes(DOMElement $element): void
    {
        $toRemove = [];

        foreach ($element->attributes as $attribute) {
            $name = Str::lower($attribute->nodeName);
            $value = $attribute->nodeValue;

            if ($this->isDangerousAttribute($name) ||
                $this->hasDangerousValue($value) ||
                ! $this->isAllowedAttribute($name)) {
                $toRemove[] = $name;
            }
        }

        foreach ($toRemove as $name) {
            $element->removeAttribute($name);
        }
    }

    /**
     * Check if attribute is dangerous
     */
    private function isDangerousAttribute(string $name): bool
    {
        if (in_array($name, self::DANGEROUS_ATTRIBUTES, true)) {
            return true;
        }

        if (Str::startsWith($name, 'on')) {
            return true;
        }

        if (Str::contains($name, ':') && ! Str::startsWith($name, 'xmlns')) {
            return true;
        }

        return false;
    }

    /**
     * Check if value is dangerous
     */
    private function hasDangerousValue(string $value): bool
    {
        $lower = Str::lower($value);

        foreach (self::DANGEROUS_PROTOCOLS as $protocol) {
            if (Str::startsWith($lower, $protocol)) {
                return true;
            }
        }

        if (Str::contains($lower, '<script') ||
            Str::contains($lower, 'expression(') ||
            Str::contains($lower, '@import')) {
            return true;
        }

        return false;
    }

    /**
     * Check if attribute is allowed
     */
    private function isAllowedAttribute(string $name): bool
    {
        if (in_array($name, ['xmlns', 'id', 'class'], true)) {
            return true;
        }

        return in_array($name, $this->allowedAttributes, true);
    }
}
