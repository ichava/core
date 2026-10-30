<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

/**
 * The host's signed-in user, as the browser sees it.
 *
 * Deliberately small: an id, a display name, an avatar URL and the Gate
 * answers the UI needs. Email, password and tokens are never part of it;
 * `extra` holds only attributes the host listed in
 * `ichava.ichava-core.auth.expose`.
 */
final readonly class UserData
{
    /**
     * @param array<string, bool> $abilities Gate ability => granted
     * @param array<string, mixed> $extra Host-approved extra attributes
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatarUrl = null,
        public array $abilities = [],
        public array $extra = [],
    ) {}

    public function can(string $ability): bool
    {
        return $this->abilities[$ability] ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->extra,
            'id'        => $this->id,
            'name'      => $this->name,
            'avatarUrl' => $this->avatarUrl,
            'abilities' => $this->abilities,
        ];
    }
}
