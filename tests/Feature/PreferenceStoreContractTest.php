<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Contracts\PreferenceStore;
use Simtabi\Laranail\Ichava\Services\IconPreferenceService;

/*
|--------------------------------------------------------------------------
| Preference writes tell the truth, and can be tested at all
|--------------------------------------------------------------------------
|
| Two findings, and the first is why the second went unnoticed.
|
| F2: the service took `IchavaSessionManager` -- a `final` class that decides
| its own availability in its constructor. In the default Testbench harness it
| resolves `tier: browser`, so every write no-ops and every read returns the
| default. A test asserting preference behaviour PASSED VACUOUSLY. Two tests
| written to prove a separate favourites bug came back one pass and one
| failure in the opposite direction, because nothing was ever stored.
|
| F1: `set()` ignored `put()`'s return -- which is `false` when no session is
| available -- and logged `Preference updated` regardless. All 39 public
| methods route through it. `HostCapabilities` documents a supported stateless
| host mode, so in production that is every write discarding while the audit
| trail claims success.
|
| The seam is an interface. It is the smallest change that makes the rest
| testable, which is why it comes first.
|
*/

final class ArrayPreferenceStore implements PreferenceStore
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private bool $available = true) {}

    public function put(string $key, mixed $value): bool
    {
        if (! $this->available) {
            return false;
        }
        $this->data[$key] = $value;

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function forget(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function clear(): bool
    {
        $this->data = [];

        return true;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getTier(): string
    {
        return $this->available ? 'session' : 'browser';
    }

    public function putAll(array $preferences): bool
    {
        return $this->put('bulk', $preferences);
    }

    public function getBrowserId(): ?string
    {
        return null;
    }
}

function prefs(bool $storeWorks = true): IconPreferenceService
{
    app()->instance(PreferenceStore::class, new ArrayPreferenceStore($storeWorks));

    return app()->make(IconPreferenceService::class);
}

it('persists a preference when the store works', function () {
    // F2: impossible before -- no seam to hand the service a working store.
    $service = prefs();

    $service->set('search', 'home');

    expect($service->get('search'))->toBe('home');
});

it('reports failure when the store refuses the write', function () {
    // F1: this returned void and logged success.
    expect(prefs(storeWorks: false)->set('search', 'home'))->toBeFalse();
});

it('reports success when the store accepts the write', function () {
    expect(prefs()->set('search', 'home'))->toBeTrue();
});

it('reports failure from a bulk update too', function () {
    expect(prefs(storeWorks: false)->update(['favorites' => [1]]))
        ->toHaveKey('persisted', false);
});

it('reserves the persisted key against a client that sends one', function () {
    // `+` would have kept the client's value and dropped the outcome -- the
    // exact class of silent loss this change exists to end.
    expect(prefs(storeWorks: false)->update(['persisted' => 'yes']))
        ->toHaveKey('persisted', false);
});
