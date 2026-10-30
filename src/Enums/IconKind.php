<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Enums;

use Simtabi\Laranail\Ichava\Constants\IchavaConstants;

/**
 * What an SVG is, independent of how it is coloured.
 *
 * An emoji or a flag is a multicolour *icon*; an illustration is a different
 * asset with its own aspect ratio, size budget and rendering defaults. Colour
 * and kind are deliberately separate facts.
 *
 * The values mirror `@ichava/js-ui-kernel`'s `IconKind` union, so the server
 * and the browser share one vocabulary.
 *
 * A pack declares kind in its `config.json`: per category
 * (`metadata.data.categories.<slug>.kind`), falling back to `package.kind`,
 * falling back to {@see self::Icon}.
 */
enum IconKind: string
{
    case Icon = 'icon';
    case Illustration = 'illustration';

    /**
     * The kind a config value names, or null when it names none.
     *
     * Case and surrounding whitespace are forgiven; anything else is not
     * guessed at, so a typo falls through to the next level of the
     * resolution order instead of silently becoming a different kind.
     */
    public static function fromConfig(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }

    /** The kind the configuration names as the default, else {@see self::Icon}. */
    public static function default(): self
    {
        return self::fromConfig(config('ichava.ichava-core.kinds.default')) ?? self::Icon;
    }

    /** English display label for facets and the CLI. */
    public function label(): string
    {
        return match ($this) {
            self::Icon         => 'Icons',
            self::Illustration => 'Illustrations',
        };
    }

    /**
     * Whether a size preset should square the rendered element.
     *
     * Icons are designed on a square grid. Illustrations are not, so forcing
     * width and height equal would squash them: they keep their viewBox
     * aspect and scale from one dimension.
     */
    public function locksAspectRatio(): bool
    {
        return $this === self::Icon;
    }

    /**
     * The largest file, in bytes, an SVG of this kind may be.
     *
     * `kinds.max_file_size.<kind>` when set, otherwise the global
     * `max_file_size`. Illustrations ship a larger default, because a scene
     * with gradients and many paths is routinely bigger than any icon.
     */
    public function maxFileSize(): int
    {
        $global = config('ichava.ichava-core.max_file_size');
        $global = is_numeric($global) ? (int) $global : IchavaConstants::MAX_SVG_FILE_SIZE;

        $perKind = config("ichava.ichava-core.kinds.max_file_size.{$this->value}");

        return is_numeric($perKind) ? (int) $perKind : $global;
    }
}
