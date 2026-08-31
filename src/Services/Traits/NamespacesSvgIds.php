<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services\Traits;

use DOMXPath;
use Throwable;
use DOMElement;
use DOMDocument;

/**
 * Per-file id namespacing. Fixes CR1.
 *
 * SVG ids are document-scoped in theory and page-scoped in practice: inline two
 * icons that both define `id="Layer_1"` and every `url(#Layer_1)` in the second
 * resolves to the first one's definition. Wrong gradient, wrong clip, wrong
 * mask, and no error anywhere. The corpus collides heavily -- 2,157 files share
 * `_Transparent_Rectangle_`, 261 share `Layer_1`, flag-icons carries 576
 * colliding ids -- and the browser renders 60+ icons at once.
 *
 * Collisions only render wrong when the same id points at *different* content,
 * so per-file uniqueness is sufficient. The prefix is derived from the file's
 * path rather than its content, so it survives an upstream asset refresh, and it
 * is deterministic, so the content-addressed cache keeps working and the cost is
 * one pass per file per cache lifetime rather than one per request.
 *
 * Runs BEFORE sanitisation. Every reference form has to move with the id it
 * points at; missing one breaks the icon rather than fixing it.
 */
trait NamespacesSvgIds
{
    /**
     * Attributes whose entire value is a space-separated list of id references.
     *
     * `aria-labelledby` is the one that gets missed -- 283 occurrences in
     * bundled-icons. Rename ids without it and the accessible name silently
     * points at nothing.
     */
    private const ID_LIST_ATTRIBUTES = ['aria-labelledby', 'aria-describedby'];

    /** Attributes whose value may be a `#fragment` reference. */
    private const ID_REFERENCE_ATTRIBUTES = ['href', 'xlink:href'];

    /**
     * Namespace every id in the document, and every reference to one.
     *
     * @param string $seed stable per-file key; the icon's relative path.
     */
    public function namespaceIds(string $content, string $seed): string
    {
        $dom = $this->loadDomForNamespacing($content);

        if (! $dom instanceof DOMDocument || ! $dom->documentElement instanceof DOMElement) {
            return $content;
        }

        $xpath = new DOMXPath($dom);
        $map = $this->buildIdMap($xpath, $seed);

        if ($map === []) {
            return $content;
        }

        foreach ($xpath->query('//*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $rewritten = $this->rewriteIdReferences(
                    mb_strtolower($attribute->nodeName),
                    (string) $attribute->nodeValue,
                    $map,
                );

                if ($rewritten !== $attribute->nodeValue) {
                    $element->setAttribute($attribute->nodeName, $rewritten);
                }
            }
        }

        // CSS in a <style> element references ids the same way an attribute does.
        foreach ($xpath->query('//*[local-name()="style"]') as $style) {
            $style->textContent = $this->rewriteUrlReferences($style->textContent, $map);
        }

        $out = $dom->saveXML($dom->documentElement);

        return $out === false ? $content : $out;
    }

    /**
     * Map every id the document defines to its namespaced form.
     *
     * The prefix always starts with a letter. sha1 is hex, so a bare digest can
     * begin with a digit -- and the sanitiser's fragment rule requires
     * `^#[A-Za-z_]`, so a digit-led prefix would make it strip the very
     * references this pass rewrites.
     *
     * @return array<string, string>
     */
    private function buildIdMap(DOMXPath $xpath, string $seed): array
    {
        $prefix = 'i' . substr(sha1($seed), 0, 6) . '-';
        $map = [];

        foreach ($xpath->query('//*[@id]') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $id = $element->getAttribute('id');

            if ($id !== '') {
                $map[$id] = $prefix . $id;
            }
        }

        return $map;
    }

    /**
     * Rewrite one attribute value against the id map.
     *
     * @param array<string, string> $map
     */
    private function rewriteIdReferences(string $name, string $value, array $map): string
    {
        if ($name === 'id') {
            return $map[$value] ?? $value;
        }

        if (in_array($name, self::ID_REFERENCE_ATTRIBUTES, true)) {
            // Fragments only. An external reference points into someone else's
            // document, where our prefix means nothing.
            if (! str_starts_with($value, '#')) {
                return $value;
            }

            $target = substr($value, 1);

            return isset($map[$target]) ? '#' . $map[$target] : $value;
        }

        if (in_array($name, self::ID_LIST_ATTRIBUTES, true)) {
            $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return implode(' ', array_map(
                static fn (string $token): string => $map[$token] ?? $token,
                $tokens,
            ));
        }

        return $this->rewriteUrlReferences($value, $map);
    }

    /**
     * Rewrite `url(#id)` anywhere in a value: fill, stroke, clip-path, mask,
     * filter, and the same forms inside a `style` value.
     *
     * @param array<string, string> $map
     */
    private function rewriteUrlReferences(string $value, array $map): string
    {
        if (! str_contains($value, 'url(')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/url\(\s*([\'"]?)#([^)\'"\s]+)\1\s*\)/i',
            static function (array $m) use ($map): string {
                $target = $m[2];

                return isset($map[$target])
                    ? 'url(' . $m[1] . '#' . $map[$target] . $m[1] . ')'
                    : $m[0];
            },
            $value,
        );
    }

    /**
     * Parse without touching the network or resolving entities.
     *
     * Returns null on unparseable input: this pass is an improvement, not a
     * gate, and the sanitiser downstream is what refuses bad content.
     */
    private function loadDomForNamespacing(string $content): ?DOMDocument
    {
        $content = preg_replace('/^<\?xml.*?\?>\s*/i', '', $content) ?? $content;

        $useErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->strictErrorChecking = false;
            $dom->validateOnParse = false;
            $dom->resolveExternals = false;
            $dom->substituteEntities = false;

            return $dom->loadXML($content, LIBXML_NONET) ? $dom : null;
        } catch (Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useErrors);
        }
    }
}
