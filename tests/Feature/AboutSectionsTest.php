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

it('reports a licence that matches the pack config.json', function () {
    $out = aboutOutput();

    $config = json_decode(
        (string) file_get_contents(dirname(__DIR__) . '/fixtures/ViewfulPack/resources/assets/svg/config.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // Not merely "MIT appears somewhere": the licence must sit inside this
    // pack's own section.
    $section = (string) preg_split('/ichava\/viewful-pack/', $out)[1];
    expect(substr($section, 0, 400))->toContain($config['package']['license']);
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
