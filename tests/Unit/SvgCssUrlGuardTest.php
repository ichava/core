<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * S1 (2026-11-04 security audit) -- the `url()` guard matched the literal
 * characters `url(`, so anything that spells a resource fetch another way
 * survived: a CSS escape (`\75rl(`), a comment inside the token (`u/**\/rl(`),
 * or a fetching function with no `url(` in it at all (`image-set(`, `src(`).
 * Rendering such an icon inline makes the viewer's browser request the
 * attacker's URL.
 *
 * The guard now tokenises the value the way a browser does -- comments
 * removed, escapes decoded, names lowercased -- and allow-lists what it finds:
 * `url()` only for a same-document fragment, a short list of inert functions,
 * and no at-rules.
 */
describe('SVG CSS url() guard', function () {
    beforeEach(function () {
        $this->sanitizer = app(SvgProcessingService::class);

        $this->styled = fn (string $value): string => '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<path style="' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES) . '" d="M0 0"/></svg>';

        $this->painted = fn (string $attribute, string $value): string => '<svg xmlns="http://www.w3.org/2000/svg">'
            . '<path ' . $attribute . '="' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES) . '" d="M0 0"/></svg>';
    });

    $bypasses = [
        'hex escape'                => 'background:\75rl(https://evil.test/p.png)',
        'hex escape with space'     => 'background:\75 rl(https://evil.test/p.png)',
        'six-digit hex escape'      => 'background:\000075rl(https://evil.test/p.png)',
        'uppercase hex escape'      => 'background:\55RL(https://evil.test/p.png)',
        'identity escape'           => 'background:u\rl(https://evil.test/p.png)',
        'comment inside the token'  => 'background:u/**/rl(https://evil.test/p.png)',
        'uppercase url'             => 'background:URL(https://evil.test/p.png)',
        'image-set'                 => 'background-image:image-set("https://evil.test/p.png" 1x)',
        '-webkit-image-set'         => 'background-image:-webkit-image-set("https://evil.test/p.png" 1x)',
        'image'                     => 'background-image:image("https://evil.test/p.png")',
        'src'                       => 'background-image:src("https://evil.test/p.png")',
        'cross-fade'                => 'background-image:cross-fade(url(#a), "https://evil.test/p.png")',
        'element'                   => 'background:element(#a)',
        'import'                    => '@import "https://evil.test/x.css";fill:red',
        'escaped import'            => '@\69mport "https://evil.test/x.css";fill:red',
        'expression'                => 'width:expression(alert(1))',
        'escaped expression'        => 'width:\65xpression(alert(1))',
        'double quote mismatch'     => "background:url(\"https://evil.test/x'y\")",
        'single quote mismatch'     => "background:url('https://evil.test/x\")')",
        'paren inside quotes'       => 'background:url("https://evil.test/a)b")',
        'fragment then fetch'       => 'fill:url(#g);background:url(https://evil.test/p.png)',
        'escaped fetch in fallback' => 'fill:url(#g) \75rl(https://evil.test/p.png)',
        'cursor on the root'        => 'cursor:\75rl(https://evil.test/x),auto',
        'escaped behavior'          => 'b\65havior:url(#x)',
        'escaped moz binding'       => '-moz-b\69nding:url(#x)',
    ];

    foreach ($bypasses as $label => $value) {
        it("strips a style carrying {$label}", function () use ($value) {
            expect($this->sanitizer->sanitize(($this->styled)($value)))->not->toContain('style=');
        });
    }

    $paintBypasses = [
        'fill'         => '\75rl(https://evil.test/p.png)',
        'stroke'       => 'u/**/rl(https://evil.test/p.png)',
        'filter'       => 'image-set("https://evil.test/p.png" 1x)',
        'clip-path'    => 'src("https://evil.test/p.png")',
        'mask'         => '\000075rl(https://evil.test/p.png)',
        'marker-start' => 'url(https://evil.test/p.png)',
    ];

    foreach ($paintBypasses as $attribute => $value) {
        it("strips a {$attribute} attribute carrying an escaped or indirect fetch", function () use ($attribute, $value) {
            // marker-start is not on the shipped allow-list; widen it so the
            // value check, not the name check, is what is under test.
            $this->sanitizer->setAllowedAttributes([...$this->sanitizer->getAllowedAttributes(), 'marker-start']);

            expect($this->sanitizer->sanitize(($this->painted)($attribute, $value)))->not->toContain($attribute . '=');
        });
    }

    it('keeps a fragment url() in a style, which is how a gradient is applied', function () {
        expect($this->sanitizer->sanitize(($this->styled)('fill:url(#g)')))->toContain('style="fill:url(#g)"');
    });

    it('keeps a quoted fragment url() in a paint attribute', function () {
        expect($this->sanitizer->sanitize(($this->painted)('fill', "url('#g')")))->toContain('fill="url(\'#g\')"');
    });

    it('keeps a double-quoted fragment url() with a fallback colour', function () {
        expect($this->sanitizer->sanitize(($this->styled)('fill:url("#g") red;stroke:url(#h)')))->toContain('url(&quot;#g&quot;) red');
    });

    $inert = [
        'colour functions'  => 'fill:rgb(0 0 0 / 50%);stroke:hsla(0,0%,0%,.5);stop-color:oklch(0.5 0.1 120)',
        'color-mix'         => 'fill:color-mix(in srgb, red 50%, blue)',
        'custom property'   => '--brand:#f00;fill:var(--brand, currentColor)',
        'calc'              => 'stroke-width:calc(1px + max(1px, min(2px, clamp(1px, 2px, 3px))))',
        'transform'         => 'transform:translate(1px,2px) rotate(45deg) scale(2) skewX(3deg) matrix(1,0,0,1,0,0)',
        'easing'            => 'transition-timing-function:cubic-bezier(.4,0,.2,1), steps(4, end)',
        'enable-background' => 'enable-background:new 0 0 24 24;isolation:isolate',
        'font family'       => "font-family:'Open Sans', sans-serif",
    ];

    foreach ($inert as $label => $value) {
        it("keeps a style carrying {$label}", function () use ($value) {
            expect($this->sanitizer->sanitize(($this->styled)($value)))->toContain('style=');
        });
    }

    it('keeps a transform attribute built from transform functions', function () {
        expect($this->sanitizer->sanitize(($this->painted)('transform', 'translate(2 3) rotate(45 12 12)')))->toContain('transform=');
    });

    it('removes a <style> element whose text fetches through an escape', function () {
        $this->sanitizer->setForbiddenTags(['script']);
        $this->sanitizer->setAllowedTags([...$this->sanitizer->getAllowedTags(), 'style']);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a{fill:\75rl(https://evil.test/p.png)}</style><path class="a" d="M0 0"/></svg>';

        $out = $this->sanitizer->sanitize($svg);

        expect($out)->not->toContain('<style')->and($out)->not->toContain('evil.test')->and($out)->toContain('<path');
    });

    it('removes a <style> element carrying an at-rule', function () {
        $this->sanitizer->setForbiddenTags(['script']);
        $this->sanitizer->setAllowedTags([...$this->sanitizer->getAllowedTags(), 'style']);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>@import "https://evil.test/x.css";</style><path d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->not->toContain('<style');
    });

    it('keeps a <style> element that only paints with fragments', function () {
        $this->sanitizer->setForbiddenTags(['script']);
        $this->sanitizer->setAllowedTags([...$this->sanitizer->getAllowedTags(), 'style']);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>.a{fill:url(#g)}.b{stroke:#000}</style><path class="a" d="M0 0"/></svg>';

        expect($this->sanitizer->sanitize($svg))->toContain('.a{fill:url(#g)}');
    });
});
