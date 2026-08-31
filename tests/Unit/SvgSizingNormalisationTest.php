<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * W1-6 sizing pass. An icon that carries both a `viewBox` and hard
 * `width`/`height` ignores the size the component asks for: the attributes win
 * over CSS in enough contexts to matter, and 6,146 tabler, 11,646 bundled and
 * 239 metronic icons ship exactly that pair. Stripping them is lossless -- the
 * viewBox keeps the coordinate system -- and hands sizing back to the component.
 */
describe('SVG sizing normalisation', function () {
    beforeEach(function () {
        $this->svg = app(SvgProcessingService::class);
    });

    it('strips width and height when a viewBox already defines the geometry', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M0 0"/></svg>';

        expect($this->svg->normaliseSizing($svg))
            ->toContain('viewBox="0 0 24 24"')
            ->not->toContain('width=')
            ->not->toContain('height=');
    });

    it('leaves width and height on nested elements alone', function () {
        // Only the root svg is normalised. A rect without its width is not a rect.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><rect width="10" height="10"/></svg>';

        expect($this->svg->normaliseSizing($svg))
            ->toContain('<rect width="10" height="10"/>')
            ->not->toContain('<svg xmlns="http://www.w3.org/2000/svg" width=');
    });

    it('synthesises a viewBox from width and height when none is present', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="48"><path d="M0 0"/></svg>';

        expect($this->svg->normaliseSizing($svg))->toContain('viewBox="0 0 32 48"');
    });

    it('reads dimensions carrying a unit', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="32px" height="48px"><path d="M0 0"/></svg>';

        expect($this->svg->normaliseSizing($svg))->toContain('viewBox="0 0 32 48"');
    });

    it('reads a decimal dimension without rounding it away', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16.5" height="16.5"><path d="M0 0"/></svg>';

        expect($this->svg->normaliseSizing($svg))->toContain('viewBox="0 0 16.5 16.5"');
    });

    it('strips the dimensions it just used to synthesise the viewBox', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="48"><path d="M0 0"/></svg>';

        $out = $this->svg->normaliseSizing($svg);

        expect($out)->toContain('viewBox="0 0 32 48"')->not->toContain('width="32"');
    });

    it('leaves a relative dimension alone, since no viewBox can be derived from it', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%"><path d="M0 0"/></svg>';

        $out = $this->svg->normaliseSizing($svg);

        expect($out)->toContain('width="100%"')->not->toContain('viewBox');
    });

    it('reports an icon that has neither a viewBox nor usable dimensions', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>';

        $unusable = null;
        $out = $this->svg->normaliseSizing($svg, function (string $reason) use (&$unusable): void {
            $unusable = $reason;
        });

        // Reported, not silently dropped: it still renders, just without an
        // intrinsic size for the component to scale against.
        expect($unusable)->not->toBeNull()
            ->and($out)->toContain('<path d="M0 0"');
    });

    it('does not report an icon that is already well formed', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0"/></svg>';

        $reported = false;
        $this->svg->normaliseSizing($svg, function () use (&$reported): void {
            $reported = true;
        });

        expect($reported)->toBeFalse();
    });

    it('keeps a viewBox that is already the only sizing information', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0"/></svg>';

        expect($this->svg->normaliseSizing($svg))->toContain('viewBox="0 0 24 24"');
    });

    it('leaves unparseable content untouched rather than destroying it', function () {
        expect($this->svg->normaliseSizing('not svg at all'))->toBe('not svg at all');
    });
});
