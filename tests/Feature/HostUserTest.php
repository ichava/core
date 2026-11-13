<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Auth\Events\Login;
use Simtabi\Laranail\Ichava\Ichava;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Ichava\Data\UserData;
use Simtabi\Laranail\Ichava\Data\PreferenceOwner;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Auth\HostUser;
use Simtabi\Laranail\Ichava\Events\FavoriteToggled;
use Simtabi\Laranail\Ichava\Events\CollectionChanged;
use Simtabi\Laranail\Ichava\Support\Auth\IchavaGates;
use Simtabi\Laranail\Ichava\Contracts\PreferenceStore;
use Simtabi\Laranail\Ichava\Events\PreferencesChanged;
use Simtabi\Laranail\Ichava\Events\IconRegistrationEvent;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Simtabi\Laranail\Ichava\Contracts\OwnerPreferenceStore;
use Simtabi\Laranail\Ichava\Services\IconPreferenceService;
use Simtabi\Laranail\Ichava\Support\OwnerAwarePreferenceStore;
use Simtabi\Laranail\Ichava\Tests\Support\InMemoryOwnerPreferenceStore;

/*
 * Plug-and-play host user: Ichava reads the host's signed-in user, never
 * runs auth of its own, shapes it without private fields, and keys
 * preferences by owner when the host provides an owner store.
 */

function hostUser(array $attributes = []): GenericUser
{
    return new GenericUser([
        'id'       => 42,
        'name'     => 'Ada',
        'email'    => 'ada@example.test',
        'password' => 'not-a-real-hash',
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    InMemoryOwnerPreferenceStore::$rows = [];
});

it('reads the default guard and is a guest when nobody is signed in', function (): void {
    expect(app(Ichava::class)->currentUser())->toBeNull();

    $this->actingAs(hostUser());

    expect(app(Ichava::class)->currentUser()?->id)->toBe('42');
});

it('reads the guard named by auth.guard', function (): void {
    config()->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
    $this->actingAs(hostUser(), 'staff');
    // actingAs() also makes `staff` the default guard; put `web` back so the
    // default and the configured guard differ.
    auth()->shouldUse('web');

    expect(app(HostUser::class)->user())->toBeNull();

    config()->set('ichava.ichava-core.auth.guard', 'staff');

    expect(app(HostUser::class)->user()?->getAuthIdentifier())->toBe(42);
});

it('lets the host resolve the user with a closure', function (): void {
    app(Ichava::class)->resolveUserUsing(fn () => hostUser(['id' => 'uuid-7', 'name' => 'Grace']));

    expect(app(Ichava::class)->currentUser()?->toArray())->toMatchArray(['id' => 'uuid-7', 'name' => 'Grace']);
});

it('never sends email, password or tokens, and sends extras only when opted in', function (): void {
    $this->actingAs(hostUser(['avatar_url' => 'https://cdn.example.test/ada.png', 'team' => 'core']));

    $shape = app(Ichava::class)->currentUser()?->toArray();

    expect(array_keys($shape))->toBe(['id', 'name', 'avatarUrl', 'abilities'])
        ->and($shape['avatarUrl'])->toBe('https://cdn.example.test/ada.png');

    config()->set('ichava.ichava-core.auth.expose', ['team', 'password']);

    expect(app(Ichava::class)->currentUser()?->toArray())
        ->toHaveKey('team', 'core')
        ->not->toHaveKey('password');
});

it('defines ichava.admin as a denial and leaves manage-cache for the host', function (): void {
    $this->actingAs(hostUser());

    expect(Gate::has(IchavaGates::ADMIN))->toBeTrue()
        ->and(Gate::allows(IchavaGates::ADMIN))->toBeFalse()
        // icon-browser reports an undefined manage-cache as "not configured";
        // defining it as a denial here would hide that hint.
        ->and(Gate::has(IchavaGates::MANAGE_CACHE))->toBeFalse()
        ->and(app(Ichava::class)->currentUser()?->can(IchavaGates::ADMIN))->toBeFalse();
});

it('falls manage-cache back to ichava.admin when the host grants admin', function (): void {
    Gate::define(IchavaGates::ADMIN, fn ($user): bool => $user->getAuthIdentifier() === 42);
    IchavaGates::define(app(GateContract::class));

    $this->actingAs(hostUser());

    expect(Gate::allows(IchavaGates::MANAGE_CACHE))->toBeTrue()
        ->and(app(Ichava::class)->currentUser()?->abilities)->toBe([
            IchavaGates::ADMIN        => true,
            IchavaGates::MANAGE_CACHE => true,
        ]);

    $this->actingAs(hostUser(['id' => 7]));
    expect(Gate::allows(IchavaGates::MANAGE_CACHE))->toBeFalse();
});

it('keeps a manage-cache rule the host defined itself', function (): void {
    Gate::define(IchavaGates::ADMIN, fn (): bool => true);
    Gate::define(IchavaGates::MANAGE_CACHE, fn (): bool => false);
    IchavaGates::define(app(GateContract::class));

    $this->actingAs(hostUser());

    expect(Gate::allows(IchavaGates::MANAGE_CACHE))->toBeFalse();
});

it('uses the session store until an owner store is bound, then keys by owner', function (): void {
    $guest = new InMemoryOwnerPreferenceStore(tier: 'session');
    $store = new OwnerAwarePreferenceStore($guest, app(HostUser::class), app());

    $this->actingAs(hostUser());
    expect($store->target())->toBe($guest);

    app()->instance(OwnerPreferenceStore::class, new InMemoryOwnerPreferenceStore);
    $store->put('preferences', ['view_mode' => 'list']);

    expect($store->getTier())->toBe('database')
        ->and(InMemoryOwnerPreferenceStore::$rows)->toHaveKey(PreferenceOwner::fromUser(hostUser())->key())
        ->and($guest->all())->toBe([]);
});

it('merges a guest’s preferences into the owner’s on sign-in', function (): void {
    $guest = new InMemoryOwnerPreferenceStore(tier: 'session');
    app()->instance(OwnerPreferenceStore::class, new InMemoryOwnerPreferenceStore);
    app()->instance(PreferenceStore::class, new OwnerAwarePreferenceStore($guest, app(HostUser::class), app()));

    $owner = (new InMemoryOwnerPreferenceStore)->forOwner(PreferenceOwner::fromUser(hostUser()));
    $owner->put('preferences', ['favorites' => [2, 3], 'view_mode' => 'grid']);
    $guest->put('preferences', ['favorites' => [1, 2], 'view_mode' => 'list', 'icon_size' => 32]);

    Event::fake([PreferencesChanged::class]);
    Event::dispatch(new Login('web', hostUser(), false));

    expect($owner->get('preferences'))->toBe([
        'favorites' => [2, 3, 1],
        'view_mode' => 'grid',   // the owner's own choice wins
        'icon_size' => 32,
    ])->and($guest->all())->toBe([]);

    Event::assertDispatched(PreferencesChanged::class, fn (PreferencesChanged $e): bool => $e->reason === PreferencesChanged::MERGED);
});

it('announces favourite, collection and preference changes to the host', function (): void {
    Event::fake([FavoriteToggled::class, CollectionChanged::class, PreferencesChanged::class]);
    $service = new IconPreferenceService(app(IchavaLogger::class), new InMemoryOwnerPreferenceStore(tier: 'session'));

    $service->toggleFavorite(5);
    $service->toggleFavorite(5);
    $collection = $service->createCollection('Brand');
    $service->addIconToCollection($collection['id'], 5);
    $service->update(['view_mode' => 'list']);

    Event::assertDispatchedTimes(FavoriteToggled::class, 2);
    Event::assertDispatched(FavoriteToggled::class, fn (FavoriteToggled $e): bool => $e->iconId === 5 && $e->favorited && $e->persisted);
    Event::assertDispatched(CollectionChanged::class, fn (CollectionChanged $e): bool => $e->action === CollectionChanged::ICON_ADDED && $e->iconId === 5);
    Event::assertDispatched(PreferencesChanged::class, fn (PreferencesChanged $e): bool => $e->keys === ['view_mode']);
    // Library writes do not also masquerade as preference changes.
    Event::assertNotDispatched(PreferencesChanged::class, fn (PreferencesChanged $e): bool => in_array('favorites', $e->keys, true));
});

it('dispatches the registration lifecycle, once, in order', function (): void {
    config()->set('ichava.ichava-core.logging.deduplication_ttl', 300);
    cache()->flush();
    Event::fake([IconRegistrationEvent::class]);

    $path = dirname(__DIR__) . '/fixtures/KindPack';
    app(IconRegistry::class)->fromDirectory($path);

    $actions = [];
    Event::assertDispatched(IconRegistrationEvent::class, function (IconRegistrationEvent $e) use (&$actions): bool {
        $actions[] = $e->action;

        return true;
    });

    expect($actions)->toBe(['processing', 'registered', 'completed']);
});

it('dispatches failed when a registration throws', function (): void {
    Event::fake([IconRegistrationEvent::class]);

    expect(fn () => app(IconRegistry::class)->registerIconSet(
        'ichava-fixtures/broken',
        app(IconRegistry::class)->set('ichava/test-icons'),
        ['package_name' => 'ichava-fixtures/broken', 'icon_set_name' => 'x', 'base_path' => '/does/not/exist'],
    ))->toThrow(Exception::class);

    Event::assertDispatched(IconRegistrationEvent::class, fn (IconRegistrationEvent $e): bool => $e->isFailed()
        && $e->getException() instanceof Throwable);
});

it('shapes UserData the same way for every surface', function (): void {
    $data = new UserData('1', 'Ada', null, ['ichava.admin' => false]);

    expect($data->toArray())->toBe(['id' => '1', 'name' => 'Ada', 'avatarUrl' => null, 'abilities' => ['ichava.admin' => false]])
        ->and($data->can('ichava.admin'))->toBeFalse();
});
