[← Docs index](../README.md#documentation)

# Architecture

*Explanation.*

Ichava is built on the [Laranail Package Tools](https://github.com/laranail/package-tools) library (a Spatie package-tools fork). The ecosystem is composed of focused, single-responsibility Composer packages that depend on each other through a clean directed graph. Install only what you need.

## What core provides

Relocated from the README, which is now a slim pointer.

| Concern | What core ships |
|---|---|
| Security | DOM-based SVG sanitiser with a strict allow-list. XXE-safe (`LIBXML_NONET`, `resolveExternals=false`). Blocks `javascript:`, `vbscript:`, `file:` and every `on*` handler. |
| Performance | Icon-level cache, SVG optimisation, indexed database lookup, and a pre-built manifest for production. |
| Blade | The base `<x-ichava::icon>` component every icon pack extends. |
| Fluent API | `ichava('vendor/pkg::category/name')->color('...')->class('...')`. |
| Seeder | Queue-backed pipeline with multi-level dedup, change detection, Horizon-aware. |
| Logging | Three dedicated channels: `ichava`, `ichava-icons`, `ichava-queue`. |
| Search | Full-text search on PostgreSQL; portable `LIKE` search on SQLite, MySQL and MariaDB. |

**Zero HTTP surface.** No REST endpoints, no middleware, no routes. The HTTP layer -- REST API plus the Vue/Vite SPA -- lives in the optional [`ichava/browser`](https://opensource.simtabi.com/documentation/ichava/browser/installation) package, which is why `composer require ichava/core` alone gives a fully functional headless icon engine.

Scaffolding a new icon pack is **not** part of core either; it lives in [`ichava/icon-sets-package-scaffolder`](https://opensource.simtabi.com/documentation/ichava/icon-sets-package-scaffolder/creating-icon-packages) and replaced `ichava::ichava-core.make:icon-package`, which core shipped up to `0.2.7`.

## Package topology

```mermaid
flowchart TB
    core["<b>ichava/core</b><br/>services, registry, seeder, Blade base,<br/>scaffolder, migrations, log channels<br/>(no HTTP surface, headless-friendly)"]
    browser["<b>ichava/browser</b><br/>REST API + Vue/Vite SPA<br/>middleware + browser views"]
    tabler["<b>ichava/icon-sets-tabler</b><br/>6,184 icons"]
    bundled["<b>ichava/icon-sets-bundled</b><br/>121,314 icons / 72 sets<br/>PRIVATE"]
    metronic["<b>ichava/icon-sets-metronic</b><br/>501 icons<br/>PRIVATE"]
    flag["<b>ichava/icon-sets-flag</b><br/>542 flags, 1x1 + 4x3"]
    emoji["<b>ichava/icon-sets-emoji</b><br/>wiring only, no assets yet"]
    third["<b>3rd-party packs</b><br/>via ichava::icon-sets-package-scaffolder.make"]
    docs["<i>ichava/documentation</i><br/>markdown only"]
    meta["<i>ichava/ichava (planned)</i><br/>metapackage + landing"]
    toolkit["<b>ichava/maintainer-toolkit</b><br/>Docker-first Python tool<br/>refreshes vendored SVGs via PR<br/>(not installed by users)"]
    motion["<i>@ichava/motion</i><br/>SVG animation engine<br/>~205 presets, no runtime deps<br/>(outside the Composer graph)"]
    react["<i>@ichava/react-browser</i><br/>React 19 rebuild of the front end<br/>PRIVATE, not on npm"]

    browser -- depends on --> core
    tabler -- depends on --> core
    bundled -- depends on --> core
    metronic -- depends on --> core
    flag -- depends on --> core
    emoji -- depends on --> core
    third -- depends on --> core
    meta -.-> core
    meta -.-> browser
    toolkit -. CI refreshes .-> tabler
    toolkit -. CI refreshes .-> bundled
    toolkit -. CI refreshes .-> metronic
    toolkit -. CI refreshes .-> flag
    toolkit -. CI refreshes .-> emoji
    react -. replaces the SPA in .-> browser

    classDef engine fill:#1f6feb,stroke:#1f6feb,color:#fff
    classDef http fill:#8957e5,stroke:#8957e5,color:#fff
    classDef pack fill:#0e8a16,stroke:#0e8a16,color:#fff
    classDef other fill:#6e7681,stroke:#6e7681,color:#fff
    classDef tooling fill:#bf8700,stroke:#bf8700,color:#fff
    class core engine
    class browser http
    class tabler,bundled,metronic,flag,emoji,third pack
    class docs,meta,motion,react other
    class toolkit tooling
```

The `ichava/browser` package owns the *entire HTTP layer* (REST API, SPA, middleware). Core ships zero HTTP surface, so `composer require ichava/core` alone gives a fully functional headless icon engine. Icon packs depend only on core, so a CLI-only deployment can install `core` + a pack with no Node, Vite, or browser dependencies.

## Dependency rules

| Rule | Rationale |
|---|---|
| `ichava/core` depends only on `laranail/package-tools` and Laravel framework | Headless server installs (no JS toolchain) work cleanly. |
| `ichava/browser` depends on `ichava/core` | Browser is a UI consumer, not an icon source. |
| Icon packs depend on `ichava/core` (never on `ichava/browser`) | Decouples icon distribution from UI. Install core + a pack and have CLI / Blade access with no Vue, Vite, or Node.js. |
| `ichava/browser` does not depend on icon packs | The browser discovers them at runtime via `IconRegistry`. Install any combination. |
| Cross-package class names follow constants (`IconsServiceProvider`, `IconsConstants`, `IconComponent`, `Variant` / `Category`) | Disambiguated by namespace. No double-suffix bugs and easy ecosystem-wide grep. |

## Service-provider hierarchy

```mermaid
classDiagram
    class PackageServiceProvider {
        <<laranail/package-tools>>
    }
    class IchavaServiceProvider {
        <<ichava/core>>
        engine: log channels,
        singletons, Horizon supervisor
    }
    class SupportServiceProvider {
        <<ichava/core, abstract>>
        registeringPackage()
        bootingPackage()
        registerIconDirectory()
    }
    class IchavaBrowserServiceProvider {
        <<ichava/browser>>
        routes, views, middleware,
        SPA assets, browser components
    }
    class TablerIcons_IconsServiceProvider["IconsServiceProvider"] {
        <<Ichava\IconSetsTabler\Providers>>
    }
    class BundledIcons_IconsServiceProvider["IconsServiceProvider"] {
        <<Ichava\IconsBundle\Providers>>
    }
    class MetronicIcons_IconsServiceProvider["IconsServiceProvider"] {
        <<Ichava\IconSetsMetronic\Providers>>
    }
    class FlagIcons_IconsServiceProvider["IconsServiceProvider"] {
        <<Ichava\IconSetsFlag\Providers>>
    }
    class EmojiSets_IconsServiceProvider["IconsServiceProvider"] {
        <<Ichava\IconSetsEmoji\Providers>>
    }
    PackageServiceProvider <|-- IchavaServiceProvider
    PackageServiceProvider <|-- SupportServiceProvider
    PackageServiceProvider <|-- IchavaBrowserServiceProvider
    SupportServiceProvider <|-- TablerIcons_IconsServiceProvider
    SupportServiceProvider <|-- BundledIcons_IconsServiceProvider
    SupportServiceProvider <|-- MetronicIcons_IconsServiceProvider
    SupportServiceProvider <|-- FlagIcons_IconsServiceProvider
    SupportServiceProvider <|-- EmojiSets_IconsServiceProvider
```

**Every pack's provider is called `IconsServiceProvider`.** The short name is a constant across the
ecosystem and only the namespace varies, so a contributor can `grep "class IconsServiceProvider"`
and find all five. A `<Pack>IconsServiceProvider` name is the thing this convention exists to
prevent; the same holds for `IconsConstants` and `IconComponent`.

> **Rule for icon packs:** extend `Simtabi\Laranail\Ichava\Support\ServiceProvider` (lives in `ichava/core`). Never extend `IchavaServiceProvider`, `IchavaBrowserServiceProvider`, or `PackageServiceProvider` directly.

> **Rule for the browser package:** extends `laranail/package-tools`' `PackageServiceProvider` directly. Boots its routes, views, and Vite assets in `bootingPackage()` so core's services are guaranteed to be bound first.

## What `IchavaServiceProvider` registers

Core singletons, in dependency order:

| Binding | Alias | Purpose |
|---|---|---|
| `SvgProcessingService` | - | SVG processing pipeline |
| `IchavaLogger` | `ichava.logger` | Structured logging |
| `PathResolver` | - | Icon path parsing |
| `SvgDriver` | - | SVG file loading and rendering |
| `IconCacheService` | `ichava.cache` | Unified icon caching |
| `IconRegistry` | - | Package and icon-set management |
| `IconWatcherService` | - | File-change monitoring |
| `IconDiscoveryService` | - | Icon browsing and search |
| `IconPreferenceService` | - | Per-user icon preferences |
| `IchavaLifecycleManager` | - | Boot lifecycle management |
| `DeferredIconsRegistry` | - | SVG `<symbol>` sprite pool |
| `ConfigurationService` | - | Config helper (no dependents) |
| `DatabaseOperationsService` | - | Extracted from DB commands |
| `CacheOperationsService` | - | Extracted from cache commands |
| `InformationService` | - | Extracted from info commands |
| `IconsManifest` | - | Manifest generation and reading |
| `Ichava` | `ichava` | Facade backing class |
| `IconRenderer` | - | Fluent renderer (fresh per resolve) |

Artisan commands:

| Command | Sub-actions |
|---|---|
| `ichava::ichava-core.database` | seed, seed:icons, seed:terms, migrate, unseed, refresh, truncate, stats |
| `ichava::ichava-core.cache` | clear, rebuild, refresh, generate, stats |
| `ichava::ichava-core.info` | packages, icons, languages, discover, stats, status |
| `ichava::ichava-core.job-status` | - |
| `ichava::ichava-core.watch` | - |
| `ichava::ichava-core.cleanup-logs` | - |
| `ichava::ichava-core.check-updates` | --package, --format=table\|json, --fail-on-stale |

`InjectNpmScriptsCommand` lives in `ichava/browser`, not core.

Blade components and directives (registered by core):

| Tag | Class | Notes |
|---|---|---|
| `<x-ichava::icon />` | `IconComponent` | Generic, works with any installed pack |
| `<x-ichava::*>` | class-based namespace | Browser layouts, demo components register their own classes via `Blade::component()` |
| `@ichava_defs` | `DeferredIconsRegistry` | Renders the SVG `<defs>` pool |

Log channels (registered before any service that logs):

| Channel | File | Level env var |
|---|---|---|
| `ichava` | `storage/logs/ichava.log` | `ICHAVA_LOG_LEVEL` |
| `ichava-icons` | `storage/logs/ichava-icons.log` | `ICHAVA_SEEDING_LOG_LEVEL` |
| `ichava-queue` | `storage/logs/ichava-queue.log` | `ICHAVA_QUEUE_LOG_LEVEL` |

## What `IchavaBrowserServiceProvider` registers

Routes:

| File | Purpose |
|---|---|
| `routes/api.php` | REST endpoints (icons, packages, terms, preferences, cache) |
| `routes/web.php` | SPA + cache-management web routes |

Middleware groups:

| Group | Stack |
|---|---|
| `ichava.api` | hybrid: `web` (Sanctum) or `StartSession` (sessions only) or none, plus `ichava.guard`, `ichava.session`, `ichava.security`, `ichava.json`, `ichava.log`, `throttle:N,1` |
| `ichava.web` | `web`, `ichava.validate` |

Per-middleware aliases: `ichava.guard`, `ichava.session`, `ichava.security`, `ichava.json`, `ichava.log`, `ichava.validate`. Plus the legacy `ichava.api.security` alias.

The middleware stack uses **hybrid detection** via `HostCapabilities`. Adds the `web` middleware only when Laravel Sanctum + sessions are available; otherwise falls back to a minimal stateless stack. This means `ichava/browser` works in any Laravel application without configuration.

Browser provider also publishes:

- Browser config: `config/ichava/browser.php` (`--tag=ichava::browser-config`)
- SPA dist assets: `public/vendor/ichava/` (`--tag=ichava-assets`)
- Browser-only Blade components: `<x-ichava::layouts.app>`, `<x-ichava::layouts.browser>`, `<x-ichava::ichava-test-icons>`, `<x-ichava::ichava-ui-icons>`
- Anonymous Blade component path under the `ichava::` namespace
- Browser's bundled `ui-icons` set (registered with core's `IconRegistry`)

## Boot order

```mermaid
sequenceDiagram
    autonumber
    participant App as Laravel app
    participant Core as IchavaServiceProvider
    participant Pack as Pack provider
    participant Browser as IchavaBrowserServiceProvider

    App->>Core: register()
    Note over Core: log channels created<br/>core singletons bound<br/>Horizon supervisor configured
    App->>Pack: registeringPackage()
    Note over Pack: config merge + commands only.<br/>Core services NOT yet bound;<br/>do not touch IconRegistry or IchavaLogger.
    App->>Core: boot()
    Note over Core: Blade directives,<br/>generic &lt;x-ichava::icon&gt; component
    App->>Pack: bootingPackage()
    Pack->>Core: registerIconDirectory()
    Pack->>Core: log via IchavaLogger
    App->>Browser: bootingPackage()
    Note over Browser: routes, views, middleware,<br/>SPA assets, browser components.<br/>Core's IconRegistry is bound (composer order).
```

Common bug: logging in `registeringPackage()` throws `Log [ichava] not defined` because the channel isn't registered yet. Move it to `bootingPackage()`.

## Package configuration mechanics

`IchavaServiceProvider::configurePackage()` runs before any bindings are registered (the packager's earliest lifecycle hook). It declares:

- The published config file (`config/ichava/core.php`)
- All Artisan commands
- The `@ichava_defs` Blade directive

Migrations are auto-discovered through `discoversMigrations()`, which uses the smart default `database/migrations` resolved from the package's calculated `basePath`. `runsMigrations()` ensures they run on `migrate` without an explicit publish step.

Public asset publishing for the SPA happens in `IchavaBrowserServiceProvider::bootingPackage()`, not core. The browser package's `public/` directory is copied to `public/vendor/ichava/` under the `ichava-assets` tag.

## RuntimeConfigurator (PHP runtime tuning)

Heavy operations (seeding 100k+ icons, batch processing) need higher memory limits and longer timeouts. Core uses the `RuntimeConfigurator` utility from `laranail/package-tools`:

```php
use Simtabi\Laranail\PackageTools\Support\RuntimeConfigurator;

RuntimeConfigurator::make()
    ->memory('2G')
    ->timeout(0)
    ->disableTelescope()
    ->apply();
```

`IchavaServiceProvider::configureRuntimeSettings()` calls this automatically during `registeringPackage()`, reading `ichava.runtime.memory_limit`, `ichava.runtime.max_execution_time`, and `ichava.runtime.disable_telescope_in_queue` from config. No manual setup required.

See `Simtabi\Laranail\Packager\Package\Support\RuntimeConfigurator` for the full fluent API (preset factories like `forQueueJob()`, `forBatchProcessing()`, scoped `scope()` callbacks, Xdebug and Debugbar toggles).

## IchavaRegistrar (bulk registration helper)

Use `IchavaRegistrar` when a service provider manages multiple icon-set sub-directories under a shared base path (for example, a bundle with 72 sets). It loops over the directories, skips missing ones, and optionally tracks statistics.

| Scenario | Recommended approach |
|---|---|
| Single directory | `$this->registerIconDirectory()` in `bootingPackage()` |
| 2 to 10 directories | `IchavaRegistrar` or `$this->registerBulkIconSets()` |
| 10+ directories or large bundle | `IchavaRegistrar` with `trackStatistics()->enableLogging()` |

> **Call stage:** only call from `bootingPackage()`. `IconRegistry` is not bound until after the registration phase completes.

`packagePrefix`, `iconSetSuffix`, and `bladeNamespace` are extensibility hooks. They live on the registrar instance and are available inside a custom `registerIconSet()` override. The default `registerIconSet()` only passes path + provider class to `IconRegistry::fromDirectory()`; it does not act on these properties itself.

## Icon-package scaffolding

Scaffolding is **not** part of core. It lives in
[`ichava/icon-sets-package-scaffolder`](https://github.com/ichava/icon-sets-package-scaffolder), a dev-only
package, and was removed from core in `0.3.0`.

The direction is deliberate and worth stating, because the obvious arrangement is the wrong one:
the scaffolder does **not** depend on `ichava/core`. Core takes it as a `require-dev`, so a
dependency back on core would be a cycle -- and it is unnecessary, because the generator's only
structural tie to core was inheritance from core's command base, and the one thing that base
supplied comes from `laranail/console` directly.

What a pack is scaffolded *into* is still core's contract: the class short names
(`IconsServiceProvider`, `IconsConstants`, `Variant`, `IconComponent`), the
`Support\ServiceProvider` a pack extends, and the `config.json` shape its constants read. That
contract is documented here; how a pack gets generated is documented
[in the scaffolder](https://github.com/ichava/icon-sets-package-scaffolder/blob/main/docs/architecture.md).

Class short names are constants across every pack, disambiguated by namespace. That eliminates a
class of collision bug and lets a contributor grep `class IconsConstants` ecosystem-wide.

## Events and lifecycle

Two unified event classes cover Ichava's runtime signals. Listeners can branch on the action type or use the `is*()` helpers.

`IconRegistrationEvent`, dispatched throughout package registration:

| Action | Constructor | Fires when |
|---|---|---|
| `started` | `IconRegistrationEvent::started($registrarId, $name, $totalIconSets, $mode)` | A bulk registration begins |
| `processing` | `IconRegistrationEvent::processing($registrarId, $name, $metadata, $position, $total)` | Each icon set in a bulk is processed |
| `registered` | `IconRegistrationEvent::registered($registrarId, $name, $metadata, $duration, $iconCount)` | A single icon set is registered |
| `failed` | `IconRegistrationEvent::failed($registrarId, $name, $metadata, $exception)` | Registration of one set throws |
| `completed` | `IconRegistrationEvent::completed($registrarId, $name, $statistics, $duration)` | The whole bulk run finishes |
| `unregistered` | `IconRegistrationEvent::unregistered($registrarId, $name, $metadata)` | A package is unregistered |

`IconCacheEvent`, dispatched on cache state changes:

| Action | Constructor | Fires when |
|---|---|---|
| `invalidated` | `IconCacheEvent::invalidated($reason, $clearedKeys)` | Cache cleared via command or programmatically |
| `rebuilt` | `IconCacheEvent::rebuilt($iconCount, $categoryCount, $packageCount, $buildTimeMs)` | Manifest rebuilt |
| `changed` | `IconCacheEvent::changed($package, $reason, $metadata)` | `IconWatcherService` detects a directory change |

Three built-in listeners react to these events:

- `AutoSeedIconsOnRegistration`, seeds icons on `registered` events. Disabled by default; enable via `ichava.database.auto_seed = true`. Only seeds if the package has zero rows in the database.
- `AutoUnseedOnUnregistration`, removes a package's icon rows plus orphaned terms on `unregistered`.
- `InvalidateIconCache`, flushes icon caches on `IconCacheEvent`. Guarded by `IchavaLifecycleManager`: only runs after the four-stage lifecycle (`uninitialized` → `migrated` → `seeded` → `ready`) reaches `ready`. Setup-time events (registration) are unguarded.

## See also

- [Security model](https://github.com/ichava/documentation/blob/main/security-model.md)
- [Security threat model](https://github.com/ichava/documentation/blob/main/security-threat-model.md)
- [Troubleshooting](troubleshooting.md)
- [Documentation index](../README.md#documentation)

---

[← Docs index](../README.md#documentation)
