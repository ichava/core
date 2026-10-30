# Configuration

*Reference.*

Publish `config/ichava/core.php` once after install:

```bash
php artisan vendor:publish --tag=ichava::core-config
```

The published file is the engine's single source of truth. Browser-specific keys live in [`config/ichava/icon-browser.php`](https://opensource.simtabi.com/documentation/ichava/icon-browser/configuration), published separately.

## Top-level keys

```php
return [
    // Asset version token (cache buster on published assets)
    'version' => env('ICHAVA_VERSION'),

    // Hard cap on SVG file size loaded from disk (bytes)
    'max_file_size' => env('ICHAVA_MAX_FILE_SIZE', 2_097_152),

    // SVG kinds: what a browser opens on, and a size ceiling per kind
    'kinds' => [
        'default'       => env('ICHAVA_DEFAULT_KIND', 'icon'),
        'max_file_size' => [
            'illustration' => env('ICHAVA_ILLUSTRATION_MAX_FILE_SIZE', 5 * 1024 * 1024),
        ],
    ],

    // The host's user: which guard, what the browser sees, which Gates
    'auth' => [
        'guard'            => env('ICHAVA_AUTH_GUARD'),
        'name_attribute'   => 'name',
        'avatar_attribute' => null,
        'expose'           => [],
        'abilities'        => ['ichava.admin', 'ichava.manage-cache'],
    ],

    // Shared route prefix (used by both core API and browser SPA)
    'prefix' => env('ICHAVA_BROWSER_PREFIX', 'ichava'),

    // PHP runtime tuning for heavy operations
    'runtime' => [
        'memory_limit'               => env('ICHAVA_MEMORY_LIMIT', '2G'),
        'max_execution_time'         => env('ICHAVA_MAX_EXECUTION_TIME', 0),
        'disable_telescope_in_queue' => env('ICHAVA_DISABLE_TELESCOPE_QUEUE', true),
    ],

    // Database-backed indexing
    'database' => [
        'enabled'                => env('ICHAVA_DATABASE_ENABLED', true),
        'auto_sync'              => env('ICHAVA_AUTO_SYNC', true),
        'sync_interval'          => env('ICHAVA_SYNC_INTERVAL', 60),
        'auto_seed'              => env('ICHAVA_AUTO_SEED', false),
        'use_queue'              => env('ICHAVA_USE_QUEUE', true),
        'queue_connection'       => env('ICHAVA_QUEUE_CONNECTION'),
        'batch_size'             => env('ICHAVA_BATCH_SIZE', 1000),
        'smart_queue_threshold'  => env('ICHAVA_SMART_QUEUE_THRESHOLD', 5000),
    ],

    // Pre-built manifest (skip directory walk in production)
    'manifest' => [
        'enabled'      => env('ICHAVA_MANIFEST_ENABLED', true),
        'path'         => env('ICHAVA_MANIFEST_PATH'),
        'auto_rebuild' => env('ICHAVA_MANIFEST_AUTO_REBUILD', true),
    ],

    // Default + fallback icons
    'default_set'   => env('ICHAVA_DEFAULT_SET', 'test'),
    'fallback_icon' => env('ICHAVA_FALLBACK_ICON'),

    // Blade component master switch
    'components' => [
        'enabled' => env('ICHAVA_COMPONENTS_ENABLED', true),
    ],

    // Logging (channels auto-registered with Laravel's logging system)
    'logging' => [
        'enabled'         => env('ICHAVA_LOGGING_ENABLED', true),
        'channel'         => env('ICHAVA_LOG_CHANNEL', 'ichava'),
        'seeding_channel' => env('ICHAVA_SEEDING_LOG_CHANNEL', 'ichava-icons'),
        'queue_channel'   => env('ICHAVA_QUEUE_LOG_CHANNEL', 'ichava-queue'),
        'level'           => env('ICHAVA_LOG_LEVEL', 'warning'),
        'retention_days'  => env('ICHAVA_LOG_RETENTION_DAYS', 7),
        'auto_cleanup'    => env('ICHAVA_LOG_AUTO_CLEANUP', true),
        'cleanup_time'    => env('ICHAVA_LOG_CLEANUP_TIME', '03:00'),
    ],

    // Queue settings (icon seeding pipeline)
    'queue' => [
        'name'    => env('ICHAVA_QUEUE_NAME', 'ichava-icons'),
        'timeout' => env('ICHAVA_QUEUE_TIMEOUT', 600),
        'retries' => env('ICHAVA_QUEUE_RETRIES', 3),
    ],

    // Horizon supervisor (auto-registers when Horizon is installed)
    'horizon' => [
        'auto_register' => env('ICHAVA_HORIZON_AUTO_REGISTER', true),
        'connection'    => env('ICHAVA_HORIZON_CONNECTION', 'redis'),
        'maxProcesses'  => env('ICHAVA_HORIZON_MAX_PROCESSES', 3),
        'memory'        => env('ICHAVA_HORIZON_MEMORY', 256),
    ],

    // SVG sanitisation policy
    'security' => [
        'allowed_tags'       => [...],
        'allowed_attributes' => [...],
        'forbidden_tags'     => [...],
    ],
];
```

## Keys that take effect at runtime

Every key below is read where it claims to act, so a published (and `config:cache`d) config is honoured:

| Key | Effect |
|---|---|
| `logging.channels.*` | copied verbatim into `logging.channels.<name>`, including the `ichava-audit` channel; the main channel defaults to `warning` |
| `queue.horizon.supervisor.*`, `queue.name` | the Horizon supervisor, with `maxProcesses` 3 in every environment |
| `queue.connection` | the connection seeding jobs are pushed to; null keeps the default |
| `database.smart_queue_threshold` | packs with fewer icons seed synchronously without `--sync` |
| `optimization.level` and the four pass flags | the SVG optimiser; a flag set to `true`/`false` overrides that pass, null follows the level; both are part of the render cache key |
| `test.enabled`, `test.path` | whether, and from where, the bundled test set registers (keep `default_set` pointing at a real pack when it is off) |
| `defaults.class`, `defaults.attributes` | attributes on every `<x-ichava::icon>`; the class only when the caller passes no class and no size |
| `separators.category` | the separator between category and icon; must differ from `separators.path` |
| `registry.strict_conflicts` | throw on a set-name, prefix or Blade-component clash instead of warning (default off) |
| `validation.icon_path_pattern`, `icon_name_pattern` | extra patterns that can only narrow the built-in path checks; null to rely on the built-ins |

## Common changes

| Goal | Edit |
|---|---|
| Speed up cold renders in production | Set `manifest.enabled=true`, run `php artisan ichava::ichava-core.cache rebuild` |
| Stop the file watcher | `database.auto_sync=false` |
| Force-disable seeding via queue | `database.use_queue=false` |
| Change the log retention | `logging.retention_days=14` |
| Read users from another guard | `auth.guard='admin'`; see [Host user](host-user.md) |
| Allow larger illustrations | `kinds.max_file_size.illustration` (bytes); see [Icons and illustrations](kinds.md) |
| Override the icon storage path | use `ICHAVA_LOG_PATH` for logs; icon paths come from each pack's `setPathFrom` |

## See also

- [Environment variables](environment.md), every `ICHAVA_*` env var
- [Browser configuration](https://opensource.simtabi.com/documentation/ichava/icon-browser/configuration), HTTP-layer keys
- [Database seeding](recipes/seed-the-database.md)
- [Architecture](architecture.md)

---

[← Docs index](../README.md#documentation)
