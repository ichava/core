# Host user

Ichava has no auth of its own: it reads the host application's signed-in user, shapes it for the browser, and keys preferences by it.

## Where the user comes from

`Contracts\ResolvesUser` finds the user. The default, `Support\Auth\GuardUserResolver`, reads the guard named by `ichava.ichava-core.auth.guard`, where null (the default) means the application's default guard. That covers session auth, Sanctum, Fortify, Jetstream and Breeze unchanged. A host without an auth system bound has only guests.

To read the user some other way, pass a closure, or bind your own `ResolvesUser`:

```php
use Simtabi\Laranail\Ichava\Facades\IchavaFacade as Ichava;

Ichava::resolveUserUsing(fn () => auth('admin')->user());
```

```php
Ichava::user();         // the host's Authenticatable, or null
Ichava::currentUser();  // UserData, or null for a guest
```

## The user shape

`Contracts\PresentsUser` turns the user into `Data\UserData`, the one shape every surface sends:

| Field | Source |
|---|---|
| `id` | the auth identifier, as a string, so UUID and integer keys read alike |
| `name` | the attribute named by `auth.name_attribute` (default `name`) |
| `avatarUrl` | `auth.avatar_attribute`, else `avatar_url` or `profile_photo_url`, else null |
| `abilities` | each Gate in `auth.abilities`, answered for this user |

Email, passwords and tokens are never sent. `auth.expose` is an explicit opt-in list of extra attributes; `password`, `remember_token` and the two-factor secrets are refused even when listed. Replace the shape with `Ichava::presentUserUsing(fn ($user) => new UserData(...))` or by binding `PresentsUser`.

## Gates

| Ability | Default |
|---|---|
| `ichava.admin` | defined as a denial when the host has not defined it |
| `ichava.manage-cache` | falls back to `ichava.admin` when the host defines `ichava.admin` but not `manage-cache` |

Grant admin access with one Gate, in your own provider:

```php
Gate::define('ichava.admin', fn ($user) => $user->is_admin);
```

Ichava defines its defaults after every provider has booted, so a host's definitions are seen first and never overwritten. When the host defines neither ability, `manage-cache` stays undefined, so ichava/icon-browser keeps reporting it as "not configured" rather than as a refusal.

## Preferences keyed by owner

`Contracts\OwnerPreferenceStore` is a preference store keyed by `owner_type` / `owner_id` (`Data\PreferenceOwner`, built from the user's morph class and identifier). Core ships the contract only: the table belongs to ichava/icon-browser, which owns the features that write it and publishes its migration.

The `PreferenceStore` core binds is `Support\OwnerAwarePreferenceStore`:

- with no `OwnerPreferenceStore` bound, every call reaches the session store, as before;
- with one bound, a signed-in user's preferences go to their owner store, and guests keep the session store;
- on the host's `Login` event, the guest's session preferences merge into the owner's store (`Actions\MergeGuestPreferences`): the owner's values win, lists are unioned, collections are unioned by id, and the guest store is cleared only after the owner store accepted the write.

## Writing preferences

`Actions\UpdatePreferences::handle($changes)` is the one write path for browser preferences (`packages`, `categories`, `search`, `sorting`, `preferences`, `tree`). Unknown sections are ignored, and the result is cleaned by `IconPreferenceService::validate()`. Whichever store is bound (session, or the owner's) receives the write.

## Events for the host

| Event | Fires when |
|---|---|
| `Events\FavoriteToggled` | an icon is added to or removed from the favourites (`iconId`, `favorited`, `persisted`) |
| `Events\CollectionChanged` | a collection is created, updated, deleted, or gains or loses an icon |
| `Events\PreferencesChanged` | preferences are updated, cleared, or merged on sign-in (`keys`, `reason`, `persisted`) |
| `Events\IconRegistrationEvent` | `processing`, `registered` and `completed` per pack (once per deduplication window), and `failed` on every failure |

Read the user inside a listener with `Ichava::currentUser()`. `persisted` is false when no store could keep the change, so a listener can tell a real write from a no-op.

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `ichava.ichava-core.auth.guard` | null (`ICHAVA_AUTH_GUARD`) | the guard to read; null is the default guard |
| `ichava.ichava-core.auth.name_attribute` | `name` | the display-name attribute |
| `ichava.ichava-core.auth.avatar_attribute` | null | the avatar URL attribute |
| `ichava.ichava-core.auth.expose` | `[]` | extra attributes sent to the browser |
| `ichava.ichava-core.auth.abilities` | `['ichava.admin', 'ichava.manage-cache']` | the Gates answered in `abilities` |

---

[← Docs index](../README.md#documentation)
