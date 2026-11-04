<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/*
 * Node types the sanitiser used to pass through untouched (S5, S10).
 *
 * A processing instruction inside the root has no rendering purpose and is
 * stripped. A CDATA section is turned into ordinary text, so its characters
 * survive and are escaped on output: parsers disagree about CDATA inside
 * `<title>` (parse5 builds an element from it, Chromium does not), and
 * escaped text means the same thing to all of them.
 */
it('strips a processing instruction inside the root', function (): void {
    $out = app(SvgProcessingService::class)->sanitize(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><?xml-stylesheet href="https://evil/x.css"?><path d="M0 0h24"/></svg>',
    );

    expect($out)->not->toContain('<?')
        ->and($out)->not->toContain('evil')
        ->and($out)->toContain('<path');
});

it('turns a CDATA section into escaped text', function (): void {
    $out = app(SvgProcessingService::class)->sanitize(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><title><![CDATA[</title><img src=x onerror=alert(1)>]]></title><path d="M0 0h24"/></svg>',
    );

    expect($out)->not->toContain('CDATA')
        ->and($out)->not->toContain('<img')
        ->and($out)->toContain('&lt;/title&gt;&lt;img')
        ->and($out)->toContain('<title>');
});
