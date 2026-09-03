<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * W1-10 / §1.8. The control that was missing.
 *
 * Three rendering defects accumulated in this subsystem without detection, and
 * five metronic brand logos rendered blank for months, because nothing rendered
 * an icon in CI. Both suites stayed green throughout -- they tested the
 * sanitiser against fixtures written to match the sanitiser.
 *
 * These run REAL corpus icons through the REAL pipeline, sampled one per
 * ARCHETYPE rather than one per pack. A pack is not an archetype: 6,184 tabler
 * icons are one monochrome shape, while five metronic logos are five different
 * problems. The archetype is what a policy gets wrong.
 *
 * This is construct-level rather than pixel-level, and that is a deliberate
 * limit worth stating. It cannot see that a gradient renders the wrong colour;
 * it can see that the gradient lost its stops, which is the failure mode that
 * actually occurred. Pixel comparison for the SPA lives in
 * `react-browser/e2e/svg-baseline.spec.ts`.
 *
 * Fixtures and their provenance: `tests/fixtures/archetypes/manifest.json`.
 */
function archetype(string $name): string
{
    return file_get_contents(__DIR__.'/../fixtures/archetypes/'.$name.'.svg');
}

function renderArchetype(string $name): string
{
    $svc = app(SvgProcessingService::class);
    $raw = archetype($name);

    // The order Icon::svg_content uses: namespace ids, normalise sizing, then
    // sanitise. Asserting against the sanitiser alone would miss a regression
    // in either of the first two passes.
    $raw = $svc->namespaceIds($raw, "tests/fixtures/archetypes/{$name}.svg");
    $raw = $svc->normaliseSizing($raw, static fn (string $reason) => null);

    return $svc->process($raw, [], false);
}

describe('archetype rendering regression', function () {
    it('keeps the stops a gradient needs to paint at all', function () {
        $out = renderArchetype('gradient');

        expect($out)->toContain('<stop')
            ->toContain('stop-color')
            ->and($out)->toMatch('/<(linear|radial)Gradient/');
    });

    it('keeps the fragment references a <use> sprite resolves through', function () {
        $out = renderArchetype('use-fragment');

        expect($out)->toContain('<use')
            // The reference must survive AND still point at something in-document,
            // which is the pair the id-namespacing pass has to keep consistent.
            ->and($out)->toMatch('/(xlink:)?href="#[^"]+"/');

        preg_match('/(?:xlink:)?href="#([^"]+)"/', $out, $ref);
        expect($out)->toContain('id="'.$ref[1].'"');
    });

    it('keeps the inline style that is the only paint 261 metronic icons have', function () {
        $out = renderArchetype('inline-style');

        expect($out)->toContain('style=')
            ->and($out)->toMatch('/style="[^"]*fill/');
    });

    it('keeps the accessible name and the attribute that points at it', function () {
        $out = renderArchetype('title');
        $raw = archetype('title');

        expect($out)->toContain('<title');

        // If the source wired an accessible name, the wiring must still resolve
        // after ids are rewritten -- that is the line most often forgotten.
        if (str_contains($raw, 'aria-labelledby')) {
            preg_match('/aria-labelledby="([^"]+)"/', $out, $m);
            expect($m[1] ?? '')->not->toBe('')
                ->and($out)->toContain('id="'.$m[1].'"');
        }
    });

    it('leaves the control archetype structurally intact', function () {
        $out = renderArchetype('control');

        // tabler is what the policy was designed against. If this one changes,
        // the change is in the pipeline, not in the policy's coverage -- and
        // without it a reviewer cannot tell "the fix worked" from
        // "everything changed".
        expect($out)->toContain('<path')
            ->toContain('currentColor')
            ->toContain('viewBox')
            // Sizing normalisation strips width/height when a viewBox is present.
            ->and($out)->not->toMatch('/<svg[^>]*\swidth=/');
    });

    it('renders every archetype to something, never to nothing', function () {
        // The failure this whole file exists to catch is silent: a stripped
        // construct yields a blank icon, not an error.
        foreach (['gradient', 'use-fragment', 'inline-style', 'title', 'control'] as $name) {
            expect(trim(renderArchetype($name)))->not->toBe('', "archetype {$name} rendered empty");
        }
    });
});
