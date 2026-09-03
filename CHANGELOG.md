# Changelog

All notable changes to `ichava/core` follow [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/).

## [0.1.1] - 2026-09-02

Patch rather than a minor so that consumers on `^0.1` pick it up without a constraint change.
The additions are new public surface; nothing existing changed shape.

### Added

- `SvgProcessingService::renderFingerprint()` — a short digest of everything that decides the
  output bytes for a given input file: the three allow-lists, the optimization level and a
  `RENDER_PIPELINE_VERSION` constant for code changes config cannot see.
- `Icon::render_version` — `file_hash` combined with that fingerprint, identifying the exact
  bytes an icon renders to.

- `Support\SvgPolicy`, the reader for `resources/security/svg-policy.json` — the single
  definition of what survives SVG sanitisation, shipped with the package and read by every
  runtime rather than duplicated into each one.
- 26 elements and 23 attributes the shipped policy stripped: `filter` and every `fe*`
  primitive, `pattern`, `textPath`, `switch`, `metadata`; `stroke-dasharray`,
  `stroke-dashoffset`, `stroke-miterlimit`, `transform-origin`, `patternUnits`,
  `patternTransform`, the `font-*` and text-layout attributes, `vector-effect`,
  `paint-order`, `shape-rendering`.

### Changed

- `ichava.core.svg.*` now derives from `resources/security/svg-policy.json` instead of
  being a second list of literals in `config/core.php`. A host can still publish the config
  and narrow it; what is no longer possible is the two drifting apart by accident. Note
  `config:cache` freezes the resolved arrays, so a policy edit needs `config:clear`.
- The SVG cache key is now `svg:{id}:{render_version}` rather than `svg:{id}:{file_hash}`.
  The cached value is the *processed* SVG — ids namespaced, sizing normalised, allow-list
  applied — so a file hash never identified it: widening the policy changes every icon while
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
