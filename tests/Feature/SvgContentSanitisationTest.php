<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;

/**
 * S1 — `svg_content` must not hand back whatever is on disk.
 *
 * The accessor was a bare `File::get()`. The SVG endpoint's own comment asserted the
 * content "has been sanitised by SvgProcessingService" and added nosniff plus a
 * restrictive CSP as defence in depth; nothing had sanitised it, so those headers were the
 * only defence, and they apply to one route.
 *
 * The path that matters more is JSON. `IconBrowserService` puts `svg_content` straight
 * into an API payload, where no response header helps and the client injects the string
 * into the DOM. This is not hypothetical: a scan of the shipped packs found files
 * containing `foreignObject`, `script` and `image` elements.
 *
 * These tests write hostile SVGs to disk and read them back through the model, because
 * that is the only way to prove the sanitiser is actually on the path rather than merely
 * present in the codebase.
 */
beforeEach(function () {
    Icon::query()->delete();

    $this->dir = sys_get_temp_dir().'/ichava-svg-sanitise-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);

    $this->plant = function (string $filename, string $svg): Icon {
        $abs = $this->dir.'/'.$filename;
        File::put($abs, $svg);

        $icon = Icon::create([
            'package' => 'ichava/test-pack',
            'name' => pathinfo($filename, PATHINFO_FILENAME),
            'path' => $abs,
            'file_hash' => md5($svg),
            'keywords' => [],
            'tags' => [],
            'attributes' => [],
            'metadata' => [],
        ]);

        // The accessor resolves `absolute_path`; point it at the file just written.
        $icon->forceFill(['path' => $abs])->save();

        return $icon->fresh();
    };
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('strips an inline script from served SVG content', function () {
    $icon = ($this->plant)('evil.svg', <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
  <script>alert(document.cookie)</script>
  <path d="M4 4h16v16H4z"/>
</svg>
SVG);

    $content = $icon->svg_content;

    // The <path> must survive: a sanitiser that strips everything would pass a
    // "no script" assertion while destroying every icon in the catalogue.
    expect($content)->not->toContain('<script');
    expect($content)->not->toContain('alert(');
    expect($content)->toContain('<path');
})->skip(fn () => ! class_exists(DOMDocument::class), 'ext-dom required');

it('strips an event handler attribute', function () {
    $icon = ($this->plant)('onload.svg', <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="fetch('//evil.test')">
  <circle cx="12" cy="12" r="8" onclick="steal()"/>
</svg>
SVG);

    $content = $icon->svg_content;

    expect(strtolower($content))->not->toContain('onload');
    expect(strtolower($content))->not->toContain('onclick');
    expect($content)->toContain('<circle');
})->skip(fn () => ! class_exists(DOMDocument::class), 'ext-dom required');

it('sanitises the JSON payload path, not just the direct SVG route', function () {
    ($this->plant)('payload.svg', <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
  <script>alert(1)</script><path d="M0 0h24v24H0z"/>
</svg>
SVG);

    // The route response carries nosniff and a CSP. A JSON payload carries neither, and
    // the client injects this string into the DOM -- so this is the path that had to be
    // fixed at the model rather than at the controller.
    $payload = app(IconBrowserService::class)->getIcons([]);
    $first = $payload->items()[0] ?? null;

    expect($first)->not->toBeNull();
    expect($first['svg_content'] ?? '')->not->toContain('<script');
})->skip(fn () => ! class_exists(DOMDocument::class), 'ext-dom required');

it('serves nothing rather than raw markup when the sanitiser rejects a file', function () {
    // Not an SVG at all. Failing closed matters: falling back to the raw bytes would
    // reintroduce exactly the hole this closes, on the files most likely to be hostile.
    $icon = ($this->plant)('notsvg.svg', '<html><body><script>alert(1)</script></body></html>');

    expect($icon->svg_content)->not->toContain('<script');
})->skip(fn () => ! class_exists(DOMDocument::class), 'ext-dom required');
