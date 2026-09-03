<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * CR1 -- ids collide across the corpus at scale. `_Transparent_Rectangle_` is
 * shared by 2,157 files, `Layer_1` by 261, and flag-icons carries 576 colliding
 * ids. Render two of them on one page and every `url(#Layer_1)` in the second
 * resolves to the first icon's definition: wrong gradient, wrong clip, wrong
 * mask, silently. The browser renders 60+ at once.
 *
 * Collisions only render wrong when the same id points at different content, so
 * per-file uniqueness is enough: a deterministic, path-derived prefix, applied
 * once per file and cacheable.
 */
describe('SVG id namespacing', function () {
    beforeEach(function () {
        $this->svg = app(SvgProcessingService::class);
    });

    it('prefixes ids deterministically from the path', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="Layer_1"/></defs></svg>';

        $a = $this->svg->namespaceIds($svg, 'tabler-icons/outline/home.svg');
        $b = $this->svg->namespaceIds($svg, 'tabler-icons/outline/home.svg');

        expect($a)->toBe($b)->not->toContain('id="Layer_1"');
    });

    it('gives two files different prefixes for the same id', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="Layer_1"/></defs></svg>';

        expect($this->svg->namespaceIds($svg, 'a/one.svg'))
            ->not->toBe($this->svg->namespaceIds($svg, 'a/two.svg'));
    });

    it('starts every prefix with a letter, so the fragment rule still accepts it', function () {
        // sha1 is hex: a bare digest can start with a digit, and the sanitiser's
        // fragment pattern requires ^#[A-Za-z_]. A digit-led prefix would make
        // the sanitiser strip the very references this pass rewrites.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><path id="a"/></defs><use href="#a"/></svg>';

        foreach (['x/1.svg', 'x/2.svg', 'x/3.svg', 'x/4.svg', 'x/5.svg'] as $seed) {
            preg_match('/id="([^"]+)"/', $this->svg->namespaceIds($svg, $seed), $m);
            expect($m[1])->toMatch('/^[A-Za-z_]/');
        }
    });

    it('rewrites a fragment href to match its renamed target', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><path id="a" d="M0 0"/></defs><use href="#a"/></svg>';

        $out = $this->svg->namespaceIds($svg, 'p/f.svg');
        preg_match('/id="([^"]+)"/', $out, $id);
        expect($out)->toContain('href="#' . $id[1] . '"');
    });

    it('rewrites xlink:href too, which is what the flag packs ship', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><path id="a"/></defs><use xlink:href="#a"/></svg>';

        $out = $this->svg->namespaceIds($svg, 'p/f.svg');
        preg_match('/id="([^"]+)"/', $out, $id);
        expect($out)->toContain('#' . $id[1]);
    });

    it('rewrites url() references in paint attributes', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"/><clipPath id="c"/></defs><rect fill="url(#g)" clip-path="url(#c)"/></svg>';

        $out = $this->svg->namespaceIds($svg, 'p/f.svg');
        preg_match('/id="([^"]+)"[^>]*\/><clipPath id="([^"]+)"/', $out, $ids);
        expect($out)
            ->toContain('url(#' . $ids[1] . ')')
            ->toContain('url(#' . $ids[2] . ')')
            ->not->toContain('url(#g)')
            ->not->toContain('url(#c)');
    });

    it('rewrites url() inside a style attribute', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"/></defs><path style="fill:url(#g)"/></svg>';

        expect($this->svg->namespaceIds($svg, 'p/f.svg'))->not->toContain('url(#g)');
    });

    it('rewrites aria-labelledby, the one most commonly missed', function () {
        // 283 occurrences in bundled-icons. Renaming ids without this silently
        // destroys the accessible name on every one of them.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" aria-labelledby="t d"><title id="t">Home</title><desc id="d">A house</desc></svg>';

        $out = $this->svg->namespaceIds($svg, 'p/f.svg');
        preg_match('/<title id="([^"]+)"/', $out, $t);
        preg_match('/<desc id="([^"]+)"/', $out, $d);
        expect($out)->toContain('aria-labelledby="' . $t[1] . ' ' . $d[1] . '"');
    });

    it('rewrites aria-describedby', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" aria-describedby="d"><desc id="d">x</desc></svg>';

        $out = $this->svg->namespaceIds($svg, 'p/f.svg');
        preg_match('/<desc id="([^"]+)"/', $out, $d);
        expect($out)->toContain('aria-describedby="' . $d[1] . '"');
    });

    it('leaves a reference to an id the document does not define alone', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><use href="#nowhere"/><rect fill="url(#absent)"/></svg>';

        expect($this->svg->namespaceIds($svg, 'p/f.svg'))
            ->toContain('href="#nowhere"')
            ->toContain('url(#absent)');
    });

    it('leaves an external reference alone', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><path id="a"/></defs><use href="https://cdn.test/s.svg#a"/></svg>';

        expect($this->svg->namespaceIds($svg, 'p/f.svg'))->toContain('https://cdn.test/s.svg#a');
    });

    it('survives the sanitiser: the rewritten reference is still there afterwards', function () {
        // The whole point. Namespacing runs before sanitisation, so a prefix the
        // sanitiser rejects would be worse than no prefix at all.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><path id="a" d="M0 0"/></defs><use href="#a"/></svg>';

        $out = $this->svg->sanitize($this->svg->namespaceIds($svg, 'p/f.svg'));
        preg_match('/id="([^"]+)"/', $out, $id);
        expect($out)->toContain('href="#' . $id[1] . '"');
    });

    it('is a no-op on a document with no ids', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>';

        expect($this->svg->namespaceIds($svg, 'p/f.svg'))->toContain('<path d="M0 0"');
    });
});
