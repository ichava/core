<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Constants\IchavaConstants;
use Simtabi\Laranail\Ichava\Enums\OptimizationLevel;
use Simtabi\Laranail\Ichava\Support\Helpers;

/**
 * Ichava, SVG icon management configuration.
 *
 * Publish: php artisan vendor:publish --tag=ichava-config
 *
 * Sections in this file are intentionally terse. For architecture, icon-path
 * grammar, and registration patterns see the package README.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Asset version (cache busting)
    |--------------------------------------------------------------------------
    */
    'version' => env('ICHAVA_VERSION', time()),

    /*
    |--------------------------------------------------------------------------
    | Maximum SVG file size (bytes)
    |--------------------------------------------------------------------------
    | Read by SvgDriver before loading from disk; oversized files are rejected.
    */
    'max_file_size' => env('ICHAVA_MAX_FILE_SIZE', IchavaConstants::MAX_SVG_FILE_SIZE),

    /*
    |--------------------------------------------------------------------------
    | PHP runtime
    |--------------------------------------------------------------------------
    | Applied at provider registration so heavy operations (seeding 100k+
    | icons, batch processing) get the headroom they need.
    */
    'runtime' => [
        'memory_limit' => env('ICHAVA_MEMORY_LIMIT', '2G'),
        'max_execution_time' => env('ICHAVA_MAX_EXECUTION_TIME', 0),
        'disable_telescope_in_queue' => env('ICHAVA_DISABLE_TELESCOPE_QUEUE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    | Icon metadata is stored in the host application's database. PostgreSQL
    | is recommended (full-text search); MySQL 8+ is supported.
    */
    'database' => [
        'enabled' => env('ICHAVA_DATABASE_ENABLED', true),
        'auto_sync' => env('ICHAVA_AUTO_SYNC', true),
        'sync_interval' => env('ICHAVA_SYNC_INTERVAL', IchavaConstants::DB_SYNC_INTERVAL),
        'auto_seed' => env('ICHAVA_AUTO_SEED', false),
        'use_queue' => env('ICHAVA_USE_QUEUE', true),
        'queue_connection' => env('ICHAVA_QUEUE_CONNECTION'),
        'batch_size' => env('ICHAVA_BATCH_SIZE', 1000),
        'smart_queue_threshold' => env('ICHAVA_SMART_QUEUE_THRESHOLD', 5000),

        'search' => [
            'strategy' => env('ICHAVA_SEARCH_STRATEGY', 'simple'),
            'language' => env('ICHAVA_SEARCH_LANGUAGE', 'simple'),
            'languages' => [
                'simple', 'english', 'french', 'german', 'spanish', 'portuguese', 'italian',
            ],
            'scope' => [
                'icon_name' => true,
                'keywords' => true,
                'tags' => true,
                'categories' => true,
                'variants' => true,
                'metadata' => true,
                'package_name' => true,
            ],
            'fuzzy' => [
                'enabled' => true,
                'threshold' => 0.3,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Icon manifest (production caching)
    |--------------------------------------------------------------------------
    | Pre-compiles icon discovery for fast lookups. Generate with:
    | php artisan ichava:cache generate
    */
    'manifest' => [
        'enabled' => env('ICHAVA_MANIFEST_ENABLED', true),
        'path' => env('ICHAVA_MANIFEST_PATH', base_path('bootstrap/cache/ichava-manifest.php')),
        'auto_rebuild' => env('ICHAVA_MANIFEST_AUTO_REBUILD', env('APP_ENV') === 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default icon set
    |--------------------------------------------------------------------------
    | Used when an icon path omits the package prefix.
    */
    'default_set' => env('ICHAVA_DEFAULT_SET', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Global fallback icon
    |--------------------------------------------------------------------------
    | Fully qualified icon path (e.g. 'ichava/tabler-icons::outline/help')
    | rendered when the requested icon cannot be resolved and no per-component
    | fallback is provided. null disables the feature.
    */
    'fallback_icon' => env('ICHAVA_FALLBACK_ICON'),

    /*
    |--------------------------------------------------------------------------
    | Built-in test icon set
    |--------------------------------------------------------------------------
    | Bundled icons used for development and the package test suite.
    */
    'test' => [
        'enabled' => true,
        'path' => null, // null → resources/assets/svg/test-icons
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | Drivers honoured: file, redis, memcached, array.
    */
    'cache' => [
        'ttl' => IchavaConstants::DEFAULT_CACHE_TTL,
        'prefix' => 'ichava',
    ],

    /*
    |--------------------------------------------------------------------------
    | SVG optimization
    |--------------------------------------------------------------------------
    | Levels: none | basic (recommended) | aggressive.
    */
    'optimization' => [
        'level' => OptimizationLevel::BASIC->value,
        'remove_comments' => true,
        'remove_xml_declaration' => true,
        'remove_metadata' => false, // true under 'aggressive'
        'minify' => false, // true under 'aggressive'
    ],

    /*
    |--------------------------------------------------------------------------
    | SVG security
    |--------------------------------------------------------------------------
    | Always keep `sanitize` enabled in production.
    */
    'security' => [
        'sanitize' => true,
        'allowed_tags' => config('ichava.svg.allowed_tags', []),
        'allowed_attributes' => config('ichava.svg.allowed_attributes', []),
        'forbidden_tags' => config('ichava.svg.forbidden_tags', []),

        /*
        |----------------------------------------------------------------------
        | Audit pipeline
        |----------------------------------------------------------------------
        | The AuditLogger emits structured records on a dedicated log channel
        | and dispatches a SecurityAuditEvent for every recorded entry. The
        | audit channel uses 90-day retention by default and 0640 permissions
        | so SIEM forwarders can tail it without world-read access.
        |
        | Set `events` to an empty array (default) to record everything; set
        | it to a list of event names to whitelist only those.
        */
        'audit' => [
            'enabled' => env('ICHAVA_AUDIT_ENABLED', true),
            'channel' => env('ICHAVA_AUDIT_CHANNEL', 'ichava-audit'),
            'dispatch_event' => env('ICHAVA_AUDIT_DISPATCH_EVENT', true),
            'events' => [], // empty = record all
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blade components
    |--------------------------------------------------------------------------
    */
    'components' => [
        'enabled' => env('ICHAVA_COMPONENTS_ENABLED', true),
        'test_icons' => env('ICHAVA_TEST_COMPONENT_ENABLED', env('APP_ENV') === 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom icon sets (in-app, no service provider needed)
    |--------------------------------------------------------------------------
    | Add entries to `custom-icons.sets` for icon directories that live inside
    | your own application rather than a distributed composer package. Each
    | entry is registered with IconRegistry on boot. See README §
    | "Registering Custom Icon Sets" for the field reference and examples.
    */
    'custom-icons' => [
        'sets' => [
            // 'social' => [
            //     'enabled'             => true,
            //     'path'                => storage_path('icons/social'),
            //     'prefix'              => 'social',
            //     'default_class'       => 'social-icon',
            //     'blade_namespace'     => 'ichava',
            //     'blade_component'     => 'social',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default class & attributes
    |--------------------------------------------------------------------------
    | CSS class and HTML attributes applied to every icon by default. Override
    | per icon set or per Blade component as needed.
    */
    'defaults' => [
        'class' => 'w-6 h-6',
        'attributes' => [
            // 'aria-hidden' => 'true',  // decorative icons (WCAG 2.1 A)
            // 'focusable'   => 'false', // legacy IE/Edge focus suppression
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route prefix (shared between core API and browser SPA)
    |--------------------------------------------------------------------------
    | The URL namespace for both `/{prefix}/api/...` (core) and
    | `/{prefix}/icons` (browser). Lives in core's config because core
    | always exists; browser optionally consumes the same value.
    |
    | Env var stays `ICHAVA_BROWSER_PREFIX` for backward compatibility
    | with deployments that already set it.
    */
    'prefix' => env('ICHAVA_BROWSER_PREFIX', 'ichava'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | Channels are auto-registered with Laravel's logging system on boot ,
    | no manual config/logging.php edits required.
    |
    | Log directory resolution (in order):
    |   1. ICHAVA_LOG_PATH (absolute or relative to base_path()).
    |   2. storage_path('logs') , Laravel-standard.
    | Files are named after the channel: ichava.log, ichava-icons.log,
    | ichava-queue.log. Daily rotation appends YYYY-MM-DD before .log.
    */
    'logging' => [
        'enabled' => env('ICHAVA_LOGGING_ENABLED', true),
        'channel' => env('ICHAVA_LOG_CHANNEL', 'ichava'),
        'seeding_channel' => env('ICHAVA_SEEDING_LOG_CHANNEL', 'ichava-icons'),
        'queue_channel' => env('ICHAVA_QUEUE_LOG_CHANNEL', 'ichava-queue'),
        'level' => env('ICHAVA_LOG_LEVEL', 'warning'),
        'seeding_level' => env('ICHAVA_SEEDING_LOG_LEVEL', 'info'),
        'retention_days' => env('ICHAVA_LOG_RETENTION_DAYS', IchavaConstants::LOG_RETENTION_DAYS),
        'auto_cleanup' => env('ICHAVA_LOG_AUTO_CLEANUP', true),
        'cleanup_time' => env('ICHAVA_LOG_CLEANUP_TIME', '03:00'),
        'security' => env('ICHAVA_LOG_SECURITY', true),
        'performance' => env('ICHAVA_LOG_PERFORMANCE', false),
        'requests' => env('ICHAVA_LOG_REQUESTS', false),
        'auth_debug' => env('ICHAVA_LOG_AUTH_DEBUG', false),
        'session_debug' => env('ICHAVA_LOG_SESSION_DEBUG', false),
        'performance_threshold' => env('ICHAVA_PERFORMANCE_THRESHOLD', IchavaConstants::PERFORMANCE_THRESHOLD_MS),
        'deduplication_ttl' => env('ICHAVA_LOG_DEDUP_TTL', 300),

        // Resolved log directory. Absolute paths used as-is; relative paths
        // are resolved against base_path(). Empty falls back to storage/logs.
        'path' => Helpers::logPath(),

        'channels' => [
            'ichava' => [
                'driver' => 'daily',
                'path' => Helpers::logPath('ichava.log'),
                'level' => env('ICHAVA_LOG_LEVEL', 'info'),
                'days' => env('ICHAVA_LOG_RETENTION_DAYS', IchavaConstants::LOG_RETENTION_DAYS),
                'permission' => 0644,
            ],
            'ichava-icons' => [
                'driver' => 'daily',
                'path' => Helpers::logPath('ichava-icons.log'),
                'level' => env('ICHAVA_SEEDING_LOG_LEVEL', 'info'),
                'days' => env('ICHAVA_LOG_RETENTION_DAYS', IchavaConstants::LOG_RETENTION_DAYS),
                'permission' => 0644,
            ],
            'ichava-queue' => [
                'driver' => 'daily',
                'path' => Helpers::logPath('ichava-queue.log'),
                'level' => env('ICHAVA_QUEUE_LOG_LEVEL', 'info'),
                'days' => env('ICHAVA_LOG_RETENTION_DAYS', IchavaConstants::LOG_RETENTION_DAYS),
                'permission' => 0644,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Icon seeding runs in the background on the named queue. When Laravel
    | Horizon is installed, a supervisor is registered automatically unless
    | `horizon.auto_register` is set to false.
    */
    'queue' => [
        'name' => env('ICHAVA_QUEUE_NAME', 'ichava-icons'),
        'connection' => env('ICHAVA_QUEUE_CONNECTION'),
        'stagger_delay' => env('ICHAVA_QUEUE_STAGGER_DELAY', IchavaConstants::QUEUE_STAGGER_DELAY),
        'timeout' => env('ICHAVA_QUEUE_TIMEOUT', IchavaConstants::QUEUE_TIMEOUT),
        'retries' => env('ICHAVA_QUEUE_RETRIES', IchavaConstants::QUEUE_RETRIES),
        'progress_ttl' => env('ICHAVA_PROGRESS_TTL', IchavaConstants::PROGRESS_TTL),

        'horizon' => [
            'auto_register' => env('ICHAVA_HORIZON_AUTO_REGISTER', true),
            'supervisor' => [
                'connection' => env('ICHAVA_HORIZON_CONNECTION', 'redis'),
                'balance' => env('ICHAVA_HORIZON_BALANCE', 'simple'),
                'autoScalingStrategy' => 'time',
                'maxProcesses' => (int) env('ICHAVA_HORIZON_MAX_PROCESSES', 25),
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => (int) env('ICHAVA_HORIZON_MEMORY', 256),
                'tries' => (int) env('ICHAVA_QUEUE_RETRIES', IchavaConstants::QUEUE_RETRIES),
                'timeout' => (int) env('ICHAVA_QUEUE_TIMEOUT', IchavaConstants::QUEUE_TIMEOUT),
                'nice' => 0,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SVG sanitization whitelist
    |--------------------------------------------------------------------------
    | Tag and attribute allow-lists used by the SVG sanitizer. Tighten or
    | extend to match the surface area you actually need.
    */
    'svg' => [
        'tag' => 'svg',
        'extensions' => ['.svg'],

        'allowed_tags' => [
            'svg', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon',
            'g', 'defs', 'clipPath', 'mask', 'use', 'symbol',
            'linearGradient', 'radialGradient', 'ellipse', 'text', 'tspan',
        ],

        'allowed_attributes' => [
            'viewBox', 'width', 'height', 'fill', 'stroke', 'd',
            'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'transform',
            'stroke-width', 'stroke-linecap', 'stroke-linejoin',
            'opacity', 'fill-opacity', 'stroke-opacity',
            'x1', 'x2', 'y1', 'y2', 'offset', 'stop-color',
        ],

        'forbidden_tags' => [
            'script', 'iframe', 'object', 'embed', 'foreignObject',
            'animation', 'animate',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation patterns
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'icon_path_pattern' => '/^([a-z0-9-]+):([a-z0-9-\/]+)$/i',
        'icon_name_pattern' => '/^[a-z0-9-]+$/i',
    ],

    /*
    |--------------------------------------------------------------------------
    | Path separators
    |--------------------------------------------------------------------------
    | See README § "Icon Path Format" for the full grammar (`vendor/package::
    | category/icon-name`). Both slash and dot separators are accepted after
    | `::` and normalized to slash form internally.
    */
    'separators' => [
        'path' => '::',
        'variant' => '/',
        'category' => '/',
    ],
];
