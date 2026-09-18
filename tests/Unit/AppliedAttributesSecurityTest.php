<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

describe('Post-sanitizer attributes', function () {
    beforeEach(function () {
        $this->service = app(SvgProcessingService::class);
        $this->clean = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>';
    });

    it('drops event handler attributes applied after sanitization', function () {
        $result = $this->service->process($this->clean, ['onload' => 'alert(1)']);

        expect(strtolower($result))->not->toContain('onload')->not->toContain('alert');
    });

    it('drops mixed-case event handler attributes', function () {
        $result = $this->service->process($this->clean, ['onLoad' => 'alert(1)']);

        expect(strtolower($result))->not->toContain('onload')->not->toContain('alert');
    });

    it('drops dangerous values on applied attributes', function () {
        $href = $this->service->process($this->clean, ['href' => 'javascript:alert(1)']);
        $style = $this->service->process($this->clean, ['style' => 'background:url(javascript:alert(1))']);
        $form = $this->service->process($this->clean, ['formaction' => 'https://evil.test/x']);

        expect($href)->not->toContain('javascript:')
            ->and($style)->not->toContain('javascript:')
            ->and($form)->not->toContain('formaction');
    });

    it('drops off-document paint urls applied after sanitization', function () {
        foreach (['fill', 'stroke', 'clip-path', 'mask', 'filter'] as $name) {
            $result = $this->service->process($this->clean, [$name => 'url(https://attacker.test/x.svg#g)']);

            expect($result)->not->toContain('attacker.test');
        }
    });

    it('drops disguised off-document paint urls', function () {
        $upper = $this->service->process($this->clean, ['fill' => 'URL(HTTPS://ATTACKER.TEST/x)']);
        $spaced = $this->service->process($this->clean, ['fill' => 'url( https://attacker.test/x )']);
        $quoted = $this->service->process($this->clean, ['stroke' => "url('https://attacker.test/x')"]);

        expect(strtolower($upper))->not->toContain('attacker.test')
            ->and($spaced)->not->toContain('attacker.test')
            ->and($quoted)->not->toContain('attacker.test');
    });

    it('drops off-document paint urls in file content', function () {
        $result = $this->service->sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="url(https://evil.test/x)"/></svg>');

        expect($result)->not->toContain('evil.test');
    });

    it('keeps fragment paint urls and plain paint values', function () {
        $fragment = $this->service->process($this->clean, ['fill' => 'url(#g)']);
        $plain = $this->service->process($this->clean, ['fill' => 'red', 'stroke' => 'none']);
        $file = $this->service->sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs><path d="M0 0h24v24H0z" fill="url(#g)"/></svg>');

        expect($fragment)->toContain('url(#g)')
            ->and($plain)->toContain('fill="red"')->toContain('stroke="none"')
            ->and($file)->toContain('url(#g)');
    });

    it('drops event handlers and broken keys from built html', function () {
        $handlers = $this->service->buildHtml(['onload' => 'alert(1)']);
        $breakout = $this->service->buildHtml(['x" onmouseover="alert(1)' => 'y']);

        expect($handlers)->not->toContain('onload')
            ->and($breakout)->not->toContain('onmouseover');
    });

    it('keeps legitimate presentation, aria, data and style attributes', function () {
        $result = $this->service->process($this->clean, [
            'class'      => 'size-5',
            'id'         => 'icon-home',
            'width'      => '24',
            'height'     => '24',
            'role'       => 'img',
            'aria-label' => 'Home',
            'data-tip'   => 'hello',
            'title'      => 'Home icon',
            'style'      => 'color: red',
        ]);

        expect($result)->toContain('class="size-5"')
            ->toContain('id="icon-home"')
            ->toContain('width="24"')
            ->toContain('height="24"')
            ->toContain('role="img"')
            ->toContain('aria-label="Home"')
            ->toContain('data-tip="hello"')
            ->toContain('title="Home icon"')
            ->toContain('style="color: red"');
    });
});
