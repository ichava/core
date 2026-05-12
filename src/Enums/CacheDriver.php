<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Enums;

enum CacheDriver: string
{
    case FILE = 'file';
    case REDIS = 'redis';
    case ARRAY = 'array';
    case DATABASE = 'database';
    case MEMCACHED = 'memcached';
}
