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
 * Event handler attributes (on*) and formaction are always stripped regardless of
 * whitelist contents. `href`/`xlink:href` survive only as same-document fragments.
 * Allowed tags and attributes can be overridden via ichava.core.svg.* config.
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
        'formaction', 'form',
    ];

    /**
     * Attributes whose value is a reference. Allowed only when the value is a
     * same-document fragment: a fragment cannot fetch, leak or execute, while
     * every other form can. See REFERENCE_FRAGMENT.
     */
    private const REFERENCE_ATTRIBUTES = [
        'href', 'xlink:href',
    ];

    /**
     * A same-document fragment reference. Deliberately strict about the first
     * character: an id has to be a name, and `#1nvalid` is not one.
     */
    private const REFERENCE_FRAGMENT = '/^#[A-Za-z_][\w.:-]*$/';

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

    /** @var array<string, int> lowercased index of */
    private array $allowedTagIndex = [];

    /** @var array<string, int> lowercased index of */
    private array $allowedAttributeIndex = [];

    /** @var array<string, int> lowercased index of */
    private array $forbiddenTagIndex = [];

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
        $this->refreshLookups();

        return $this;
    }

    public function setAllowedAttributes(array $attributes): self
    {
        $this->allowedAttributes = $attributes;
        $this->refreshLookups();

        return $this;
    }

    public function setForbiddenTags(array $tags): self
    {
        $this->forbiddenTags = $tags;
        $this->refreshLookups();

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
        $this->allowedTags = config('ichava.core.svg.allowed_tags', $this->getDefaultAllowedTags());
        $this->allowedAttributes = config('ichava.core.svg.allowed_attributes', $this->getDefaultAllowedAttributes());
        $this->forbiddenTags = config('ichava.core.svg.forbidden_tags', ['script', 'foreignObject', 'iframe']);

        $this->refreshLookups();
    }

    /**
     * The lists are authored in SVG's own casing -- `clipPath`,
     * `linearGradient`, `viewBox` -- while node names are compared lowercased.
     * Comparing a lowercased name against a camelCase list entry can never
     * match, so every camelCase entry was silently unreachable and the elements
     * were removed on every icon (V42). Keep a lowercased index for lookups and
     * the original list for the public getters.
     */
    private function refreshLookups(): void
    {
        $fold = static fn (array $names): array => array_flip(array_map(
            static fn (string $name): string => mb_strtolower($name),
            $names,
        ));

        $this->allowedTagIndex = $fold($this->allowedTags);
        $this->allowedAttributeIndex = $fold($this->allowedAttributes);
        $this->forbiddenTagIndex = $fold($this->forbiddenTags);
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
            // Paint infrastructure: a gradient, clip or mask that is allowed as
            // an element but stripped of its geometry paints nothing.
            'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
            'fx', 'fy', 'fr', 'clipPathUnits', 'clip-path',
            'maskUnits', 'maskContentUnits', 'mask',
            'preserveAspectRatio',
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
        if (isset($this->forbiddenTagIndex[mb_strtolower($node->nodeName)])) {
            $node->parentNode?->removeChild($node);

            return;
        }

        // Whitelist check
        if (! isset($this->allowedTagIndex[mb_strtolower($node->nodeName)])) {
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
            // Compare lowercased, remove by the real name. removeAttribute() is
            // case-sensitive on an XML document, so removing `viewbox` never
            // removed `viewBox` and disallowed camelCase attributes survived by
            // accident (V42).
            $actual = $attribute->nodeName;
            $name = mb_strtolower($actual);
            $value = $attribute->nodeValue;

            if ($this->isDangerousAttribute($name) ||
                $this->hasDangerousValue($value) ||
                ! $this->isAllowedAttribute($name) ||
                ! $this->isAllowedReferenceValue($name, (string) $value) ||
                ! $this->isAllowedStyleValue($name, (string) $value)) {
                $toRemove[] = $actual;
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

        if (in_array($name, self::REFERENCE_ATTRIBUTES, true)) {
            return false;
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

        // Scan the whole value, not just its start. A protocol reached through
        // a CSS `url()` -- style="background:url(javascript:alert(1))" -- is not
        // at position 0, and `style` is an allowed attribute, so a start-anchored
        // test let it through untouched (V41).
        //
        // Whitespace and control characters are removed first because they are
        // ignored between a scheme's characters when the value is parsed, so
        // "java\nscript:" and "javascript:" are the same payload.
        $collapsed = (string) preg_replace('/[\s\x00-\x1f\x7f]+/u', '', $lower);

        foreach (self::DANGEROUS_PROTOCOLS as $protocol) {
            if (Str::contains($lower, $protocol) || Str::contains($collapsed, $protocol)) {
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

        if (in_array($name, self::REFERENCE_ATTRIBUTES, true)) {
            return true;
        }

        // ARIA is an inert namespace: nothing under `aria-` is a script sink,
        // and an icon shipping <title>/<desc> needs aria-labelledby pointing at
        // them or the accessible name is unreachable. Allowed by shape rather
        // than by list, so a newly standardised ARIA attribute does not become
        // an accessibility regression, while the shape keeps out names carrying
        // uppercase, colons or punctuation. The value check runs regardless of
        // how the name was allowed.
        if ($name === 'role' || preg_match('/^aria-[a-z]+$/', $name) === 1) {
            return true;
        }

        return isset($this->allowedAttributeIndex[$name]);
    }

    /**
     * The `style` attribute stays because it is the only paint source for
     * thousands of icons, but a CSS `url()` aimed off the document is an
     * exfiltration vector that needs no dangerous protocol to work: loading it
     * reveals the viewer. Inside an icon the only legitimate target is a
     * fragment. `behavior:` and `-moz-binding` are script sinks in their own
     * right, whatever they point at.
     */
    private function isAllowedStyleValue(string $name, string $value): bool
    {
        if ($name !== 'style') {
            return true;
        }

        $collapsed = (string) preg_replace('/[\s\x00-\x1f\x7f]+/u', '', mb_strtolower($value));

        if (Str::contains($collapsed, ['behavior:', '-moz-binding'])) {
            return false;
        }

        preg_match_all('/url\(([^)]*)\)/i', $collapsed, $matches);

        foreach ($matches[1] as $target) {
            $target = trim($target, "'\"");

            if (! str_starts_with($target, '#')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reference attributes carry a same-document fragment or they do not
     * survive. Non-reference attributes are unaffected.
     */
    private function isAllowedReferenceValue(string $name, string $value): bool
    {
        if (! in_array($name, self::REFERENCE_ATTRIBUTES, true)) {
            return true;
        }

        return (bool) preg_match(self::REFERENCE_FRAGMENT, $value);
    }
}
