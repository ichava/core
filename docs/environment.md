# Environment variables

*Reference.*

Every `ICHAVA_*` env var that the **engine** (`ichava/core`) reads at runtime, with default, effect, and the `config('ichava.ichava-core.*')` path it drives. HTTP-layer variables (`ICHAVA_API_*`, `ICHAVA_BROWSER_*`, `ICHAVA_DOMAINS`, `ICHAVA_RATE_LIMITING_*`, `ICHAVA_VITE_DEV`) live in [browser/environment.md](https://opensource.simtabi.com/documentation/ichava/icon-browser/environment).

Copy what you need from [`.env.example`](https://github.com/ichava/core/blob/main/.env.example) into your application's `.env`.

## Identity / asset versioning

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_VERSION` | `time()` at boot | Cache-busting token appended to published asset URLs. Pin to a build tag in production. | `ichava.ichava-core.version` |

## PHP runtime tuning

Applied at provider registration so heavy operations (seeding 100k+ icons, batch processing) get the headroom they need.

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_MEMORY_LIMIT` | `2G` | `memory_limit` for Ichava operations | `ichava.ichava-core.runtime.memory_limit` |
| `ICHAVA_MAX_EXECUTION_TIME` | `0` (unlimited) | `max_execution_time` for bulk seeding | `ichava.ichava-core.runtime.max_execution_time` |
| `ICHAVA_DISABLE_TELESCOPE_QUEUE` | `true` | Skip Telescope during queue jobs to avoid memory leaks | `ichava.ichava-core.runtime.disable_telescope_in_queue` |
| `ICHAVA_MAX_FILE_SIZE` | `2_097_152` | Top-level guard read by `SvgDriver` before loading from disk (bytes) | `ichava.ichava-core.max_file_size` |

## Route prefix (shared)

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_BROWSER_PREFIX` | `ichava` | Single segment used by both core's API and browser's SPA. Value lives in core's config so it works whether or not browser is installed. | `ichava.ichava-core.prefix` |

## Database

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_DATABASE_ENABLED` | `true` | Master switch for database-backed indexing | `ichava.ichava-core.database.enabled` |
| `ICHAVA_AUTO_SYNC` | `true` | Watch for filesystem changes and re-sync | `ichava.ichava-core.database.auto_sync` |
| `ICHAVA_SYNC_INTERVAL` | `60` | Seconds between filesystem fingerprint checks | `ichava.ichava-core.database.sync_interval` |
| `ICHAVA_AUTO_SEED` | `false` | Auto-seed on package registration. Disabled by default; prefer `php artisan ichava::ichava-core.database seed` | `ichava.ichava-core.database.auto_seed` |
| `ICHAVA_USE_QUEUE` | `true` | Run seeding via Laravel queue (recommended for large packs) | `ichava.ichava-core.database.use_queue` |
| `ICHAVA_QUEUE_CONNECTION` | `null` | Override the queue connection used for seeding | `ichava.ichava-core.database.queue_connection` |
| `ICHAVA_BATCH_SIZE` | `1000` | Icons per seeding job. Smaller = lower memory per job, more jobs | `ichava.ichava-core.database.batch_size` |
| `ICHAVA_SMART_QUEUE_THRESHOLD` | `5000` | Above this count, `--sync` mode is rejected unless explicitly forced | `ichava.ichava-core.database.smart_queue_threshold` |
| `ICHAVA_SEARCH_STRATEGY` | `simple` | One of `simple`, `multilingual`, `single` | `ichava.ichava-core.search.strategy` |
| `ICHAVA_SEARCH_LANGUAGE` | `simple` | PostgreSQL FTS dictionary used by the `single` and `multilingual` strategies | `ichava.ichava-core.search.language` |

## Manifest (production caching)

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_MANIFEST_ENABLED` | `true` | Use the pre-compiled manifest when available | `ichava.ichava-core.manifest.enabled` |
| `ICHAVA_MANIFEST_PATH` | `bootstrap/cache/ichava-manifest.php` | Filesystem location of the manifest | `ichava.ichava-core.manifest.path` |
| `ICHAVA_MANIFEST_AUTO_REBUILD` | true in `local`, false otherwise | Rebuild the manifest automatically in dev | `ichava.ichava-core.manifest.auto_rebuild` |

## Default + fallback icons

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_DEFAULT_SET` | `test` | Set used when an icon path omits the package prefix | `ichava.ichava-core.default_set` |
| `ICHAVA_FALLBACK_ICON` | `null` | Fully-qualified icon path rendered when an icon can't be resolved | `ichava.ichava-core.fallback_icon` |

## Blade component master switch

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_COMPONENTS_ENABLED` | `true` | Master switch for Blade component registration | `ichava.ichava-core.components.enabled` |

## Logging

Channels are auto-registered with Laravel's logging system on boot, no `config/logging.php` edit required.

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_LOGGING_ENABLED` | `true` | Master switch | `ichava.ichava-core.logging.enabled` |
| `ICHAVA_LOG_CHANNEL` | `ichava` | General-channel name | `ichava.ichava-core.logging.channel` |
| `ICHAVA_SEEDING_LOG_CHANNEL` | `ichava-icons` | Seeding-channel name | `ichava.ichava-core.logging.seeding_channel` |
| `ICHAVA_QUEUE_LOG_CHANNEL` | `ichava-queue` | Queue-channel name | `ichava.ichava-core.logging.queue_channel` |
| `ICHAVA_LOG_LEVEL` | `warning` | Level for the general channel (`debug`/`info`/`warning`/`error`) | `ichava.ichava-core.logging.level` |
| `ICHAVA_SEEDING_LOG_LEVEL` | `info` | Level for the seeding channel | `ichava.ichava-core.logging.seeding_level` |
| `ICHAVA_QUEUE_LOG_LEVEL` | `info` | Level for the queue channel | `ichava.ichava-core.logging.queue_level` |
| `ICHAVA_LOG_RETENTION_DAYS` | `7` | Daily-rotation retention | `ichava.ichava-core.logging.retention_days` |
| `ICHAVA_LOG_AUTO_CLEANUP` | `true` | Enable the daily cleanup job | `ichava.ichava-core.logging.auto_cleanup` |
| `ICHAVA_LOG_CLEANUP_TIME` | `03:00` | 24-h time of day for the cleanup | `ichava.ichava-core.logging.cleanup_time` |
| `ICHAVA_LOG_DEDUP_TTL` | `300` | Suppress duplicate log messages for this many seconds | `ichava.ichava-core.logging.dedup_ttl` |
| `ICHAVA_LOG_REQUESTS` | `false` | Log request entries (only with `APP_DEBUG=true`) | `ichava.ichava-core.logging.log_requests` |
| `ICHAVA_LOG_AUTH_DEBUG` | `false` | Log auth-flow detail (only with `APP_DEBUG=true`) | `ichava.ichava-core.logging.log_auth_debug` |
| `ICHAVA_LOG_SESSION_DEBUG` | `false` | Log session-lifecycle detail (only with `APP_DEBUG=true`) | `ichava.ichava-core.logging.log_session_debug` |
| `ICHAVA_LOG_SECURITY` | `true` | Emit security-event log lines | `ichava.ichava-core.logging.log_security` |
| `ICHAVA_LOG_PERFORMANCE` | `false` | Emit performance-threshold log lines | `ichava.ichava-core.logging.log_performance` |
| `ICHAVA_PERFORMANCE_THRESHOLD` | `200` | Operations slower than N ms get logged when `LOG_PERFORMANCE=true` | `ichava.ichava-core.logging.performance_threshold` |
| `ICHAVA_LOG_PATH` | `storage_path('logs')` | Override the directory log files write to. Absolute paths used as-is; relative paths resolve against `base_path()` | `ichava.ichava-core.logging.path` |

### Log path resolution

Files: `ichava.log`, `ichava-icons.log`, `ichava-queue.log`. Daily rotation appends `-YYYY-MM-DD`.

Resolution order:
1. `ICHAVA_LOG_PATH` if set (absolute or relative to `base_path()`)
2. Otherwise `storage_path('logs')` (Laravel default)

Implemented once in `Simtabi\Laranail\Ichava\Support\Helpers::logPath()`. Always go through the helper:

```php
use Simtabi\Laranail\Ichava\Support\Helpers;

Helpers::logPath();                    // /abs/path/to/storage/logs
Helpers::logPath('ichava.log');        // /abs/path/to/storage/logs/ichava.log
Helpers::logPath('audit/icons.log');   // joined safely against the resolved dir
```

If `ICHAVA_LOG_PATH` is unset *and* `storage_path()` is unavailable (tests bootstrapping outside Laravel), the helper falls back to `sys_get_temp_dir()`.

## Queue

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_QUEUE_NAME` | `ichava-icons` | Queue name for icon-seeding jobs | `ichava.ichava-core.queue.name` |
| `ICHAVA_QUEUE_STAGGER_DELAY` | constant | Seconds between consecutive job dispatches | `ichava.ichava-core.queue.stagger_delay` |
| `ICHAVA_QUEUE_TIMEOUT` | `600` | Per-job timeout (seconds) | `ichava.ichava-core.queue.timeout` |
| `ICHAVA_QUEUE_RETRIES` | `3` | Per-job retry count | `ichava.ichava-core.queue.retries` |
| `ICHAVA_PROGRESS_TTL` | constant | TTL for cached `JobProgressTracker` state | `ichava.ichava-core.queue.progress_ttl` |

## Horizon supervisor

The engine auto-registers a Horizon supervisor for the `ichava-icons` queue when Horizon is installed.

| Key | Default | Effect | Config |
|---|---|---|---|
| `ICHAVA_HORIZON_AUTO_REGISTER` | `true` | Automatic supervisor registration | `ichava.ichava-core.horizon.auto_register` |
| `ICHAVA_HORIZON_CONNECTION` | `redis` | Queue connection used by the supervisor | `ichava.ichava-core.horizon.connection` |
| `ICHAVA_HORIZON_BALANCE` | `simple` | Balance strategy | `ichava.ichava-core.horizon.balance` |
| `ICHAVA_HORIZON_MAX_PROCESSES` | `25` | Max parallel worker processes | `ichava.ichava-core.horizon.maxProcesses` |
| `ICHAVA_HORIZON_MEMORY` | `256` | Memory ceiling per worker (MiB) | `ichava.ichava-core.horizon.memory` |

## See also

- [Configuration reference](configuration.md)
- [Browser environment variables](https://opensource.simtabi.com/documentation/ichava/icon-browser/environment)
- [Database seeding](recipes/seed-the-database.md)

---

[← Docs index](../README.md#documentation)
