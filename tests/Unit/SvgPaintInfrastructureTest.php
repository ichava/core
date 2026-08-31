<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * W1-6 item 3 / V42 — the allow-lists are compared against a lowercased node
 * name, so every camelCase entry in them was unmatchable: `clipPath`,
 * `linearGradient` and `radialGradient` were removed on every icon despite
 * being allowed. Gradient icons lost their paint source and kept a dangling
 * `fill="url(#g)"`.
 */
describe('SVG paint infrastructure', function () {
    beforeEach(function () {
        $this->sanitizer = app(SvgProcessingService::class);
    });

    it('keeps a linear gradient and its stops', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/></linearGradient></defs><rect fill="url(#g)"/></svg>';

        expect($this->sanitizer->sanitize($svg))
            ->toContain('<linearGradient')
            ->toContain('<stop');
    });

    it('keeps a radial gradient', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><radialGradient id="r"><stop offset="0"/></radialGradient></defs></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('<radialGradient');
    });

    it('keeps a clipPath and the reference to it', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="c"><rect/></clipPath></defs><path clip-path="url(#c)" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))
            ->toContain('<clipPath')
            ->toContain('clip-path="url(#c)"');
    });

    it('keeps a mask and the reference to it', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><mask id="m"><rect/></mask></defs><path mask="url(#m)" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('mask="url(#m)"');
    });

    it('keeps the geometry attributes a gradient needs to paint', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g" gradientUnits="userSpaceOnUse" gradientTransform="rotate(45)"><stop offset="0" stop-opacity=".5"/></linearGradient></defs></svg>';

        expect($this->sanitizer->sanitize($svg))
            ->toContain('gradientUnits')
            ->toContain('gradientTransform')
            ->toContain('stop-opacity');
    });

    it('keeps viewBox, which the lowercasing also made unmatchable', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" preserveAspectRatio="xMidYMid meet"><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))
            ->toContain('viewBox="0 0 24 24"')
            ->toContain('preserveAspectRatio');
    });

    it('still removes a camelCase element that is not allowed', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><feImage href="#a"/><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('feImage');
    });

    it('still removes a camelCase attribute that is not allowed', function () {
        // This one survived by accident before: the removal was attempted with
        // the lowercased name, which does not match in an XML document.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path requiredExtensions="http://evil.test" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('requiredExtensions');
    });
});
