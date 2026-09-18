<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\IconRenderer;

describe('Renderer debug errors', function () {
    it('does not leak paths in debug render errors', function () {
        config(['app.debug' => true]);

        $html = app(IconRenderer::class)->icon('nope::missing/icon')->render();

        expect($html)->toMatch('/^<!-- Icon render error: [A-Za-z\\\\]+ -->$/');
    });
});
