<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * W1-6 item 1 / SEC-1 -- `href` and `xlink:href` were stripped unconditionally,
 * which left `<use>` and `<symbol>` allowed but inert: 126 flag-icons, 5
 * metronic and 130 bundled icons rendered as nothing. A fragment reference
 * cannot fetch, leak or execute, so the policy is fragment-only rather than
 * all-or-nothing.
 */
describe('SVG fragment references', function () {
    beforeEach(function () {
        $this->sanitizer = app(SvgProcessingService::class);
    });

    it('keeps a fragment href on use', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><path id="a" d="M0 0"/></defs><use href="#a"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('href="#a"');
    });

    it('keeps a fragment xlink:href, which is what the flag packs actually ship', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><path id="a" d="M0 0"/></defs><use xlink:href="#a"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('#a');
    });

    it('keeps a fragment id containing dots, colons and hyphens', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><use href="#ad-a.b:c"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('href="#ad-a.b:c"');
    });

    $external = [
        'absolute'          => 'https://evil.test/x.svg#a',
        'protocol relative' => '//evil.test/x.svg#a',
        'relative path'     => 'sprite.svg#a',
        'data uri'          => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
        'javascript'        => 'javascript:alert(1)',
        'blob'              => 'blob:https://evil.test/1234',
        'empty fragment'    => '#',
        'digit-led id'      => '#1nvalid',
    ];

    foreach ($external as $label => $value) {
        it("strips a non-fragment href: {$label}", function () use ($value) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg"><use href="' . htmlspecialchars($value, ENT_XML1) . '"/></svg>';

            expect($this->sanitizer->sanitize($svg))->not->toContain('href=');
        });
    }

    it('still removes an anchor element outright, href or not', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><a href="#a"><path d="M0 0"/></a></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('<a');
    });
});
