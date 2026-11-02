<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Data\PreferenceOwner;
use Simtabi\Laranail\Ichava\Support\HostCapabilities;
use Simtabi\Laranail\Ichava\Support\IchavaSessionManager;
use Simtabi\Laranail\Ichava\Actions\MergeGuestPreferences;
use Simtabi\Laranail\Ichava\Tests\Support\InMemoryOwnerPreferenceStore;

/*
|--------------------------------------------------------------------------
| The session store's put(), all() and clear() agree on one shape
|--------------------------------------------------------------------------
|
| `put('preferences', ...)` goes through Laravel's session, which nests a
| dotted key: it stores `['ichava' => ['preferences' => ...]]`. `all()` and
| `clear()` used to scan the session's TOP-LEVEL keys for a literal
| `ichava.` prefix, which a nested write never produces. So `all()` was
| always empty, `clear()` removed nothing, and the guest-to-user merge on
| sign-in -- which reads the guest store through `all()` -- never saw a
| single guest preference.
|
| These tests run on a real session driver (`file`), because the `array`
| driver makes `HostCapabilities` report no session and every write no-op.
|
*/

beforeEach(function (): void {
    config()->set('session.driver', 'file');
    HostCapabilities::getInstance()->reset();
    InMemoryOwnerPreferenceStore::$rows = [];

    $this->session = new IchavaSessionManager;
});

afterEach(function (): void {
    session()->flush();
    HostCapabilities::getInstance()->reset();
});

it('runs against a session that is really available', function (): void {
    expect($this->session->isAvailable())->toBeTrue();
});

it('returns from all() what put() stored', function (): void {
    $this->session->put('preferences', ['theme' => 'dark', 'favorites' => ['a']]);
    $this->session->put('theme', 'light');

    expect($this->session->all())->toBe([
        'preferences' => ['theme' => 'dark', 'favorites' => ['a']],
        'theme'       => 'light',
    ]);
});

it('returns the sections putAll() wrote, readable back through get()', function (): void {
    $this->session->putAll(['display' => ['view_mode' => 'grid'], 'locale' => 'fr']);

    $all = $this->session->all();

    expect($all)->toBe(['display' => ['view_mode' => 'grid'], 'locale' => 'fr']);

    foreach ($all as $key => $value) {
        $this->assertSame($value, $this->session->get($key), "all()['{$key}'] disagrees with get('{$key}')");
    }
});

it('clears every key put() stored and leaves the host session alone', function (): void {
    session()->put('host_owned', 'keep');
    $this->session->put('preferences', ['theme' => 'dark']);

    expect($this->session->clear())->toBeTrue()
        ->and($this->session->has('preferences'))->toBeFalse()
        ->and($this->session->all())->toBe([])
        ->and(session()->get('host_owned'))->toBe('keep');
});

it('merges a guest session into the owner store on sign-in, then clears the guest', function (): void {
    $this->session->put('preferences', ['theme' => 'dark', 'favorites' => ['tabler::home']]);

    $owner = (new InMemoryOwnerPreferenceStore)->forOwner(new PreferenceOwner('App\\Models\\User', '7'));

    $changed = (new MergeGuestPreferences)->handle($this->session, $owner);

    expect($changed)->toBe(['preferences'])
        ->and($owner->get('preferences'))->toBe(['theme' => 'dark', 'favorites' => ['tabler::home']])
        ->and($this->session->all())->toBe([]);
});
