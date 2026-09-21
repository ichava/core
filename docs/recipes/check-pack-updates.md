# Check pack updates

*How-to guide.* Ask every installed icon pack whether its upstream source has shipped a newer release.

## Run the check

```bash
php artisan ichava::ichava-core.check-updates
```

| Option | Effect |
|---|---|
| `--package=vendor/name` | check one pack instead of all of them |
| `--format=table\|json` | `table` for a human, `json` for a script |
| `--fail-on-stale` | exit non-zero when any pack is behind — the flag to use in CI |

## What each status means

A pack resolves to exactly one of five states, and only the first two are about versions:

| Status | Meaning |
|---|---|
| `up-to-date` | the vendored version matches upstream |
| `update-available` | upstream has shipped something newer |
| `no-upstream` | the pack declares no upstream to check — **not an error** |
| `unreachable` | the registry could not be reached |
| `error` | the check itself failed |

`no-upstream` is the one worth knowing about before it surprises you. A pack that vendors a one-off snapshot of a commercial product has no public registry, release feed or canonical CDN to poll, so running the tracker across the whole ecosystem reports `no-upstream` for it every time. That is the correct answer, not a misconfiguration — see the pack's own README for how it is refreshed instead.

## Results are cached for twelve hours

Responses are cached for `cache_ttl` seconds, defaulting to **12 hours**, so polling the command in a loop does not hammer the registries and does not give you fresher answers. `IconPackUpdateChecker` takes the TTL as a constructor argument if you need tighter polling.

## React to an update in your own application

The checker dispatches `IconPackUpdateAvailable`, which a host application can listen for and route to Slack, email, a dashboard, or an issue tracker:

```php
use Simtabi\Laranail\Ichava\Events\IconPackUpdateAvailable;
use Illuminate\Support\Facades\Event;

Event::listen(IconPackUpdateAvailable::class, function (IconPackUpdateAvailable $event) {
    // notify however your application notifies
});
```

## Where a pack declares its upstream

Each pack ships `resources/assets/svg/config.json`, and its `upstream` block is what the checker reads:

| Key | What it holds |
|---|---|
| `source` | the registry type and package name |
| `current_version` | the version the vendored SVGs came from |
| `version_check_url` | the endpoint polled for the latest release |
| `license` | the assets' licence |
| `cdn` | URL templates — see [Serve icons from a CDN](serve-icons-from-a-cdn.md) |
| `update_command` | how the assets are refreshed |

**That file is the single source of truth for these values.** A pack's README names its own upstream package and explains its placeholders; it should not restate the versions, because a README and a manifest that both carry a version number drift apart silently and only one of them is read by the code.

## Refreshing the assets is a maintainer task

This command reports; it does not update anything. Refreshing vendored SVGs, bumping `current_version` and opening the pull request is the maintainer toolkit's job — see [upstream tracking](https://opensource.simtabi.com/documentation/ichava/maintainer-toolkit/upstream-tracking) for the schema and the pipeline.

## See also

- [Serve icons from a CDN](serve-icons-from-a-cdn.md)
- [Use an icon pack](use-an-icon-pack.md)
- [Artisan commands](../tools/artisan-commands.md)

---

[← Docs index](../../README.md#documentation)
