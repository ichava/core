<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Support;

use Simtabi\Laranail\Ichava\Data\PreferenceOwner;
use Simtabi\Laranail\Ichava\Contracts\OwnerPreferenceStore;

/**
 * An owner-keyed store held in memory, standing in for icon-browser's table.
 *
 * Unscoped it doubles as a guest (session) store. Rows are shared between
 * scoped instances through one array, as table rows would be.
 */
final class InMemoryOwnerPreferenceStore implements OwnerPreferenceStore
{
    /** @var array<string, array<string, mixed>> owner key => preferences */
    public static array $rows = [];

    public function __construct(
        private readonly ?PreferenceOwner $owner = null,
        private readonly string $tier = 'database',
    ) {}

    public function forOwner(PreferenceOwner $owner): static
    {
        return new self($owner, $this->tier);
    }

    public function owner(): ?PreferenceOwner
    {
        return $this->owner;
    }

    public function put(string $key, mixed $value): bool
    {
        self::$rows[$this->bucket()][$key] = $value;

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return self::$rows[$this->bucket()][$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset(self::$rows[$this->bucket()][$key]);
    }

    public function forget(string $key): bool
    {
        unset(self::$rows[$this->bucket()][$key]);

        return true;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return self::$rows[$this->bucket()] ?? [];
    }

    public function clear(): bool
    {
        self::$rows[$this->bucket()] = [];

        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    /** @param array<string, mixed> $preferences */
    public function putAll(array $preferences): bool
    {
        foreach ($preferences as $key => $value) {
            $this->put($key, $value);
        }

        return true;
    }

    public function getBrowserId(): ?string
    {
        return null;
    }

    private function bucket(): string
    {
        return $this->owner?->key() ?? 'guest:' . $this->tier;
    }
}
