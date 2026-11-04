<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Enums;

/**
 * Which row of the kind resolution order decided an item's kind (D6).
 *
 * The cases are declared in row order, which is also the precedence: the
 * first row that yields a valid kind wins. Row 7, the heuristic, only ever
 * suggests and so never appears here.
 *
 * Stored on every `ichava_icons` row as `kind_source`, and served on every
 * API icon, so "why is this an illustration?" has an answer without reading
 * config.
 */
enum KindSource: string
{
    /** `ichava.ichava-core.kinds.overrides` in the host application. */
    case Host = 'host';

    /** `items.<item path>.kind` in the pack's config.json. */
    case Item = 'item';

    /** The first matching `kinds.rules[]` entry in the pack's config.json. */
    case Rule = 'rule';

    /** `categories.<slug>.kind` in the pack's config.json. */
    case Category = 'category';

    /** `package.kind` in the pack's config.json. */
    case Pack = 'pack';

    /** Nothing declared a kind, so the item is an icon. */
    case Default = 'default';

    /** The source's row number in the resolution order, 1 to 6. */
    public function row(): int
    {
        return match ($this) {
            self::Host     => 1,
            self::Item     => 2,
            self::Rule     => 3,
            self::Category => 4,
            self::Pack     => 5,
            self::Default  => 6,
        };
    }
}
