<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Contracts\Container\Container;
use Simtabi\Laranail\Ichava\Data\PreferenceOwner;
use Simtabi\Laranail\Ichava\Support\Auth\HostUser;
use Simtabi\Laranail\Ichava\Contracts\PreferenceStore;
use Simtabi\Laranail\Ichava\Contracts\OwnerPreferenceStore;

/**
 * The preference store core resolves: the owner's store for a signed-in user,
 * the session store for a guest.
 *
 * With no `OwnerPreferenceStore` bound -- core on its own -- every call goes
 * to the session store, exactly as before this class existed. Binding one
 * (ichava/icon-browser does, with its owner-keyed table) is what makes
 * signed-in preferences follow the user; nothing else changes for guests.
 *
 * The target is decided per call, because the signed-in user can change
 * within one long-lived process (Octane, queue workers, a sign-in mid-request).
 */
final readonly class OwnerAwarePreferenceStore implements PreferenceStore
{
    public function __construct(
        private PreferenceStore $guest,
        private HostUser $users,
        private Container $container,
    ) {}

    /** The store the current request writes to. */
    public function target(): PreferenceStore
    {
        if (! $this->container->bound(OwnerPreferenceStore::class)) {
            return $this->guest;
        }

        $user = $this->users->user();

        return $user === null
            ? $this->guest
            : $this->container->make(OwnerPreferenceStore::class)->forOwner(PreferenceOwner::fromUser($user));
    }

    /** The session store guests use, whoever is signed in. */
    public function guest(): PreferenceStore
    {
        return $this->guest;
    }

    public function put(string $key, mixed $value): bool
    {
        return $this->target()->put($key, $value);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->target()->get($key, $default);
    }

    public function has(string $key): bool
    {
        return $this->target()->has($key);
    }

    public function forget(string $key): bool
    {
        return $this->target()->forget($key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->target()->all();
    }

    public function clear(): bool
    {
        return $this->target()->clear();
    }

    public function isAvailable(): bool
    {
        return $this->target()->isAvailable();
    }

    public function getTier(): string
    {
        return $this->target()->getTier();
    }

    /** @param array<string, mixed> $preferences */
    public function putAll(array $preferences): bool
    {
        return $this->target()->putAll($preferences);
    }

    public function getBrowserId(): ?string
    {
        return $this->target()->getBrowserId();
    }
}
