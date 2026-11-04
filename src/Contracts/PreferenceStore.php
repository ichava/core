<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

/**
 * Where a user's icon-browser preferences are kept.
 *
 * Extracted so the store can be substituted. `IchavaSessionManager` is `final`
 * and decides its own availability in its constructor, so under the default
 * test harness it resolves the browser tier: every write no-ops, every read
 * returns the default, and a test asserting preference behaviour passes
 * vacuously. That is not a hypothetical -- two tests written against
 * `core v0.3.1` to prove a favourites bug came back one pass and one failure
 * in the opposite direction, because nothing was ever stored.
 *
 * `put()` returns whether the value was stored. Callers must not assume it
 * was: `HostCapabilities` documents a supported stateless-host mode in which
 * the answer is always `false`, and the frontend's localStorage tier is the
 * real store.
 */
interface PreferenceStore
{
    /** @return bool True when the value was stored. */
    public function put(string $key, mixed $value): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function forget(string $key): bool;

    /** @return array<string, mixed> */
    public function all(): array;

    public function clear(): bool;

    public function isAvailable(): bool;

    /** `browser`, `session` or `database`. */
    public function getTier(): string;

    /** @param array<string, mixed> $preferences */
    public function putAll(array $preferences): bool;

    public function getBrowserId(): ?string;
}
