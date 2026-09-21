# Troubleshooting

*How-to guide.*

Common issues across the Ichava ecosystem and how to fix them. Anything specific to a single package lives in that package's own docs.

## Icons aren't appearing

```bash
# Check whether icons are in the database
php artisan ichava::ichava-core.database stats

# Confirm via Tinker
php artisan tinker
>>> \Simtabi\Laranail\Ichava\Models\Icon::count()

# Manually seed
php artisan ichava::ichava-core.database seed --sync
```

If `count()` is zero, the seeder hasn't run yet for any installed pack. Run `ichava::ichava-core.database seed --package=<vendor>/<pack>` per pack, or omit `--package` to seed every installed pack.

## Queue jobs aren't processing

```bash
# Start a queue worker for the seeding queue
php artisan queue:work --queue=ichava-icons --memory=512 --timeout=300

# Inspect failed jobs
php artisan queue:failed

# Retry every failed job
php artisan queue:retry all
```

If you run Horizon, the dashboard at `/horizon` shows the `ichava-icons` supervisor's state and recent throughput.

## Memory errors during seeding

The seeder chunks icons into 1,000-row jobs to avoid memory pressure. If you still hit a memory limit, do one of:

```bash
# Smaller chunks (config/ichava/core.php)
'batch_size' => 500,

# Larger queue worker memory
php artisan queue:work --queue=ichava-icons --memory=1024 --timeout=600

# Confirm the configured runtime memory
php artisan tinker
>>> config('ichava.core.runtime.memory_limit')
```

## "All files unchanged, skipping"

Expected behaviour. The seeder skips icons whose `file_hash` matches what's already in the database, since their tags and keywords haven't changed either. Force a full re-seed:

```bash
php artisan ichava::ichava-core.database seed --update
```

To wipe and re-seed from scratch:

```bash
php artisan ichava::ichava-core.database refresh --force
```

`refresh --force` is destructive (drops Ichava tables, re-runs migrations, re-seeds).

## Stale cache

```bash
# Clear every Laravel cache
php artisan optimize:clear

# Ichava-only caches
php artisan ichava::ichava-core.cache clear
php artisan ichava::ichava-core.cache rebuild
```

The Ichava manifest is rebuilt by `ichava::ichava-core.cache rebuild`. In production, set `ICHAVA_MANIFEST_AUTO_REBUILD=false` and run rebuild on deploy instead.

## Where the logs go

Ichava writes to three rotating files in `storage/logs/`:

```
storage/logs/ichava-YYYY-MM-DD.log         general
storage/logs/ichava-icons-YYYY-MM-DD.log   seeding
storage/logs/ichava-queue-YYYY-MM-DD.log   queue
```

Override the directory with `ICHAVA_LOG_PATH` (absolute or relative to `base_path()`).

## SVG isn't rendering

```bash
php artisan tinker
>>> $icon = \Simtabi\Laranail\Ichava\Models\Icon::where('name', 'home')->first()
>>> $icon?->svg_content
```

If `$icon` is null, the icon isn't seeded. If `svg_content` is null, the source file is missing on disk; re-run `ichava::ichava-core.database seed --update` to refresh it.

## Concurrent seeding errors

Ichava holds a cache lock to prevent two seeders from clobbering each other. The lock TTL is 10 minutes and clears on completion or error. If a seeder dies mid-flight (`kill -9`, OOM kill), the lock survives and blocks the next attempt:

```bash
php artisan tinker
>>> cache()->forget('ichava:seeding:vendor/your-icons')
```

Replace `vendor/your-icons` with the actual package you're trying to seed.

## `ichava::icon-sets-package-scaffolder.make` problems

| Symptom | Likely cause | Fix |
|---|---|---|
| Generated package has `IconsIcons` in class names | You typed `HeroIcons` (with the suffix) instead of `Hero`. The stubs append the literal `Icons` suffix themselves. | Re-run with the bare short name: `php artisan ichava::icon-sets-package-scaffolder.make Hero`. |
| Composer name is `your company/...` (with a space) | A vendor with spaces ended up in `{{vendorLower}}` somewhere. | Use `{{vendorKebab}}` for any composer-name or URL context. |
| `Class ... not found` after `composer install` | The psr-4 namespace key in `composer.json` doesn't match the file's `namespace` declaration. | Both should derive from `{{namespace}}` (or `{{namespaceEscaped}}` in JSON). Re-scaffold or align manually. |
| Multi-variant scaffold has `getDefaultVariant() === null` | `config.json` was edited and the `default: true` flag was removed from every variant. | Set `default: true` on exactly one variant, or rely on the `array_key_first()` fallback. |
| `No stub files found in: ...` warning | `getStubsPath()` points at an empty directory. | Verify `vendor/ichava/core/stubs/icon-package/` is populated. Re-install if you copied the package manually. |
| Custom stubs are ignored | `MakeIconPackageCommand::getStubsPath()` isn't overridden. | Subclass the command and override `getStubsPath()` to return your custom path, or edit the vendor stubs in place for a one-off run. |

## Logging-path resolution surprises

`ICHAVA_LOG_PATH` is honoured by every package log writer **only** when the path is computed via `Helpers::logPath()`. If you wrote a custom logger and the overrides aren't taking effect:

```bash
php artisan tinker
>>> \Simtabi\Laranail\Ichava\Support\Helpers::logPath('ichava.log')
```

Should print the directory you expected. If it points at `storage/logs/` despite `ICHAVA_LOG_PATH` being set, your logger is bypassing the helper. Always go through `Helpers::logPath()` instead of inlining the env-var check.

## Browser SPA loads but no icons show

Confirm three things:

1. At least one icon pack is installed and seeded: `php artisan ichava::ichava-core.info packages`
2. The browser config picks up the right prefix: `php artisan tinker`, then `config('ichava.core.prefix')` should match `/{prefix}/icons` in the URL.
3. The Vite dist assets were published: `ls public/vendor/ichava/assets/`. If empty, run `php artisan vendor:publish --tag=ichava-assets`.

## See also

- [Architecture](architecture.md)
- [Security model](https://github.com/ichava/documentation/blob/main/security-model.md)
- [Core artisan commands](tools/artisan-commands.md)
- [Seeding pack icons](recipes/seed-pack-icons.md)

---

[← Docs index](../README.md#documentation)
