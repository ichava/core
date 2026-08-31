<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * W1-6 item 4 — `<title>` and `<desc>` survived, but `role` and every `aria-*`
 * attribute were stripped, so an icon that carried an accessible name lost it
 * on the way through the sanitiser. The name survived in the document and
 * nothing pointed at it.
 */
describe('SVG accessibility attributes', function () {
    beforeEach(function () {
        $this->sanitizer = app(SvgProcessingService::class);
    });

    it('keeps the accessible name wiring intact', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="t d"><title id="t">Flag of Kenya</title><desc id="d">A vertical tricolour</desc><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))
            ->toContain('role="img"')
            ->toContain('aria-labelledby="t d"')
            ->toContain('<title id="t">Flag of Kenya</title>')
            ->toContain('<desc id="d">');
    });

    it('keeps aria-hidden, which is how a decorative icon opts out', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('aria-hidden="true"');
    });

    it('keeps aria-label and aria-describedby', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" aria-label="Search" aria-describedby="d"><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))
            ->toContain('aria-label="Search"')
            ->toContain('aria-describedby="d"');
    });

    it('still removes a real event handler alongside an aria attribute', function () {
        // `aria-onload` is not an event handler -- no browser binds it, it is
        // inert junk -- so this asserts the property that matters: the real
        // `onload` does not survive. Requiring the sanitiser to also strip
        // inert junk would mean contorting the name rule for no security gain.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" aria-label="x" onload="alert(1)"><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))
            ->not->toContain('onload')
            ->toContain('aria-label="x"');
    });

    it('refuses a namespaced name wearing the aria prefix', function () {
        // Casing is deliberately not part of this: names are matched
        // case-insensitively throughout (V42), so `aria-Label` is accepted and
        // then ignored by the browser as the unknown attribute it is. A colon
        // is different -- it declares a namespace, and those are refused.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" aria-x:y="1"><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('aria-x:y');
    });

    it('does not let the aria prefix smuggle a dangerous value', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" aria-label="javascript:alert(1)"><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('javascript:');
    });

    it('still removes an unknown non-aria attribute', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" data-tracking="1"><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('data-tracking');
    });
});
