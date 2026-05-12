<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

describe('SVG Sanitizer', function () {
    beforeEach(function () {
        $this->sanitizer = app(SvgProcessingService::class);
    });

    it('allows valid SVG with basic shapes', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z"/>
        </svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->toContain('<svg')
            ->toContain('<path')
            ->toContain('viewBox');
    });

    it('removes script tags', function () {
        $svg = '<svg><script>alert("XSS")</script><circle r="5"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('<script')
            ->not->toContain('alert')
            ->toContain('<circle');
    });

    it('removes onclick event handlers', function () {
        $svg = '<svg><circle onclick="alert(1)" r="5"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('onclick')
            ->not->toContain('alert')
            ->toContain('<circle');
    });

    it('removes onload event handlers', function () {
        $svg = '<svg onload="alert(1)"><circle r="5"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('onload')
            ->not->toContain('alert');
    });

    it('removes all on* event handlers', function () {
        $svg = '<svg onmouseover="alert(1)" onerror="alert(2)"><path onanimationstart="alert(3)"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('onmouseover')
            ->not->toContain('onerror')
            ->not->toContain('onanimationstart')
            ->not->toContain('alert');
    });

    it('removes javascript protocol in href', function () {
        $svg = '<svg><a href="javascript:alert(1)"><text>Click</text></a></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('javascript:')
            ->not->toContain('alert');
    });

    it('removes vbscript protocol', function () {
        $svg = '<svg><a href="vbscript:msgbox(1)"><text>Click</text></a></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('vbscript:');
    });

    it('removes data:text/html protocol', function () {
        // Test that dangerous data: protocols are removed from attributes
        $svg = '<svg><circle fill="data:text/html,malicious" r="5"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('data:text/html');
    });

    it('removes foreignObject tags', function () {
        $svg = '<svg><foreignObject><body><script>alert(1)</script></body></foreignObject></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('foreignObject')
            ->not->toContain('<body')
            ->not->toContain('alert');
    });

    it('removes animate tags', function () {
        $svg = '<svg><animate onbegin="alert(1)"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('animate')
            ->not->toContain('onbegin');
    });

    it('removes iframe tags', function () {
        $svg = '<svg><iframe src="evil.com"></iframe></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('iframe');
    });

    it('removes embed tags', function () {
        $svg = '<svg><embed src="evil.swf"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('embed');
    });

    it('removes object tags', function () {
        $svg = '<svg><object data="evil.swf"></object></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('object');
    });

    it('removes xlink:href attributes', function () {
        $svg = '<svg><use xlink:href="#evil"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('xlink:href');
    });

    it('preserves allowed attributes', function () {
        $svg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
            <circle cx="12" cy="12" r="10" stroke="red" stroke-width="2"/>
        </svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->toContain('viewBox')
            ->toContain('width')
            ->toContain('height')
            ->toContain('fill')
            ->toContain('stroke')
            ->toContain('stroke-width');
    });

    it('allows standard SVG elements', function () {
        $svg = '<svg>
            <g><path/><circle/><rect/><line/><polyline/><polygon/></g>
            <defs><linearGradient/></defs>
            <ellipse/><text><tspan/></text>
        </svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->toContain('<g')
            ->toContain('<path')
            ->toContain('<circle')
            ->toContain('<defs')
            ->toContain('<text');
    });

    it('throws exception for empty SVG', function () {
        expect(fn () => $this->sanitizer->sanitize(''))
            ->toThrow(IchavaException::class, 'SVG content is empty');
    });

    it('throws exception for non-SVG XML', function () {
        expect(fn () => $this->sanitizer->sanitize('<div>Not SVG</div>'))
            ->toThrow(IchavaException::class, 'Root element must be <svg>');
    });

    it('throws exception for invalid XML', function () {
        expect(fn () => $this->sanitizer->sanitize('<svg><unclosed'))
            ->toThrow(IchavaException::class);
    });

    it('removes XML declaration', function () {
        $svg = '<?xml version="1.0" encoding="UTF-8"?><svg><circle r="5"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('<?xml')
            ->toContain('<svg');
    });

    it('handles nested malicious content', function () {
        $svg = '<svg>
            <g>
                <g>
                    <foreignObject>
                        <script>alert(1)</script>
                    </foreignObject>
                </g>
            </g>
        </svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('foreignObject')
            ->not->toContain('script')
            ->not->toContain('alert');
    });

    it('can customize allowed tags', function () {
        $sanitizer = app(SvgProcessingService::class);
        $sanitizer->setAllowedTags(['svg', 'circle']);
        $sanitizer->setAllowedAttributes(['r']);
        $sanitizer->setForbiddenTags([]);

        $svg = '<svg><circle r="5"/><rect width="10"/></svg>';

        $result = $sanitizer->sanitize($svg);

        expect($result)->toContain('<circle')
            ->not->toContain('<rect');
    });

    it('protects against expression() attacks', function () {
        $svg = '<svg><style>* { color: expression(alert(1)); }</style></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('expression(');
    });

    it('protects against @import attacks', function () {
        $svg = '<svg><style>@import url("evil.css");</style></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->not->toContain('@import');
    });

    it('allows xmlns attribute', function () {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->toContain('xmlns');
    });

    it('allows id and class attributes', function () {
        $svg = '<svg id="icon" class="icon-lg"><circle r="5"/></svg>';

        $result = $this->sanitizer->sanitize($svg);

        expect($result)->toContain('id="icon"')
            ->toContain('class="icon-lg"');
    });
});
