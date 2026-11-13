<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Enums\OptimizationLevel;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * W1-7b / B0-b. The SVG endpoint may only advertise `immutable` on a URL that
 * identifies its own content, and the served bytes are the *processed* SVG --
 * ids namespaced, sizing normalised, allow-list applied. A file hash alone
 * therefore does not identify a response: widen the policy and every icon
 * changes while every file hash stays put.
 *
 * These pin the half that config controls.
 */
describe('SvgProcessingService::renderFingerprint', function () {
    it('is stable across calls for an unchanged policy', function () {
        $svc = new SvgProcessingService;

        expect($svc->renderFingerprint())->toBe($svc->renderFingerprint());
    });

    it('is identical for two services configured the same way', function () {
        expect((new SvgProcessingService)->renderFingerprint())
            ->toBe((new SvgProcessingService)->renderFingerprint());
    });

    it('changes when the allowed tags change', function () {
        $svc = new SvgProcessingService;
        $before = $svc->renderFingerprint();

        $svc->setAllowedTags([...$svc->getAllowedTags(), 'pattern']);

        expect($svc->renderFingerprint())->not->toBe($before);
    });

    it('changes when the allowed attributes change', function () {
        $svc = new SvgProcessingService;
        $before = $svc->renderFingerprint();

        $svc->setAllowedAttributes([...$svc->getAllowedAttributes(), 'stroke-dasharray']);

        expect($svc->renderFingerprint())->not->toBe($before);
    });

    it('changes when the forbidden tags change', function () {
        $svc = new SvgProcessingService;
        $before = $svc->renderFingerprint();

        $svc->setForbiddenTags([...$svc->getForbiddenTags(), 'animate']);

        expect($svc->renderFingerprint())->not->toBe($before);
    });

    it('changes when the optimization level changes', function () {
        $svc = new SvgProcessingService;
        $before = $svc->renderFingerprint();

        $svc->setOptimizationLevel(OptimizationLevel::AGGRESSIVE);

        expect($svc->renderFingerprint())->not->toBe($before);
    });

    /*
     * The service is a container singleton, so a memoised fingerprint would
     * survive a setter call and hand out a token for a policy no longer in
     * effect -- the exact failure the token exists to prevent. This asserts the
     * absence of that memo.
     */
    it('does not memoise across a policy change on the same instance', function () {
        $svc = new SvgProcessingService;
        $first = $svc->renderFingerprint();

        $svc->setAllowedTags(['svg', 'path']);
        $second = $svc->renderFingerprint();

        $svc->setAllowedTags(['svg', 'path']);

        expect($second)->not->toBe($first)
            ->and($svc->renderFingerprint())->toBe($second);
    });

    it('is short enough for a URL and uses only hex characters', function () {
        expect((new SvgProcessingService)->renderFingerprint())->toMatch('/^[0-9a-f]{12}$/');
    });
});
