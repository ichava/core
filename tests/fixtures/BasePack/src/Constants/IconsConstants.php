<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Tests\Fixtures\BasePack\Constants;

use Simtabi\Laranail\Ichava\Support\PathResolver;
use Simtabi\Laranail\Ichava\Constants\JsonConfigConstants;

final class IconsConstants extends JsonConfigConstants
{
    protected static function getConfigPath(): string
    {
        return PathResolver::resolvePackagePath(self::class, levelsUp: 3, append: 'resources/assets/svg');
    }
}
