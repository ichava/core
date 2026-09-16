# Changelog

All notable changes to `ichava/core` follow [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/).

## [0.2.0] - Unreleased

### Breaking

- **Artisan commands are now namespaced `ichava::ichava-core.<command>`.** Every previous name
  is retained as an alias, so nothing that exists today breaks — with one deliberate exception:
  **`make:icon-package` is gone.** It registered into Laravel's own `make:` namespace, which is
  the defect being fixed, so keeping it as an alias would have kept the defect. Use
  `ichava::ichava-core.make:icon-package`, or the `ichava:make:icon-package` alias.
- **`ajaxray/ansikit` dropped from `require`.** Terminal output in `IchavaSeeder` now goes
  through the command it already held, so it honours `--quiet`, `--no-ansi` and redirection,
  which raw ANSI writes to STDOUT did not.

- **Config renamed: `config/core.php` → `config/ichava-core.php`, key `ichava.core.*` → `ichava.ichava-core.*`.** Republish with `php artisan vendor:publish --tag=ichava::ichava-core-config` and update every `config('ichava.core.*')` read, including host apps and sibling `ichava/*` packages. Published overrides at the old nested path are no longer loaded.

### Added

- **Tested support for SQLite, PostgreSQL, MySQL and MariaDB.** CI runs the suite against
  PostgreSQL 17, MySQL 8.4 and MariaDB 11.4 as service containers alongside the existing
  SQLite lane, and `tests/TestCase.php` reads `DB_CONNECTION` so the same suite targets any
  of the four locally. See `documentation/core/databases.md`.
- PHPStan static analysis (level 0) with `composer analyse` wired into CI.

### Fixed

- Nine commands registered bare generic slugs into Artisan's flat command map, where a second
  package claiming the same key replaces the first silently rather than colliding. All now
  carry vendor and slug, pinned by a test that reads the live console registry rather than
  `$signature` — the `::` name only survives because a trait writes it past Symfony's
  `validateName()`, so reading the property would pass against a registration that never took.
- The `make:icon-package` scaffolder stub emitted `#[AsCommand(name: 'ichava:update-…-icons')]`,
  so every pack generated from it reintroduced a bare name. It now emits
  `{{vendorKebab}}::{{kebabName}}-icons.update`.

- **Icon search was broken on PostgreSQL.** `FtsLanguageHelper` and `Icon::scopeFuzzySearch()`
  called `jsonb_array_elements_text()` against `tags`, `keywords` and `search_text`, which are
  `json` columns — `$table->json()` emits `json`, not `jsonb`, on PostgreSQL, and no implicit
  cast exists between the two, so the call resolved to no function at all. The migration's own
  trigger had always done this correctly. Five call sites now cast explicitly. Nothing caught it
  because the suite ran only on SQLite, which never reaches that code.
- `IconSetBuilder::all()` and `get('all')` addressed the same cache key, so one overwrote the
  other and a reader expecting a single icon payload was handed a map of them. Set keys and icon
  keys are now namespaced apart.
- `Icon::getPackageCounts()` passed a TTL as a third positional argument to a two-parameter
  `remember()`. PHP discards surplus arguments to a userland function without complaint, so the
  24-hour lifetime it asked for was never applied. `remember()` takes a `$ttl` parameter now and
  the call site passes it by name.
- SQLite test runs never enforced foreign keys, so the schema's cascading deletes went
  unexercised on the only driver that ran. `foreign_key_constraints` is on in the test harness.
- `tests/TestCase.php` set `ichava.cache_enabled`, `ichava.cache_driver` and `ichava.default_set`
  — bare keys that no code reads. Replaced with the live `ichava.ichava-core.default_set`.

- `IconSetBuilder::get()`/`all()` cached raw `IconData` objects, which Laravel 13 returns as `__PHP_Incomplete_Class` (`cache.serializable_classes` defaults to `false`). Every page refresh after the first failed with a `TypeError`. Payloads are now cached as plain arrays and rehydrated via `IconData::toArray()`/`fromArray()`; stale object entries are forgotten, rediscovered, and orphaned by a cache version bump (`v1` → `v2`).
- `<x-ichava::icon class="...">` silently dropped every Blade attribute. The compiled template calls `render()` before `withAttributes()` populates the bag, so the eagerly built SVG froze before `class` arrived (the fluent `ichava()->class()` path was unaffected). `IconComponent::render()` now returns a deferred `Htmlable` built in `toHtml()`, after the bag is set; direct callers can use the new `renderNow()` for an immediate string (note the `render()` signature change).
- Icon examples now use variant-prefixed Tabler paths (`outline/home`, `filled/home`). Bare `ichava/tabler-icons::home` does not resolve.
- `IconDiscoveryService` missing `IchavaException` import left 7 catch blocks dead.
- `SvgDriver` threw non-existent `IconRenderException`; now uses `IchavaException::renderFailed()` with chained previous exception.
- `PerformanceTimer` type-hint pointed at wrong `IchavaLogger` namespace.
- Facade imports (`DB`, `Schema`) made explicit in `IconPreferenceService`.
- `FtsLanguageHelper::getLanguages()` called an instance method statically.

### Changed

- Hardened CI workflows: concurrency groups, job timeouts, problem matchers, docs-only skip paths, and tidy composer scripts.
- CI branch triggers standardized on `main` only.

## [0.1.1] - 2026-09-02

Patch rather than a minor so that consumers on `^0.1` pick it up without a constraint change.
The additions are new public surface; nothing existing changed shape.

### Added

- `SvgProcessingService::renderFingerprint()` -- a short digest of everything that decides the
  output bytes for a given input file: the three allow-lists, the optimization level and a
  `RENDER_PIPELINE_VERSION` constant for code changes config cannot see.
- `Icon::render_version` -- `file_hash` combined with that fingerprint, identifying the exact
  bytes an icon renders to.

- `Support\SvgPolicy`, the reader for `resources/security/svg-policy.json` -- the single
  definition of what survives SVG sanitisation, shipped with the package and read by every
  runtime rather than duplicated into each one.
- 26 elements and 23 attributes the shipped policy stripped: `filter` and every `fe*`
  primitive, `pattern`, `textPath`, `switch`, `metadata`; `stroke-dasharray`,
  `stroke-dashoffset`, `stroke-miterlimit`, `transform-origin`, `patternUnits`,
  `patternTransform`, the `font-*` and text-layout attributes, `vector-effect`,
  `paint-order`, `shape-rendering`.

- An archetype rendering gate (`SvgArchetypeRegressionTest`) over real corpus icons, one per
  archetype rather than one per pack, with provenance in
  `tests/fixtures/archetypes/manifest.json`. Three rendering defects accumulated in this
  subsystem without detection because nothing rendered an icon in CI.

### Changed

- `SanitizesSvg` reads the reference attributes and the fragment pattern from the policy
  instead of its own constants. They were constants, so emptying `fragmentOnlyRefs` in the
  policy changed every client and nothing here. Not a hole, since the value check still ran,
  but it made a section of the policy decorative on the server.
- `ichava.core.svg.*` now derives from `resources/security/svg-policy.json` instead of
  being a second list of literals in `config/core.php`. A host can still publish the config
  and narrow it; what is no longer possible is the two drifting apart by accident. Note
  `config:cache` freezes the resolved arrays, so a policy edit needs `config:clear`.
- The SVG cache key is now `svg:{id}:{render_version}` rather than `svg:{id}:{file_hash}`.
  The cached value is the *processed* SVG -- ids namespaced, sizing normalised, allow-list
  applied -- so a file hash never identified it: widening the policy changes every icon while
  every file hash stays put. Existing cache entries are orphaned and repopulate on first read.

### Why

`ichava/browser` serves the SVG endpoint with `Cache-Control: immutable, max-age=31536000`,
which is a promise that a URL's bytes never change. It could not keep that promise on a URL
keyed by icon id. `render_version` is the token that makes the URL content-addressed, and it
has to cover the render policy as well as the file, or the next allow-list widening would ship
into a year of cached responses produced by the previous policy.

## [0.1.0] - 2026-08-31

First open-source release. The engine of the Ichava icon ecosystem: registry, models, seeder,
cache, SVG pipeline, Blade components, Artisan commands and the package scaffolder. Ships **zero
HTTP surface**, so `composer require ichava/core` alone gives a working headless icon engine; the
REST API and the browser SPA live in `ichava/browser`.

Earlier `v1.x` and `v2.x` tags existed on GitHub and were never published to Packagist. They are
withdrawn: the ecosystem restarts from a single `0.1.0` across every package. Nothing depended on
them.

### Added

- `IconRegistry` with runtime pack discovery, `register()`/`unregister()`, and registration
  events that drive automatic seeding and cache invalidation.
- Database layer: `Icon` and `IconTerm` models, migrations, and a chunked seeder that dispatches
  1,000-row jobs onto the `ichava-icons` queue with multi-level de-duplication and a cache lock
  against concurrent seeds.
- `<x-ichava::icon>` Blade component, the `@ichava_defs` and `@ichava_csp_nonce` directives, and a
  global helper.
- Artisan commands: `ichava:database`, `ichava:cache`, `ichava:info`, `ichava:job-status`,
  `ichava:check-icon-updates`, `ichava:watch-icon-files`, `ichava:cleanup-logs` and
  `make:icon-package`.
- `make:icon-package` scaffolder that walks `stubs/icon-package/` and substitutes mustache tokens
  in both file contents and path segments, so adding a file to the scaffold is a drop-in.
- Dedicated log channels (`ichava`, `ichava-icons`, `ichava-audit`), `AuditLogger` with a
  `SecurityAuditEvent` per record, and `SecurityNonce` for CSP.
- Path handling that accepts both `vendor/package::category/icon` and the dot form, normalised
  through a single `PathResolver`.

### Security

- **SVG served through the model is sanitised.** The `svg_content` accessor was a bare
  `File::get()`, so whatever a pack shipped reached consumers verbatim: including the
  `foreignObject`, `script` and `image` elements present in the shipped packs. The JSON path
  mattered most: `IconBrowserService` places the string into an API payload where no response
  header helps and the client injects it into the DOM. Sanitisation now happens at the accessor,
  the single point every consumer reads through, and the result is cached post-sanitisation. A
  file the sanitiser rejects yields an empty string rather than falling back to raw markup.
- **The sanitiser blocks by value rather than by name.** `href` and `xlink:href` survive as
  same-document fragments only; a `style` value keeps its declarations but any `url()` must target
  a fragment, and `behavior:`/`-moz-binding` are refused. Dangerous protocols are matched anywhere
  in a value, with whitespace and control characters collapsed first, rather than only at its
  start.
- `role` and `aria-*` survive, so an icon shipping `<title>`/`<desc>` keeps a reachable accessible
  name.

### Fixed

- **The package config now loads at the key the source reads.** The file was `config/ichava.php`
  while the package short name is `core`, so it merged at `ichava.core.ichava.*` while every read
  site used `config('ichava.*')`. All 68 returned `null` and fell through to hardcoded defaults,
  which left the entire shipped configuration inert: cache TTLs, `database.batch_size`, queue,
  logging, optimization, `custom-icons.sets`, `prefix`, the whole `security` block and every
  `ICHAVA_*` environment variable. The file is now `config/core.php` and the key is `ichava.core`.
- **Allow-lists are matched case-insensitively.** They are authored in SVG's own casing, and node
  names were compared lowercased, so `clipPath`, `linearGradient` and `radialGradient` could never
  match and were removed from every icon despite being allowed. Gradient icons lost their paint
  source and kept a dangling `fill="url(#g)"`.
- **Search no longer discards active filters.** Package, category and variant filters sat in an
  `else` branch behind `if (search)`, so selecting a package and then typing a query returned
  matches from every package.
- **Search runs on non-PostgreSQL drivers.** `scopeFuzzySearch`, documented as the fallback for
  exactly those drivers, used `jsonb_array_elements_text()`, which only PostgreSQL provides. Any
  search on SQLite or MySQL failed outright.
- `IconCacheService` referenced an undefined `$this->config` property in three methods.

### Requirements

- PHP `^8.4.1 || ^8.5`, `illuminate/support` `^13.0`.
- `laranail/package-tools`, `laranail/console` and `laranail/enumerator`, all `^0.1.0`. None is
  published on Packagist, so the package declares VCS repository entries for all three.
