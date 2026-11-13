<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Constants;

use Simtabi\Laranail\Ichava\Support\PathResolver;

/**
 * IchavaTestIconsConstants
 *
 * Constants for the ichava/test-icons icon set.
 * All values automatically extracted from config.json via base class.
 *
 * @see JsonConfigConstants
 */
final class IchavaTestIconsConstants extends JsonConfigConstants
{
    /**
     * Get path to config.json directory
     */
    protected static function getConfigPath(): string
    {
        return PathResolver::resolvePackagePath(self::class, levelsUp: 3, append: 'resources/assets/svg/test-icons');
    }
}
