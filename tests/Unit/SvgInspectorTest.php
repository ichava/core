<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\SvgInspector;

/*
 * P-04. The two checks every pack carried its own copy of, written once. The
 * negative and positive vectors are the ones the pack copies used.
 */

it('reports what the SVG policy strips, and passes a clean icon', function (): void {
    $bad = '<!-- x --><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><use href="https://example.test/a.svg#x"/><path d="M0 0" data-slot="icon"/></svg>';
    $good = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><title>Ok</title><use href="#a"/><path id="a" d="M0 0" fill="currentColor"/></svg>';

    expect(SvgInspector::policyViolations($bad))->toContain('comment', 'attribute svg@onload', 'tag script', 'reference href', 'attribute path@data-slot')
        ->and(SvgInspector::policyViolations($good))->toBe([])
        ->and(SvgInspector::policyViolations('<svg'))->toBe(['unparseable'])
        ->and(SvgInspector::policyViolations('<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"/>'))->toContain('doctype');
});

it('reports a stray text node and ignores title and desc text', function (): void {
    expect(SvgInspector::strayTextNodes("<svg xmlns=\"http://www.w3.org/2000/svg\">\n<g>\n d\n<path d=\"M0 0\"/></g></svg>"))->toBe(['d'])
        ->and(SvgInspector::strayTextNodes('<svg xmlns="http://www.w3.org/2000/svg"><title>Train</title><desc>fast</desc><g><path d="M0 0"/></g></svg>'))->toBe([])
        ->and(SvgInspector::strayTextNodes('<svg'))->toBe(['unparseable']);
});
