<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * W1-6 item 2 — the `style` attribute is allowed because it is the only paint
 * source for 261 metronic and 2,976 bundled icons. A CSS `url()` pointing off
 * the document is the exfiltration vector: it fires a request that reveals the
 * viewer, and it needs no dangerous protocol to do it. Fragments are the only
 * legitimate target inside an icon.
 */
describe('SVG style attribute values', function () {
    beforeEach(function () {
        $this->sanitizer = app(SvgProcessingService::class);
    });

    it('keeps a fragment url(), which is how a gradient is applied', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path style="fill:url(#grad)" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('url(#grad)');
    });

    it('keeps a plain paint declaration', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path style="fill:#ff0000;stroke-width:2" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('fill:#ff0000');
    });

    it('keeps a CSS custom property', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path style="--brand:#f00;fill:var(--brand)" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('--brand');
    });

    $blocked = [
        'absolute url' => 'background:url(https://evil.test/p.png)',
        'protocol relative' => 'background:url(//evil.test/p.png)',
        'relative url' => 'background:url(sprite.png)',
        'quoted url' => "background:url('https://evil.test/p.png')",
        'spaced url' => 'background:url(  https://evil.test/p.png  )',
        'behavior' => 'behavior:url(x.htc)',
        'moz binding' => '-moz-binding:url(https://evil.test/x.xml#e)',
    ];

    foreach ($blocked as $label => $value) {
        it("strips a style carrying {$label}", function () use ($value) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path style="'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES).'" d="M0 0"/></svg>';

            expect($this->sanitizer->sanitize($svg))->not->toContain('style=');
        });
    }
});
