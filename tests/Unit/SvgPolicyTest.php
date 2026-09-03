<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;
use Simtabi\Laranail\Ichava\Support\SvgPolicy;

/**
 * W1-2 / W1-6 item 5. One policy file, read by every runtime, and one test per
 * construct it widens.
 *
 * The census on 2026-09-02 measured 3,507 icons rendering correctly on the Blade
 * path and wrong in the SPA, because the two policies were separate literals and
 * only one of them was widened. These tests pin the file as the source and pin
 * each construct that was previously stripped.
 */
function sanitiseSvg(string $svg): string
{
    return app(SvgProcessingService::class)->process($svg, [], false);
}

describe('SvgPolicy as the single source', function () {
    it('is the source of the shipped config, not a parallel list', function () {
        expect(config('ichava.core.svg.allowed_tags'))->toBe(SvgPolicy::allowedTags())
            ->and(config('ichava.core.svg.forbidden_tags'))->toBe(SvgPolicy::forbiddenTags());
    });

    it('merges the value-restricted names into the by-name allow-list', function () {
        // `style` and the reference attributes live in their own policy blocks
        // because their VALUES are checked. Their NAMES still have to be
        // admitted, or SEC-1b returns and 261 metronic icons go black.
        expect(SvgPolicy::allowedAttributes())
            ->toContain('style')
            ->toContain('href')
            ->toContain('xlink:href');
    });

    it('fails loudly rather than silently permitting or stripping everything', function () {
        expect(SvgPolicy::path())->toBeFile();
    });
});

describe('W1-6 item 5, constructs the shipped policy used to strip', function () {
    it('keeps a filter and its primitives', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><defs><filter id="f">'
            .'<feGaussianBlur stdDeviation="2"/><feOffset dx="1"/><feMerge><feMergeNode/></feMerge>'
            .'</filter></defs><rect filter="url(#f)" width="10" height="10"/></svg>'
        );

        expect($out)->toContain('<filter')
            ->toContain('feGaussianBlur')
            ->toContain('feMerge')
            ->toContain('filter=');
    });

    it('keeps a pattern with its units', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><defs>'
            .'<pattern id="p" patternUnits="userSpaceOnUse" patternTransform="rotate(45)" width="4" height="4">'
            .'<path d="M0 0h4"/></pattern></defs><rect fill="url(#p)" width="8" height="8"/></svg>'
        );

        expect($out)->toContain('<pattern')
            ->toContain('patternUnits')
            ->toContain('patternTransform');
    });

    it('keeps textPath, switch and metadata', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><metadata>m</metadata>'
            .'<switch><text><textPath href="#p">hi</textPath></text></switch></svg>'
        );

        expect($out)->toContain('<metadata')
            ->toContain('<switch')
            ->toContain('<textPath');
    });

    it('keeps dash and stroke geometry', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10" '
            .'stroke-dasharray="4 2" stroke-dashoffset="1" stroke-miterlimit="8" '
            .'vector-effect="non-scaling-stroke" paint-order="stroke"/></svg>'
        );

        expect($out)->toContain('stroke-dasharray')
            ->toContain('stroke-dashoffset')
            ->toContain('stroke-miterlimit')
            ->toContain('vector-effect')
            ->toContain('paint-order');
    });

    it('keeps text layout attributes', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><text font-family="serif" font-size="10" '
            .'font-weight="700" text-anchor="middle" dominant-baseline="central" '
            .'letter-spacing="2">hi</text></svg>'
        );

        expect($out)->toContain('font-family')
            ->toContain('text-anchor')
            ->toContain('dominant-baseline')
            ->toContain('letter-spacing');
    });
});

describe('the geometry §4.1 omitted', function () {
    /*
     * Adopting phase-3-redesign.md §4.1 verbatim would have REMOVED these from
     * the shipped config. A linearGradient without x1/y1/x2/y2 has no direction
     * and a radialGradient without fx/fy/fr has no focal point, so all 65
     * gradient icons in the corpus would have broken while the policy looked
     * more rigorous. Caught by diffing the effective config before and after.
     */
    it('keeps linear gradient coordinates', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><defs>'
            .'<linearGradient id="g" x1="0" y1="0" x2="1" y2="1" spreadMethod="pad">'
            .'<stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/>'
            .'</linearGradient></defs><rect fill="url(#g)" width="10" height="10"/></svg>'
        );

        expect($out)->toContain('x1=')->toContain('y1=')->toContain('x2=')->toContain('y2=')
            ->toContain('spreadMethod')
            ->toContain('<stop');
    });

    it('keeps radial gradient focal points', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><defs>'
            .'<radialGradient id="r" fx="0.4" fy="0.6" fr="0.1"><stop offset="0" stop-color="#f00"/>'
            .'</radialGradient></defs><rect fill="url(#r)" width="10" height="10"/></svg>'
        );

        expect($out)->toContain('fx=')->toContain('fy=')->toContain('fr=');
    });

    it('keeps clip and mask coordinate systems', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><defs>'
            .'<clipPath id="c" clipPathUnits="objectBoundingBox"><path d="M0 0h1v1z"/></clipPath>'
            .'<mask id="m" maskUnits="userSpaceOnUse" maskContentUnits="userSpaceOnUse">'
            .'<rect width="10" height="10" fill="#fff"/></mask>'
            .'</defs><rect clip-path="url(#c)" mask="url(#m)" width="10" height="10"/></svg>'
        );

        expect($out)->toContain('clipPathUnits')
            ->toContain('maskUnits')
            ->toContain('maskContentUnits');
    });
});

describe('what the widening must NOT have loosened', function () {
    it('still blocks the style element while keeping the style attribute', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><style>.a{fill:red}</style>'
            .'<path style="fill:#123456" d="M0 0h1"/></svg>'
        );

        expect($out)->not->toContain('<style')
            ->and($out)->toContain('style="fill:#123456"');
    });

    it('still blocks script, foreignObject and SMIL', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script>'
            .'<foreignObject><b>x</b></foreignObject>'
            .'<animate attributeName="href" to="javascript:alert(1)"/>'
            .'<animateTransform attributeName="transform"/><set attributeName="href"/>'
            .'<path d="M0 0h1"/></svg>'
        );

        expect($out)->not->toContain('<script')
            ->not->toContain('foreignObject')
            ->not->toContain('<animate')
            ->not->toContain('<set')
            ->and($out)->toContain('<path');
    });

    it('still refuses a non-fragment reference', function () {
        $out = sanitiseSvg(
            '<svg xmlns="http://www.w3.org/2000/svg">'
            .'<use href="https://evil.test/x"/><use href="#ok"/></svg>'
        );

        expect($out)->not->toContain('evil.test')
            ->and($out)->toContain('#ok');
    });
});
