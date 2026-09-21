<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Ichava\Tests\Fixtures\ViewfulPack\Providers\IconsServiceProvider as PackTwo;
use Simtabi\Laranail\Ichava\Tests\Fixtures\TranslatedPack\Providers\IconsServiceProvider as PackOne;

/*
|--------------------------------------------------------------------------
| `php artisan about` reports the ecosystem and every installed pack
|--------------------------------------------------------------------------
|
| Asserted on the command's CAPTURED OUTPUT, never on the registration call.
| package-tools stores sections on the Package and hands them to
| AboutCommand::add() during boot; reading the array back would prove the
| registration was written, not that the command prints anything.
|
| The rule these sections follow is HasAbout's own: config.json is canonical
| for a pack's facts, so a section reports them rather than restating them.
|
| What the licence assertion does and does not do is worth stating, because
| it is easy to overclaim. It cannot catch a MIT-versus-Commercial
| disagreement, because there is only one reader -- change config.json and
| the section changes with it. What it catches is the section reporting a
| value that did NOT come from config.json: a hardcoded default, or a licence
| rendered into the wrong pack's section. Mutation-checked that way.
|
*/

function aboutOutput(): string
{
    Artisan::call('about');

    return Artisan::output();
}

/**
 * The body of one `about` section, from its heading to the blank line.
 *
 * Replaces a fixed 400-character window, which is only correct while the
 * sections happen to be short -- a longer section would spill into the next
 * one and quietly widen every assertion made through it.
 */
function sectionFor(string $output, string $heading): string
{
    $after = preg_split('/' . preg_quote($heading, '/') . '/', $output, 2);

    expect($after)->toHaveCount(2, "no `{$heading}` section in `about` output");

    return preg_split('/\n\s*\n/', $after[1], 2)[0];
}

beforeEach(function () {
    $this->app->register(PackOne::class);
    $this->app->register(PackTwo::class);
});

it('prints an ichava ecosystem section with a real icon count', function () {
    $out = aboutOutput();

    expect($out)->toContain('Ichava');

    // A count, not a placeholder. `0` would pass a naive "contains a number"
    // check while meaning the registry never populated.
    expect($out)->toMatch('/Icons\D+[1-9]\d*/');
});

it('gives every booted pack exactly one section', function () {
    $out = aboutOutput();

    foreach (['ichava/fixture-pack', 'ichava/viewful-pack'] as $pack) {
        expect(substr_count($out, $pack))->toBe(1, "expected exactly one section for {$pack}");
    }
});

it('reports a licence that matches the pack config.json', function (string $fixture, string $pack) {
    // The two fixtures carry DIFFERENT licences on purpose -- fixture-pack is
    // MIT, viewful-pack is Commercial. While both said MIT this assertion had
    // no teeth: a section rendering the *other* pack's licence, or a hardcoded
    // default, printed "MIT" and passed. That is the exact shape of the bug
    // this ecosystem actually shipped, where a pack's translations claimed MIT
    // and its config.json said Commercial.
    $out = aboutOutput();

    $config = json_decode(
        (string) file_get_contents(dirname(__DIR__) . "/fixtures/{$fixture}/resources/assets/svg/config.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(sectionFor($out, $pack))->toContain($config['package']['license']);
})->with([
    ['TranslatedPack', 'ichava/fixture-pack'],
    ['ViewfulPack', 'ichava/viewful-pack'],
]);

it('does not leak one pack\'s licence into another pack\'s section', function () {
    // The negative half. Without it, a section printing *both* licences would
    // satisfy the test above for both packs.
    $out = aboutOutput();

    expect(sectionFor($out, 'ichava/fixture-pack'))->not->toContain('Commercial');
    expect(sectionFor($out, 'ichava/viewful-pack'))->not->toContain('MIT');
});

it('restates nothing composer.json already carries', function () {
    $out = aboutOutput();

    // HasAbout is explicit: description, authors and homepage come from the
    // manifest, and a copy here is a second source free to drift. config.json
    // facts (licence, version, counts) are a different matter -- that file is
    // canonical for an icon pack and nothing else surfaces it.
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($out)->not->toContain((string) ($composer['description'] ?? '@@none@@'));
});
