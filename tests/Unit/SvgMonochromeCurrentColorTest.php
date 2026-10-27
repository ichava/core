<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * B7 -- a monochrome icon that hardcodes black vanishes on a dark background.
 *
 * The opt-in pass rewrites pure-black paint to `currentColor`, so the icon
 * follows the surrounding text colour. It is only safe on an icon whose every
 * paint is black or transparent: rewriting the black in a multicolour icon
 * would change its design, not just its theme.
 */
describe('SvgProcessingService::monochromeToCurrentColor', function () {
    beforeEach(function () {
        $this->svg = app(SvgProcessingService::class);
    });

    it('rewrites every black spelling in a monochrome icon', function (string $black) {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<path fill="' . $black . '" d="M0 0h24v24H0z"/>'
            . '<path stroke="' . $black . '" fill="none" d="M1 1h2"/>'
            . '</svg>';

        $out = $this->svg->monochromeToCurrentColor($in);

        expect($out)->toContain('fill="currentColor"')
            ->toContain('stroke="currentColor"')
            ->toContain('fill="none"')
            ->not->toContain($black);
    })->with(['#000', '#000000', 'black', 'BLACK', 'rgb(0,0,0)', 'rgb(0, 0, 0)']);

    it('treats a near-black neutral grey as black (twemoji heavy-dollar-sign)', function (string $grey) {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 36"><path fill="' . $grey . '" d="M0 0h1"/></svg>';

        expect($this->svg->monochromeToCurrentColor($in))->toContain('fill="currentColor"')->not->toContain($grey);
    })->with(['#292F33', '#31373D', '#333']);

    it('keeps dark colours with a hue, and greys past the near-black line', function (string $colour) {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="' . $colour . '" d="M0 0h1"/></svg>';

        expect($this->svg->monochromeToCurrentColor($in))->toBe($in);
    })->with(['#1a237e', '#3b0000', '#444444', '#555']);

    it('rewrites black inside a style attribute', function () {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<path style="fill:#000000;stroke-width:2" d="M0 0h24"/></svg>';

        expect($this->svg->monochromeToCurrentColor($in))
            ->toContain('fill:currentColor')
            ->toContain('stroke-width:2')
            ->not->toContain('#000000');
    });

    it('leaves a multicolour icon untouched', function () {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<path fill="#000" d="M0 0h12"/><path fill="#ff0000" d="M12 0h12"/></svg>';

        expect($this->svg->monochromeToCurrentColor($in))->toBe($in);
    });

    it('leaves an icon painted through a gradient untouched', function () {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<path fill="#000" d="M0 0h12"/><path fill="url(#g)" d="M12 0h12"/></svg>';

        expect($this->svg->monochromeToCurrentColor($in))->toBe($in);
    });

    it('leaves an icon with a style element untouched', function () {
        // A stylesheet can paint any element; this pass does not parse CSS
        // selectors, so it cannot prove such an icon is monochrome.
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<style>.a{fill:#f00}</style><path class="a" fill="#000" d="M0 0h12"/></svg>';

        expect($this->svg->monochromeToCurrentColor($in))->toBe($in);
    });

    it('leaves an icon with no explicit black untouched', function () {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            . '<path fill="currentColor" d="M0 0h12"/></svg>';

        expect($this->svg->monochromeToCurrentColor($in))->toBe($in);
    });
});

describe('monochrome_to_current_color and the render fingerprint', function () {
    it('leaves the fingerprint unchanged while the flag is off', function () {
        // Off is the default; an unchanged fingerprint means no host's
        // existing icon cache is invalidated by this release.
        $svc = new SvgProcessingService;
        $before = $svc->renderFingerprint();

        config()->set('ichava.ichava-core.svg.monochrome_to_current_color', false);

        expect($svc->renderFingerprint())->toBe($before);
    });

    it('changes the fingerprint when the flag is turned on', function () {
        $svc = new SvgProcessingService;
        $before = $svc->renderFingerprint();

        config()->set('ichava.ichava-core.svg.monochrome_to_current_color', true);

        expect($svc->renderFingerprint())->not->toBe($before);
    });

    it('ships the flag off', function () {
        expect(config('ichava.ichava-core.svg.monochrome_to_current_color'))->toBeFalse();
    });
});
