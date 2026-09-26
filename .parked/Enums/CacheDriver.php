<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Enums;

use Simtabi\Laranail\Enumerator\Concerns\HasEnumeratorBehavior;

enum CacheDriver: string
{
    use HasEnumeratorBehavior;

    case FILE = 'file';
    case REDIS = 'redis';
    case ARRAY = 'array';
    case DATABASE = 'database';
    case MEMCACHED = 'memcached';
}
