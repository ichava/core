<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

describe('Policy document flags', function () {
    beforeEach(function () {
        $this->service = app(SvgProcessingService::class);
    });

    it('strips comments independent of the optimizer', function () {
        $result = $this->service->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><!-- secret --><path d="M0 0h24v24H0z"/></svg>',
        );

        expect($result)->not->toContain('<!--')
            ->toContain('<path');
    });

    it('strips the doctype while keeping the content', function () {
        $result = $this->service->sanitize(
            '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>',
        );

        expect($result)->not->toContain('DOCTYPE')
            ->toContain('<path');
    });

    it('does not leave internal entity references behind', function () {
        $result = $this->service->sanitize(
            '<!DOCTYPE svg [<!ENTITY x "AAAA">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><text>&x;</text></svg>',
        );

        expect($result)->not->toContain('&x;');
    });

    it('blocks blob urls in paint values', function () {
        $result = $this->service->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="blob:https://evil.test/x"/></svg>',
        );

        expect($result)->not->toContain('blob:');
    });
});
