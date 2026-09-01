<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services\Traits;

use DOMDocument;

/**
 * One SVG parser, shared by every pass that reads a document.
 *
 * We do not control what an icon author ships. A custom icon set points at a
 * directory on the host; the files in it were drawn in Illustrator, exported by
 * a plugin, or hand-edited. Strict XML parsing rejects six ordinary author
 * mistakes outright, and a rejection reaches the consumer as a blank icon.
 *
 * So parse strictly first -- correct, and the fast path for a well-formed
 * corpus -- and fall back to recovery. Recovery is about **syntax only**: the
 * security flags are identical across both passes (no network, no external
 * entities, no entity substitution), and the sanitiser's allow-list runs over
 * whatever comes back. Being forgiving about how a file is written costs
 * nothing in what is allowed to survive it.
 *
 * Every pass shares this, so an icon that needed recovering still gets id
 * namespacing and sizing normalisation rather than silently skipping them.
 */
trait ParsesSvg
{
    /**
     * Parse, recovering from syntax errors where possible.
     *
     * Returns null only when there is no document element at all -- callers
     * decide whether that is fatal (the sanitiser) or a reason to pass the
     * content through untouched (the namespacing and sizing passes).
     */
    private function parseSvgDocument(string $content): ?DOMDocument
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = preg_replace('/^<\?xml.*?\?>\s*/i', '', $content) ?? $content;

        $useErrors = libxml_use_internal_errors(true);

        try {
            return $this->parseSvgOnce($content, strict: true)
                ?? $this->parseSvgOnce($this->repairSvgText($content), strict: false);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useErrors);
        }
    }

    /**
     * One parse attempt.
     *
     * LIBXML_RECOVER handles the structural mistakes -- an unclosed tag, an
     * unquoted attribute value, junk after the root element. It is deliberately
     * not an HTML parse: that would fold `linearGradient` to `lineargradient`
     * and silently stop every gradient from resolving.
     */
    private function parseSvgOnce(string $content, bool $strict): ?DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->strictErrorChecking = false;
        $dom->validateOnParse = false;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;

        $flags = LIBXML_NONET | ($strict ? 0 : LIBXML_RECOVER | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        $loaded = $dom->loadXML($content, $flags);

        // In recovery mode loadXML reports success while having produced
        // nothing usable, so the document element is the real test.
        if ($dom->documentElement === null) {
            return null;
        }

        return ($loaded || ! $strict) ? $dom : null;
    }

    /**
     * Text-level repairs libxml's recovery cannot make for us.
     *
     * Both are ordinary output from drawing tools and hand-editing, and both
     * abort a strict parse:
     *
     * - An HTML named entity. XML defines five; `&nbsp;` and friends are
     *   undefined, so they become the numeric form XML does define.
     * - A bare `&`. "Tom & Jerry" in a <title> is not markup, it is a mistake
     *   nobody notices until the icon renders blank.
     */
    private function repairSvgText(string $content): string
    {
        $content = (string) preg_replace_callback(
            '/&([A-Za-z][A-Za-z0-9]{1,31});/',
            static function (array $m): string {
                // The five XML built-ins pass through untouched.
                if (in_array($m[1], ['amp', 'lt', 'gt', 'quot', 'apos'], true)) {
                    return $m[0];
                }

                $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($decoded === $m[0]) {
                    return '&amp;'.$m[1].';';
                }

                $codepoint = mb_ord($decoded, 'UTF-8');

                return $codepoint === false ? '&amp;'.$m[1].';' : '&#'.$codepoint.';';
            },
            $content,
        );

        // Whatever ampersands are left do not open a valid reference.
        return (string) preg_replace('/&(?!(?:#\d+|#x[0-9A-Fa-f]+|[A-Za-z][A-Za-z0-9]{1,31});)/', '&amp;', $content);
    }
}
