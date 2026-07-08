# Changelog

All notable changes to `ichava/core` follow [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/).

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
