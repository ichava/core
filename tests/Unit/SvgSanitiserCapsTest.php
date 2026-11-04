<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * S2 (2026-11-04 security audit) -- the sanitiser had no size or node ceiling,
 * so a 5 MB illustration of about 374k trivial nodes took 9.5 s to sanitise,
 * synchronously, inside the request that first served it.
 *
 * Two caps now fail closed with an IchavaException: a byte cap checked before
 * the document is parsed, and a node cap checked while it is walked.
 */
describe('SVG sanitiser caps', function () {
    beforeEach(function () {
        $this->sanitizer = app(SvgProcessingService::class);

        // Root plus $paths children, no whitespace: exactly 1 + $paths nodes.
        $this->svgWithNodes = fn (int $nodes): string => '<svg xmlns="http://www.w3.org/2000/svg">'
            . str_repeat('<path d="M0 0"/>', $nodes - 1) . '</svg>';
    });

    it('ships both caps in config', function () {
        expect(config('ichava.ichava-core.svg.max_bytes'))->toBeInt()->toBeGreaterThan(0)
            ->and(config('ichava.ichava-core.svg.max_nodes'))->toBeInt()->toBeGreaterThan(0);
    });

    it('accepts a document exactly at the node cap', function () {
        config()->set('ichava.ichava-core.svg.max_nodes', 50);

        expect(substr_count($this->sanitizer->sanitize(($this->svgWithNodes)(50)), '<path'))->toBe(49);
    });

    it('rejects a document one node over the cap', function () {
        config()->set('ichava.ichava-core.svg.max_nodes', 50);

        $this->sanitizer->sanitize(($this->svgWithNodes)(51));
    })->throws(IchavaException::class, 'node');

    it('accepts a document exactly at the byte cap', function () {
        $svg = ($this->svgWithNodes)(10);
        config()->set('ichava.ichava-core.svg.max_bytes', strlen($svg));

        expect($this->sanitizer->sanitize($svg))->toContain('<path');
    });

    it('rejects a document one byte over the cap, before parsing it', function () {
        $svg = ($this->svgWithNodes)(10);
        config()->set('ichava.ichava-core.svg.max_bytes', strlen($svg) - 1);

        $this->sanitizer->sanitize($svg);
    })->throws(IchavaException::class, 'bytes');

    it('rejects an oversized document even when it is not well-formed', function () {
        // Proves the byte check runs before the parser: this input would
        // otherwise be refused for its structure, with a different message.
        config()->set('ichava.ichava-core.svg.max_bytes', 16);

        $this->sanitizer->sanitize(str_repeat('x', 17));
    })->throws(IchavaException::class, 'bytes');
});
