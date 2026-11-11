<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Actions;

use Simtabi\Laranail\Ichava\Contracts\PreferenceStore;

/**
 * Fold a guest's session preferences into a signed-in owner's store.
 *
 * The owner's existing values win: signing in on a second device must not
 * overwrite settings chosen on the first. What the guest added is kept:
 * lists are unioned (favourite ids, history), list items carrying an `id`
 * (collections) are unioned by that id with the owner's copy winning, and
 * nested maps merge key by key under the same rule.
 *
 * The guest store is cleared only after the owner's store accepted the write,
 * so a failed write loses nothing and a second sign-in does not merge the same
 * guest data twice.
 */
final class MergeGuestPreferences
{
    /** @return list<string> The keys the owner's store gained or changed. */
    public function handle(PreferenceStore $guest, PreferenceStore $owner): array
    {
        $changed = [];

        foreach ($guest->all() as $key => $guestValue) {
            $hadKey = $owner->has($key);
            $ownerValue = $owner->get($key);
            $merged = $hadKey ? $this->merge($ownerValue, $guestValue) : $guestValue;

            if ($hadKey && $merged === $ownerValue) {
                continue;
            }

            if (! $owner->put($key, $merged)) {
                return $changed;
            }

            $changed[] = $key;
        }

        $guest->clear();

        return $changed;
    }

    private function merge(mixed $owner, mixed $guest): mixed
    {
        if (! is_array($owner) || ! is_array($guest)) {
            return $owner;
        }

        if (array_is_list($owner) && array_is_list($guest)) {
            return $this->mergeLists($owner, $guest);
        }

        foreach ($guest as $key => $value) {
            $owner[$key] = array_key_exists($key, $owner) ? $this->merge($owner[$key], $value) : $value;
        }

        return $owner;
    }

    /**
     * @param list<mixed> $owner
     * @param list<mixed> $guest
     *
     * @return list<mixed>
     */
    private function mergeLists(array $owner, array $guest): array
    {
        $seen = array_map($this->identity(...), $owner);

        foreach ($guest as $item) {
            $identity = $this->identity($item);

            if (! in_array($identity, $seen, true)) {
                $owner[] = $item;
                $seen[] = $identity;
            }
        }

        return $owner;
    }

    private function identity(mixed $item): mixed
    {
        return is_array($item) && array_key_exists('id', $item) ? ['id' => $item['id']] : $item;
    }
}
