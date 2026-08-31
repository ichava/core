<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * We do not control what an icon author ships, and never will. A custom icon
 * set points at a directory on the host; the files in it were drawn in
 * Illustrator, exported by a plugin, or hand-edited.
 *
 * Strict XML parsing rejected six common author mistakes outright, and the
 * accessor turns a rejection into an empty string -- so a stray `&` in a title
 * was a blank icon. Recover instead. The allow-list still runs over whatever
 * the recovery produces, so being forgiving about syntax costs nothing in
 * safety: the sanitiser is what decides content, and it is unchanged.
 */
describe('SVG malformed input recovery', function () {
    beforeEach(function () {
        $this->svg = app(SvgProcessingService::class);
    });

    $recoverable = [
        'an unclosed tag, HTML style' => '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"></svg>',
        'an undefined HTML entity'    => '<svg xmlns="http://www.w3.org/2000/svg"><title>A&nbsp;B</title><path d="M0 0"/></svg>',
        'a bare ampersand in text'    => '<svg xmlns="http://www.w3.org/2000/svg"><title>Tom & Jerry</title><path d="M0 0"/></svg>',
        'an uppercase root element'   => '<SVG xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></SVG>',
        'an unquoted attribute value' => '<svg xmlns="http://www.w3.org/2000/svg"><path d=M00/></svg>',
        'junk after the root element' => '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>trailing',
        'a bare ampersand in a value' => '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0" data-x="a&b"/></svg>',
    ];

    foreach ($recoverable as $label => $input) {
        it("keeps the drawing when given {$label}", function () use ($input) {
            expect($this->svg->sanitize($input))->toContain('<path');
        });
    }

    it('does not lowercase camelCase elements while recovering', function () {
        // An HTML parser would fold linearGradient to lineargradient and the
        // gradient would stop resolving. Recovery has to stay XML.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"><stop offset="0"/></linearGradient></defs><rect fill="url(#g)"></svg>';

        expect($this->svg->sanitize($svg))->toContain('<linearGradient');
    });

    it('still strips a script from a document it had to recover', function () {
        // The point: recovery is about syntax, never about content. A malformed
        // document is not a way past the allow-list.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0"></svg>';

        expect($this->svg->sanitize($svg))
            ->not->toContain('alert')
            ->not->toContain('<script')
            ->toContain('<path');
    });

    it('still strips an event handler from a document it had to recover', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path onload="alert(1)" d="M0 0"></svg>';

        expect($this->svg->sanitize($svg))->not->toContain('onload');
    });

    it('still refuses content with no svg root at all', function () {
        expect(fn () => $this->svg->sanitize('<html><body><p>not an icon</p></body></html>'))
            ->toThrow(IchavaException::class);
    });

    it('still refuses empty content', function () {
        expect(fn () => $this->svg->sanitize('   '))->toThrow(IchavaException::class);
    });

    it('still refuses content that is not markup', function () {
        expect(fn () => $this->svg->sanitize('just a sentence'))->toThrow(IchavaException::class);
    });

    it('leaves a well-formed document byte-identical to the strict path', function () {
        // Recovery must be a fallback, not a rewrite of the happy path.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0"/></svg>';

        expect($this->svg->sanitize($svg))->toBe($svg);
    });
});

describe('every pass recovers, not just the sanitiser', function () {
    beforeEach(function () {
        $this->svg = app(SvgProcessingService::class);
    });

    // One parser, shared. Before it was shared, a file that needed recovering
    // was passed through untouched by these two and only the sanitiser saw it,
    // so a stray `&` silently cost the icon its id namespacing and its sizing
    // normalisation.
    $malformed = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><defs><linearGradient id="g"><stop offset="0"/></linearGradient></defs><rect fill="url(#g)"><title>Tom & Jerry</title></svg>';

    it('namespaces ids in a document that needed recovering', function () use ($malformed) {
        expect($this->svg->namespaceIds($malformed, 'p/f.svg'))
            ->toContain('url(#i')
            ->not->toContain('url(#g)');
    });

    it('normalises sizing in a document that needed recovering', function () use ($malformed) {
        expect($this->svg->normaliseSizing($malformed))
            ->toContain('viewBox="0 0 24 24"')
            ->not->toMatch('/<svg[^>]*(?<![-\w])width=/i');
    });
});
