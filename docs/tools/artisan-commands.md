# Artisan Commands Reference

*Reference.*

### Database Commands (`ichava::ichava-core.database`)

```bash
php artisan ichava::ichava-core.database                       # Interactive menu
php artisan ichava::ichava-core.database migrate               # Run migrations
php artisan ichava::ichava-core.database migrate --fresh       # Drop + re-run migrations
php artisan ichava::ichava-core.database seed                  # Seed all packages
php artisan ichava::ichava-core.database seed --package=X      # Seed specific package
php artisan ichava::ichava-core.database seed --sync           # Force synchronous
php artisan ichava::ichava-core.database seed --update         # Force update all (even unchanged)
php artisan ichava::ichava-core.database seed --fresh          # Truncate + seed
php artisan ichava::ichava-core.database seed:icons            # Seed icons only
php artisan ichava::ichava-core.database seed:terms            # Seed terms only
php artisan ichava::ichava-core.database unseed --package=X    # Remove package data
php artisan ichava::ichava-core.database unseed --all          # Remove all data
php artisan ichava::ichava-core.database refresh               # migrate --fresh + seed
php artisan ichava::ichava-core.database truncate              # Truncate tables
php artisan ichava::ichava-core.database stats                 # Show statistics
```

### Cache Commands (`ichava::ichava-core.cache`)

```bash
php artisan ichava::ichava-core.cache clear                   # Clear all caches
php artisan ichava::ichava-core.cache clear --package=X       # Clear package cache
php artisan ichava::ichava-core.cache rebuild                 # Rebuild caches
php artisan ichava::ichava-core.cache refresh                 # Clear + rebuild
php artisan ichava::ichava-core.cache generate                # Generate manifest
php artisan ichava::ichava-core.cache stats                   # Show statistics
```

### Information Commands (`ichava::ichava-core.info`)

```bash
php artisan ichava::ichava-core.info packages                 # List all packages
php artisan ichava::ichava-core.info packages --search=tabler # Search packages
php artisan ichava::ichava-core.info icons                    # List icons
php artisan ichava::ichava-core.info icons --package=X        # Icons in package
php artisan ichava::ichava-core.info icons --search=home      # Search icons
php artisan ichava::ichava-core.info languages                # List FTS languages
php artisan ichava::ichava-core.info discover                 # Discover packages
php artisan ichava::ichava-core.info stats                    # Full stats
php artisan ichava::ichava-core.info status                   # Lifecycle status
```

### Other Commands

```bash
php artisan ichava::ichava-core.job-status                    # Check seeding-job progress (reads JobProgressTracker)
php artisan ichava::ichava-core.watch                         # Watch icon files for changes (dev)
```

### Scaffolding a new pack moved out of core

`ichava::ichava-core.make:icon-package` was removed in core `0.3.0`. It lives in
[`ichava/icon-sets-package-scaffolder`](https://github.com/ichava/icon-sets-package-scaffolder) now, as
`ichava::icon-sets-package-scaffolder.make`:

```bash
composer require --dev ichava/icon-sets-package-scaffolder
php artisan ichava::icon-sets-package-scaffolder.make
```

Every flag it accepts is documented in that package's own
[command reference](https://github.com/ichava/icon-sets-package-scaffolder/blob/main/docs/tools/make-command.md);
this page covers the commands `ichava/core` itself registers.

### `ichava::ichava-core.cleanup-logs`

Removes Ichava log files older than the configured retention period (`ichava.logging.retention_days`, default 7). The package's scheduler entry runs it daily at the configured `cleanup_time` (default `03:00`); it can also be triggered manually.

```bash
php artisan ichava::ichava-core.cleanup-logs                  # delete logs older than retention_days
php artisan ichava::ichava-core.cleanup-logs --days=14        # override the retention window (one-off)
php artisan ichava::ichava-core.cleanup-logs --dry-run        # report what would be deleted, change nothing
```

### `ichava::ichava-core.check-updates`

Reports whether any registered icon pack is behind its upstream source. Reads each pack's `upstream` block from its `config.json`, hits the declared `version_check_url` (npm registry, GitHub releases / tags, Packagist, or a custom URL), and prints a status table. Dispatches `IconPackUpdateAvailable` events for stale packs so host apps can wire Slack / email / dashboard notifications.

```bash
php artisan ichava::ichava-core.check-updates                              # table for every pack
php artisan ichava::ichava-core.check-updates --package=ichava/icon-sets-emoji  # restrict to one pack
php artisan ichava::ichava-core.check-updates --format=json                # machine-readable
php artisan ichava::ichava-core.check-updates --fail-on-stale              # exit 1 if any pack is behind
```

| Arg / Flag | Effect |
|---|---|
| `--package=` | Restrict to one `vendor/name` pack (else: every pack in `IconRegistry`). |
| `--format=table\|json` | Output shape. `json` is what CI scripts consume. |
| `--fail-on-stale` | Exit non-zero when any pack is behind or unreachable. |

Refreshing the actual SVG assets is **not** an end-user step -- `vendor/` is regenerated on every `composer install`. The maintainer-side refresh runs in CI via [`ichava/maintainer-toolkit`](https://github.com/ichava/maintainer-toolkit); see [`../icon-pack-maintainer-sync.md`](https://opensource.simtabi.com/documentation/ichava/maintainer-toolkit/maintainer-sync).

### `ichava:inject-npm-scripts`

Adds the Ichava asset-build scripts (`ichava:dev`, `ichava:build`, `ichava::ichava-core.watch`) to the host application's `package.json`. Existing keys are preserved unless `--force` is supplied.

```bash
php artisan ichava::browser.inject-scripts                                 # patches base_path('package.json')
php artisan ichava::browser.inject-scripts --path=/abs/path/to/package.json # custom location
php artisan ichava::browser.inject-scripts --force                          # overwrite if scripts already exist
```

### Programmatic seeding progress

`JobProgressTracker` is a small cache-backed counter readable by the browser UI and `ichava::ichava-core.job-status`. The seeding pipeline writes to it automatically; consumers normally only read.

```php
use Simtabi\Laranail\Ichava\Support\JobProgressTracker;

JobProgressTracker::start('vendor/your-icons', total: 5963);
JobProgressTracker::update('vendor/your-icons', processed: 1500);
JobProgressTracker::complete('vendor/your-icons', meta: ['duration_seconds' => 120]);

$state = JobProgressTracker::status('vendor/your-icons'); // ['total' => …, 'processed' => …, 'state' => …]
```

---

---

[← Docs index](../../README.md#documentation)
