<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/*
 * Parity with ichava/icon-sets-sync#85.
 *
 * A dry run of OpenMoji 17 stripped display="none" from 13 subdivision flags,
 * so the hidden construction shapes in them would render, and stripped color
 * and marker-end from a few files. The policy now allows the five names, and
 * each has a value guard: display only as bare keywords, color through the CSS
 * guard, marker-* only as none or url(#fragment). The fixture is a reduced copy
 * of the shape those flags take, with the Inkscape sodipodi:docname that stays
 * stripped.
 *
 * tests/fixtures/svg/presentation-vectors.json is byte-identical in core,
 * react-icon-browser and js-icon-components, and copies its rows from the Go
 * table in icon-sets-sync, so all four sanitisers make the same decisions.
 */
function presentationSanitise(string $svg): string
{
    return app(SvgProcessingService::class)->sanitize($svg);
}

it('keeps the hidden shapes of an OpenMoji subdivision flag', function (): void {
    $out = presentationSanitise((string) file_get_contents(__DIR__ . '/../fixtures/svg/openmoji-subdivision-flag.svg'));

    expect($out)->toContain('display="none"')
        ->toContain('color="#000"')
        ->toContain('marker-end="url(#arrow)"')
        ->not->toContain('docname');
});

it('keeps color and a same-document marker-end', function (): void {
    $out = presentationSanitise('<svg xmlns="http://www.w3.org/2000/svg"><path color="red" marker-end="url(#m)" d="M0 0"/></svg>');

    expect($out)->toContain('color="red"')->toContain('marker-end="url(#m)"');
});

it('strips hostile display, marker and color values without touching the path', function (string $doc): void {
    $out = presentationSanitise($doc);

    expect($out)->not->toContain('url(')
        ->not->toContain('expression(')
        ->toContain('d="M0 0"');
})->with([
    'display url'           => '<svg xmlns="http://www.w3.org/2000/svg"><path display="url(x)" d="M0 0"/></svg>',
    'style display url'     => '<svg xmlns="http://www.w3.org/2000/svg"><path style="display:url(x)" d="M0 0"/></svg>',
    'remote marker-end'     => '<svg xmlns="http://www.w3.org/2000/svg"><path marker-end="url(https://e/x)" d="M0 0"/></svg>',
    'remote color'          => '<svg xmlns="http://www.w3.org/2000/svg"><path color="url(https://e)" d="M0 0"/></svg>',
    'color expression'      => '<svg xmlns="http://www.w3.org/2000/svg"><path style="color: expression(alert(1))" d="M0 0"/></svg>',
    'color attr expression' => '<svg xmlns="http://www.w3.org/2000/svg"><path color="expression(alert(1))" d="M0 0"/></svg>',
]);

it('makes the shared vector decisions', function (): void {
    /** @var array{cases: list<array{name: string, value: string, keep: bool}>} $table */
    $table = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/svg/presentation-vectors.json'), true, flags: JSON_THROW_ON_ERROR);

    // The Go table has 27 rows; a table that stopped loading must not pass.
    expect($table['cases'])->toHaveCount(27);

    foreach ($table['cases'] as $case) {
        $value = htmlspecialchars($case['value'], ENT_QUOTES | ENT_XML1);
        $out = presentationSanitise(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd">'
            . '<path ' . $case['name'] . '="' . $value . '" d="M0 0"/></svg>',
        );

        $dom = new DOMDocument;
        $dom->loadXML($out);
        $path = $dom->getElementsByTagName('path')->item(0);

        expect($path)->not->toBeNull();
        expect($path instanceof DOMElement && $path->hasAttribute($case['name']))
            ->toBe($case['keep'], "{$case['name']}=\"{$case['value']}\"");
    }
});

it('drops stray text under g and keeps the indentation', function (): void {
    $out = presentationSanitise((string) file_get_contents(__DIR__ . '/../fixtures/svg/openmoji-1F684-stray-text.svg'));

    expect($out)->toBe(
        "<svg xmlns=\"http://www.w3.org/2000/svg\" id=\"emoji\" viewBox=\"0 0 72 72\">\n  <g id=\"color\">\n    "
        . "<path fill=\"#fff\" d=\"m68 48-61.78-0.5026-1.131-3.958 31.76-21.02 31.15-0.5784z\"/>\n  </g>\n</svg>",
    );
});

it('applies the stray text rules', function (string $doc, array $survive, array $die): void {
    $out = presentationSanitise($doc);

    foreach ($survive as $s) {
        expect($out)->toContain($s);
    }

    foreach ($die as $s) {
        expect($out)->not->toContain($s);
    }
})->with([
    'text directly under svg' => ['<svg xmlns="http://www.w3.org/2000/svg">oops<path d="M0 0"/></svg>', ['<path d="M0 0"/>'], ['oops']],
    'CDATA directly under g'  => ['<svg xmlns="http://www.w3.org/2000/svg"><g><![CDATA[junk]]><path d="M0 0"/></g></svg>', ['<path d="M0 0"/>'], ['junk']],
    'two stray runs'          => ['<svg xmlns="http://www.w3.org/2000/svg">a<g>b</g></svg>', [], ['>a<', '>b<']],
    'whitespace is not stray' => ["<svg xmlns=\"http://www.w3.org/2000/svg\">\n  <g>\n    <path d=\"M0 0\"/>\n  </g>\n</svg>", ["\n  <g>\n    <path"], []],
    'text elements keep text' => [
        '<svg xmlns="http://www.w3.org/2000/svg"><title>Train</title><desc>A train</desc><g><text>Hi<tspan>there</tspan></text></g></svg>',
        ['<title>Train</title>', '<desc>A train</desc>', 'Hi', 'there'],
        [],
    ],
]);
