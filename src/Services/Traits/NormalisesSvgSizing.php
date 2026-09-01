<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services\Traits;

use Closure;
use DOMDocument;
use DOMElement;

/**
 * Root-element sizing normalisation.
 *
 * An icon that ships both a `viewBox` and hard `width`/`height` ignores the size
 * the component asks for, because the presentation attributes win over CSS in
 * enough contexts to matter. 6,146 tabler, 11,646 bundled and 239 metronic icons
 * ship exactly that pair.
 *
 * Three cases, and only the root `<svg>` is touched -- a nested `<rect>` without
 * its width is not a rect:
 *
 *   1. viewBox and dimensions both present  -> drop the dimensions. Lossless:
 *      the viewBox keeps the coordinate system and the component regains control.
 *   2. dimensions present, no viewBox       -> synthesise `viewBox="0 0 W H"`,
 *      then case 1 applies to the result.
 *   3. neither usable                       -> report it. The icon still renders;
 *      it just has no intrinsic size to scale against, and shipping that
 *      silently is how it stays broken.
 */
trait NormalisesSvgSizing
{
    /**
     * Normalise the root element's sizing.
     *
     * @param  Closure(string):void|null  $onUnusable  called with a reason when
     *                                                 the icon has neither a viewBox nor dimensions one can be derived
     *                                                 from. Reporting is the caller's business; this pass does not throw
     *                                                 and does not drop the icon.
     */
    public function normaliseSizing(string $content, ?Closure $onUnusable = null): string
    {
        $dom = $this->parseSvgDocument($content);

        if (! $dom instanceof DOMDocument || ! $dom->documentElement instanceof DOMElement) {
            return $content;
        }

        $root = $dom->documentElement;

        $viewBox = $this->rootViewBox($root);
        $width = $this->dimension($root, 'width');
        $height = $this->dimension($root, 'height');

        if ($viewBox === null) {
            if ($width === null || $height === null) {
                // Called plainly rather than rebound: a static closure cannot be
                // bound, and the callback has no business needing our scope.
                if ($onUnusable !== null) {
                    $onUnusable('no viewBox, and width/height are absent or not absolute lengths');
                }

                return $content;
            }

            $root->setAttribute('viewBox', sprintf('0 0 %s %s', $width, $height));
        }

        // Reached with a viewBox either way: the dimensions are now redundant.
        $this->removeAttributeExactly($root, 'width');
        $this->removeAttributeExactly($root, 'height');

        $out = $dom->saveXML($root);

        return $out === false ? $content : $out;
    }

    /**
     * The root `viewBox`, whatever its casing, or null when it is absent or empty.
     */
    private function rootViewBox(DOMElement $root): ?string
    {
        foreach ($root->attributes as $attribute) {
            if (mb_strtolower($attribute->nodeName) === 'viewbox') {
                $value = trim((string) $attribute->nodeValue);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /**
     * An absolute length from `width`/`height`, or null.
     *
     * A unit suffix is accepted and dropped -- `32px` describes the same box as
     * `32` in user units. A percentage is not a length: it resolves against a
     * container this pass cannot see, so no viewBox can be derived from it and
     * the attribute is left where it is.
     */
    private function dimension(DOMElement $root, string $name): ?string
    {
        $raw = null;

        foreach ($root->attributes as $attribute) {
            if (mb_strtolower($attribute->nodeName) === $name) {
                $raw = trim((string) $attribute->nodeValue);
                break;
            }
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! preg_match('/^(\d+(?:\.\d+)?)(px|pt|pc|mm|cm|in|q)?$/i', $raw, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Remove an attribute by its real name.
     *
     * `removeAttribute()` is case-sensitive on an XML document, so removing
     * `width` never removes a `WIDTH` that some authoring tool emitted.
     */
    private function removeAttributeExactly(DOMElement $root, string $name): void
    {
        foreach (iterator_to_array($root->attributes) as $attribute) {
            if (mb_strtolower($attribute->nodeName) === $name) {
                $root->removeAttribute($attribute->nodeName);
            }
        }
    }
}
