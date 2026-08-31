<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * V41 — dangerous protocols were only detected at the start of an attribute
 * value, so any protocol reached through a CSS url() in a `style` attribute
 * survived sanitisation. `style` is in the effective allowed-attribute list,
 * so these are reachable.
 */
describe('SVG protocol scanning inside attribute values', function () {
    beforeEach(function () {
        $this->sanitizer = app(SvgProcessingService::class);
    });

    it('strips a javascript: protocol reached through a style url()', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path style="background:url(javascript:alert(1))" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('javascript:');
    });

    it('strips a vbscript: protocol reached through a style url()', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path style="background:url(vbscript:msgbox(1))" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('vbscript:');
    });

    it('strips a data:text/html payload embedded mid-value', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path style="background:url(data:text/html;base64,PHNjcmlwdD4=)" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('data:text/html');
    });

    it('sees through whitespace and control characters splitting the scheme', function () {
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\"><path style=\"background:url(java\nscript:alert(1))\" d=\"M0 0\"/></svg>";

        expect($this->sanitizer->sanitize($svg))->not->toContain('alert');
    });

    it('keeps a legitimate style value that only paints', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path style="fill:#ff0000;stroke:#00ff00" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('fill:#ff0000');
    });

    it('keeps a fragment url() reference, which is inert', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path style="fill:url(#grad)" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('url(#grad)');
    });
});
