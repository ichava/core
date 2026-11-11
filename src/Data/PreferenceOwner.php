<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who a set of preferences belongs to: the `owner_type` / `owner_id` pair an
 * owner-keyed store writes under.
 *
 * The type is the model's morph class, so a host's morph map is honoured and a
 * renamed model class does not orphan its rows; a non-Eloquent user falls back
 * to its class name. The id is always a string, so UUID and integer keys are
 * stored alike.
 */
final readonly class PreferenceOwner
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function fromUser(Authenticatable $user): self
    {
        $identifier = $user->getAuthIdentifier();

        return new self(
            $user instanceof Model ? $user->getMorphClass() : $user::class,
            is_scalar($identifier) ? (string) $identifier : '',
        );
    }

    /** A stable single-string key, `type:id`. */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }
}
