# Changelog

All notable changes to `ichava/core` follow [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Security

- **`svg_content` is now sanitised before it is served or cached (S1).** The accessor was a
  bare `File::get()`, so whatever a pack shipped reached consumers verbatim. The SVG route
  carried `nosniff` and a restrictive CSP and its comment asserted the content "has been
  sanitised" — nothing had, so those headers were the only defence and they apply to one
  route. The path that mattered more was JSON: `IconBrowserService` places `svg_content`
  directly into an API payload, where no response header helps and the client injects the
  string into the DOM. The shipped packs contain files with `foreignObject`, `script` and
  `image` elements today. Sanitisation now happens at the accessor, the single point every
  consumer reads through, and the result is cached post-sanitisation. A file the sanitiser
  rejects yields an empty string rather than falling back to raw markup.

### Fixed

- **Search no longer discards active filters (B1).** Package, category and variant filters
  sat in an `else` branch behind `if (search)`, under the comment "Apply filters only when
  not searching". Selecting a package and then typing a query returned matches from every
  package. Search now narrows the filtered set instead of replacing it.
- **Search runs on non-PostgreSQL drivers (B2).** `scopeFuzzySearch`, documented as the
  "fallback for non-PostgreSQL" and used by `scopeSearch` for exactly those drivers, was
  written with `jsonb_array_elements_text()`, which only PostgreSQL provides. Since the
  `keywords` and `tags` scopes default to enabled, any search on SQLite or MySQL failed
  with "no such table: jsonb_array_elements_text". The jsonb form is retained on
  PostgreSQL, where it matches array elements rather than the encoded JSON text.

## [2.0.1] - 2026-07-08

### Changed

- Composer metadata adopts the canonical OSS-portal URLs (products
  homepage, docs support link); `laranail/enumerator` resolves from
  Packagist (temporary `vcs` entry dropped).
- CI test matrix runs on PHP 8.4/8.5 (the 2.0 floor); Pint style fix.

## [2.0.0] - 2026-07-08

### Changed (BREAKING)

- **`laranail/package-tools ^3.0`** (from `^1.0`): the provider, support
  provider, and seed-icons job now import the
  `Simtabi\Laranail\Package\Tools` namespace; package-tools resolves from
  Packagist (the stale `vcs` repository entry was dropped).
- **PHP floor raised to `^8.4.1 || ^8.5`** (mandated by package-tools 3.0
  and laranail/console).

### Added

- **`laranail/console ^1.1`**: `BaseCommand` extends the console
  toolkit's command base — namespaced `laranail::<slug>.<command>`
  naming, capability-aware console services, and short-alias support.
- **`laranail/enumerator ^0.4`**: the `CacheDriver`, `ComponentSize`,
  and `OptimizationLevel` enums adopt `HasEnumeratorBehavior`
  (from-helpers, labels, comparisons).

## [1.1.0] - earlier

- See git history.
