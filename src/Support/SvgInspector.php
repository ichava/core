<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use DOMElement;
use DOMDocument;

/**
 * Reads an SVG file's source and reports what core's sanitiser would change.
 *
 * The one implementation of the two checks every pack ran with its own copy
 * (P-04): policyViolations() against {@see SvgPolicy}, and strayTextNodes(),
 * the text directly under `<svg>` or `<g>` that a renderer draws as stray
 * glyphs and js-icon-components refuses. A pack whose SVGs report nothing
 * here is served byte-for-byte as shipped.
 */
final class SvgInspector
{
    /**
     * What the policy strips: `doctype`, `entity`, `comment`, `tag {name}`,
     * `attribute {element}@{name}`, `reference {name}`, `value {name}`,
     * `root is not svg`, or `unparseable`. Empty for a clean file.
     *
     * @return list<string>
     */
    public static function policyViolations(string $raw): array
    {
        $found = [];

        if (stripos($raw, '<!DOCTYPE') !== false) {
            $found[] = 'doctype';
        }

        if (str_contains($raw, '<!ENTITY')) {
            $found[] = 'entity';
        }

        if (str_contains($raw, '<!--')) {
            $found[] = 'comment';
        }

        $root = self::parse($raw);

        if ($root === null) {
            return ['unparseable'];
        }

        $tags = array_flip(SvgPolicy::allowedTags());
        $forbidden = array_flip(SvgPolicy::forbiddenTags());
        $attributes = array_flip(SvgPolicy::allowedAttributes());
        $references = SvgPolicy::referenceAttributes();
        $fragment = SvgPolicy::fragmentPattern();

        self::walk($root, function (DOMElement $element) use (&$found, $tags, $forbidden, $attributes, $references, $fragment): void {
            $name = $element->localName;

            if (isset($forbidden[$name]) || ! isset($tags[$name])) {
                $found[] = "tag {$name}";
            }

            foreach ($element->attributes ?? [] as $attribute) {
                $key = $attribute->nodeName;
                $value = (string) $attribute->nodeValue;

                if (! isset($attributes[$key]) && $key !== 'role' && preg_match('/^aria-[a-z]+$/', $key) !== 1) {
                    $found[] = "attribute {$name}@{$key}";
                }

                if (in_array($key, $references, true) && preg_match($fragment, $value) !== 1) {
                    $found[] = "reference {$key}";
                }

                if (preg_match('/javascript:|data:|expression\(|behavior:|-moz-binding|url\(\s*[\'"]?[^#\s\'")]/i', $value) === 1) {
                    $found[] = "value {$key}";
                }
            }
        });

        if ($root->localName !== 'svg') {
            $found[] = 'root is not svg';
        }

        return array_values(array_unique($found));
    }

    /**
     * The trimmed text of every non-blank text or CDATA node directly under
     * `<svg>` or `<g>`; `['unparseable']` when the source does not parse.
     * Text in `<title>`, `<desc>` and `<text>` is content, not stray.
     *
     * @return list<string>
     */
    public static function strayTextNodes(string $raw): array
    {
        $root = self::parse($raw);

        if ($root === null) {
            return ['unparseable'];
        }

        $found = [];

        self::walk($root, function (DOMElement $element) use (&$found): void {
            if (! in_array($element->localName, ['svg', 'g'], true)) {
                return;
            }

            foreach ($element->childNodes as $child) {
                if (($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) && trim((string) $child->nodeValue) !== '') {
                    $found[] = trim((string) $child->nodeValue);
                }
            }
        });

        return $found;
    }

    private static function parse(string $raw): ?DOMElement
    {
        $dom = new DOMDocument;

        if (@$dom->loadXML($raw, LIBXML_NONET) === false) {
            return null;
        }

        return $dom->documentElement;
    }

    /**
     * @param callable(DOMElement): void $visit
     */
    private static function walk(DOMElement $element, callable $visit): void
    {
        $visit($element);

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                self::walk($child, $visit);
            }
        }
    }
}
