<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Enums\KindSource;

/**
 * An item's kind, and which row of the resolution order decided it.
 *
 * `$rule` names what matched: the override key (host), the rule's `match`
 * (rule) or the category slug (category). It is null for the item, pack and
 * default rows, which have nothing more to say.
 *
 * Computed once per item at seed time and stored on the row; doctor
 * recomputes it to report drift.
 */
final readonly class KindResolution
{
    public function __construct(
        public IconKind $kind,
        public KindSource $source,
        public ?string $rule = null,
    ) {}

    /**
     * Whether this resolution and a stored one say the same thing.
     */
    public function matches(IconKind $kind, KindSource $source, ?string $rule): bool
    {
        return $this->kind === $kind && $this->source === $source && $this->rule === $rule;
    }
}
