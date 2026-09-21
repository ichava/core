# ichava/core

[![Tests](https://github.com/ichava/core/actions/workflows/tests.yml/badge.svg)](https://github.com/ichava/core/actions/workflows/tests.yml)
[![Code Quality](https://github.com/ichava/core/actions/workflows/code-quality.yml/badge.svg)](https://github.com/ichava/core/actions/workflows/code-quality.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> The engine for the Ichava Laravel icon ecosystem — icon registry, queue-backed seeder, DOM-based SVG sanitiser, icon cache, and the base `<x-ichava::icon>` Blade component, with zero HTTP surface.

This package is not published to Packagist, so there is no registry-version badge to show. Targets PHP `^8.4.1 || ^8.5` on Laravel `^13`, against SQLite, PostgreSQL, MySQL or MariaDB.

## Install

```bash
composer require ichava/core
```

Core and its `laranail/*` dependencies are unpublished, so your application's `composer.json` needs VCS repository entries before that command resolves — [Installation](docs/installation.md) gives the exact block, then covers publishing the config, migrating, and seeding your first pack.

## <a name="documentation"></a>Documentation

Full documentation is at **[opensource.simtabi.com/documentation/ichava/core](https://opensource.simtabi.com/documentation/ichava/core/)**.

### Guides

- [Installation](docs/installation.md) — VCS repositories, config, migrations, first pack
- [Getting started](docs/getting-started.md) — your first icon, in Blade and in PHP
- [Configuration](docs/configuration.md) — every config key and what it changes
- [Environment variables](docs/environment.md) — the `ICHAVA_*` surface
- [Database support](docs/databases.md) — the four drivers, and what only PostgreSQL does
- [Architecture](docs/architecture.md) — what core provides, topology, boot order, extension seams
- [Troubleshooting](docs/troubleshooting.md) — the failures that look like something else
- [Release](docs/release.md) — how a version is cut, and what a release carries

### Reference

- [Icon path format](docs/tools/icon-path-format.md) — the one normaliser both spellings go through
- [Blade components](docs/tools/blade-components.md) — `<x-ichava::icon>` and its attributes
- [Global helper](docs/tools/global-helper.md) — the fluent `ichava()` API
- [Artisan commands](docs/tools/artisan-commands.md) — every command core registers

### Recipes

- [Seed the database](docs/recipes/seed-the-database.md)
- [Add a custom icon set](docs/recipes/add-a-custom-icon-set.md)
- [Use an icon pack](docs/recipes/use-an-icon-pack.md)
- [Seed pack icons](docs/recipes/seed-pack-icons.md)

## Contributing & security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately through [SECURITY.md](SECURITY.md) — never in a public issue.

## License

MIT. © Simtabi LLC. See [LICENSE](LICENSE).
