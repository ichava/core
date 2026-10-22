<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services\Traits;

use DOMElement;
use DOMDocument;

/**
 * Opt-in: let a monochrome icon that hardcodes black follow the text colour.
 *
 * An icon drawn as `fill="#000"` renders black whatever the page around it
 * does, so on a dark background it vanishes. Rewriting that black to
 * `currentColor` makes it inherit the surrounding `color`, which is what a
 * monochrome glyph almost always means.
 *
 * It is only safe on an icon whose every paint is black or transparent. In a
 * multicolour icon the black is part of the design, and rewriting it would
 * change the drawing rather than its theme -- so an icon carrying any other
 * colour, a gradient or pattern (`url(...)`), or a `<style>` element (whose
 * selectors this pass does not evaluate) is returned untouched.
 *
 * Only explicit black is rewritten. An element with no paint at all also
 * renders black by default; adding paint to it is a different, broader change.
 *
 * The pass is gated by `ichava.ichava-core.svg.monochrome_to_current_color`
 * at the call site, and the flag feeds renderFingerprint(), so turning it on
 * re-keys the icon cache without a manual cache-version bump.
 */
trait ConvertsMonochromeToCurrentColor
{
    /** Presentation attributes and style properties that carry paint. */
    private const PAINT_PROPERTIES = ['fill', 'stroke', 'color', 'stop-color', 'flood-color', 'lighting-color'];

    /** Values that paint nothing, or already follow the text colour. */
    private const NEUTRAL_PAINTS = ['none', 'transparent', 'currentcolor', 'inherit', 'unset', 'initial'];

    /**
     * Rewrite pure-black paint to `currentColor` when the icon is monochrome.
     *
     * Returns the input unchanged when the icon cannot be parsed, is not
     * monochrome, or carries no explicit black.
     */
    public function monochromeToCurrentColor(string $content): string
    {
        $dom = $this->parseSvgDocument($content);

        if (! $dom instanceof DOMDocument || ! $dom->documentElement instanceof DOMElement) {
            return $content;
        }

        if ($dom->getElementsByTagName('style')->length > 0) {
            return $content;
        }

        /** @var list<DOMElement> $elements */
        $elements = [$dom->documentElement];

        foreach ($dom->documentElement->getElementsByTagName('*') as $element) {
            $elements[] = $element;
        }

        $sawBlack = false;

        foreach ($elements as $element) {
            foreach ($this->paintValues($element) as $value) {
                if ($this->isBlackPaint($value)) {
                    $sawBlack = true;

                    continue;
                }

                if (! in_array(mb_strtolower($value), self::NEUTRAL_PAINTS, true)) {
                    return $content;
                }
            }
        }

        if (! $sawBlack) {
            return $content;
        }

        foreach ($elements as $element) {
            $this->rewriteBlackPaint($element);
        }

        $out = $dom->saveXML($dom->documentElement);

        return $out === false ? $content : $out;
    }

    /**
     * Every paint value on an element: presentation attributes, then the
     * matching declarations inside its `style` attribute.
     *
     * @return list<string>
     */
    private function paintValues(DOMElement $element): array
    {
        $values = [];

        foreach ($element->attributes as $attribute) {
            $name = mb_strtolower($attribute->nodeName);

            if (in_array($name, self::PAINT_PROPERTIES, true)) {
                $values[] = trim((string) $attribute->nodeValue);
            }

            if ($name === 'style') {
                foreach (explode(';', (string) $attribute->nodeValue) as $declaration) {
                    [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);

                    if ($value !== null && in_array(mb_strtolower(trim($property)), self::PAINT_PROPERTIES, true)) {
                        $values[] = trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value);
                    }
                }
            }
        }

        return $values;
    }

    private function isBlackPaint(string $value): bool
    {
        return (bool) preg_match('/^(?:#000(?:000)?|black|rgb\(\s*0\s*,\s*0\s*,\s*0\s*\))$/i', trim($value));
    }

    private function rewriteBlackPaint(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = mb_strtolower($attribute->nodeName);

            if (in_array($name, self::PAINT_PROPERTIES, true) && $this->isBlackPaint((string) $attribute->nodeValue)) {
                $element->setAttribute($attribute->nodeName, 'currentColor');
            }

            if ($name === 'style') {
                $declarations = explode(';', (string) $attribute->nodeValue);

                foreach ($declarations as $i => $declaration) {
                    [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);

                    if ($value === null || ! in_array(mb_strtolower(trim($property)), self::PAINT_PROPERTIES, true)) {
                        continue;
                    }

                    $important = (bool) preg_match('/!important\s*$/i', $value);
                    $bare = trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value);

                    if ($this->isBlackPaint($bare)) {
                        $declarations[$i] = $property . ':currentColor' . ($important ? ' !important' : '');
                    }
                }

                $element->setAttribute($attribute->nodeName, implode(';', $declarations));
            }
        }
    }
}
