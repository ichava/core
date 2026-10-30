<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Auth;

use Simtabi\Laranail\Ichava\Data\UserData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Ichava\Contracts\PresentsUser;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;

/**
 * The default user shape.
 *
 * - `id`: the auth identifier, as a string, so UUID and integer keys read alike.
 * - `name`: the attribute named by `auth.name_attribute` (default `name`).
 * - `avatarUrl`: the attribute named by `auth.avatar_attribute`, else the
 *   common `avatar_url` / `profile_photo_url` (Jetstream) attributes, else null.
 * - `abilities`: each Gate in `auth.abilities`, answered for this user.
 * - extras: only the attributes listed in `auth.expose`. Nothing else, and
 *   never email by default.
 */
final readonly class DefaultUserPresenter implements PresentsUser
{
    /** Attributes that never leave the server, even when listed in `expose`. */
    private const array NEVER_EXPOSED = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function __construct(
        private Container $container,
    ) {}

    public function present(Authenticatable $user): UserData
    {
        $identifier = $user->getAuthIdentifier();

        return new UserData(
            id: is_scalar($identifier) ? (string) $identifier : '',
            name: $this->string($user, $this->config('name_attribute', 'name')) ?? '',
            avatarUrl: $this->avatar($user),
            abilities: $this->abilities($user),
            extra: $this->extra($user),
        );
    }

    private function avatar(Authenticatable $user): ?string
    {
        $configured = $this->config('avatar_attribute', '');

        foreach ($configured !== '' ? [$configured] : ['avatar_url', 'profile_photo_url'] as $attribute) {
            $value = $this->string($user, $attribute);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(Authenticatable $user): array
    {
        $abilities = config('ichava.ichava-core.auth.abilities', []);

        if (! is_array($abilities) || ! $this->container->bound(GateContract::class)) {
            return [];
        }

        $gate = $this->container->make(GateContract::class)->forUser($user);
        $answers = [];

        foreach ($abilities as $ability) {
            if (is_string($ability) && $ability !== '') {
                $answers[$ability] = $gate->allows($ability);
            }
        }

        return $answers;
    }

    /**
     * @return array<string, mixed>
     */
    private function extra(Authenticatable $user): array
    {
        $expose = config('ichava.ichava-core.auth.expose', []);
        $extra = [];

        foreach (is_array($expose) ? $expose : [] as $attribute) {
            if (is_string($attribute) && ! in_array($attribute, self::NEVER_EXPOSED, true)) {
                $extra[$attribute] = $this->read($user, $attribute);
            }
        }

        return $extra;
    }

    private function string(Authenticatable $user, string $attribute): ?string
    {
        $value = $this->read($user, $attribute);

        return is_scalar($value) ? (string) $value : null;
    }

    private function read(Authenticatable $user, string $attribute): mixed
    {
        return data_get($user, $attribute);
    }

    private function config(string $key, string $default): string
    {
        $value = config("ichava.ichava-core.auth.{$key}");

        return is_string($value) ? $value : $default;
    }
}
