<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Enums;

use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

enum OptimizationLevel: string
{
    use HasEnumeratorBehavior;

    case NONE = 'none';
    case BASIC = 'basic';
    case AGGRESSIVE = 'aggressive';

    public function shouldRemoveComments(): bool
    {
        return match ($this) {
            self::NONE => false,
            self::BASIC, self::AGGRESSIVE => true,
        };
    }

    public function shouldRemoveMetadata(): bool
    {
        return match ($this) {
            self::NONE, self::BASIC => false,
            self::AGGRESSIVE => true,
        };
    }

    public function shouldMinify(): bool
    {
        return match ($this) {
            self::NONE, self::BASIC => false,
            self::AGGRESSIVE => true,
        };
    }
}
