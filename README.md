# ichava/core

[![Packagist](https://img.shields.io/packagist/v/ichava/core)](https://packagist.org/packages/ichava/core)
[![Tests](https://github.com/ichava/core/actions/workflows/tests.yml/badge.svg)](https://github.com/ichava/core/actions/workflows/tests.yml)
[![Static analysis](https://github.com/ichava/core/actions/workflows/code-quality.yml/badge.svg)](https://github.com/ichava/core/actions/workflows/code-quality.yml)
[![License MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> The engine for the Ichava Laravel icon ecosystem: icon registry, queue-backed seeder, DOM-based SVG sanitiser, icon cache, and the base `<x-ichava::icon>` Blade component, with zero HTTP surface.

Targets PHP `^8.4.1 || ^8.5` on Laravel `^13`, against SQLite, PostgreSQL, MySQL or MariaDB.

## Install

Core is on Packagist, but four of its dependencies (`laranail/package-tools`, `laranail/console`,
`laranail/enumerator` and `laranail/db-tools`) are not yet, so add them as VCS repositories to your
application's `composer.json` first:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/laranail/package-tools.git" },
        { "type": "vcs", "url": "https://github.com/laranail/console.git" },
        { "type": "vcs", "url": "https://github.com/laranail/enumerator.git" },
        { "type": "vcs", "url": "https://github.com/laranail/db-tools.git" }
    ]
}
```

```bash
composer require ichava/core:^0.1
```

The service provider registers itself through package discovery. [Installation](docs/installation.md) covers every step.

## Quick start guide and usage

### Getting started

Core ships no icons, so install a pack beside it, run the migrations and seed the icon index:

```bash
composer require ichava/icon-sets-tabler:^0.1
php artisan migrate
php artisan ichava::ichava-core.database seed --package=ichava/icon-sets-tabler
```

Publishing the config is optional: `php artisan vendor:publish --tag=ichava::ichava-core-config` writes
`config/ichava/ichava-core.php`, read as `config('ichava.ichava-core.*')`.

### Usage

Render an icon with the Blade component, which works with any installed pack:

```blade
<x-ichava::icon name="ichava/icon-sets-tabler::outline/home" class="w-6 h-6" />
```

Or with the fluent helper, which renders when cast to a string:

```blade
{{ ichava('ichava/icon-sets-tabler::filled/home')->color('#4338ca')->class('w-5 h-5') }}
```

See [Getting started](docs/getting-started.md) for the full walkthrough, and the [documentation index](#documentation) for everything else.

## <a name="documentation"></a>Documentation

The full documentation lives in this repository, under `docs/`, and is indexed below.

### Guides

- [Installation](docs/installation.md) — VCS repositories, config, migrations, first pack
- [Getting started](docs/getting-started.md) — your first icon, in Blade and in PHP
- [Configuration](docs/configuration.md) — every config key and what it changes
- [Environment variables](docs/environment.md) — the `ICHAVA_*` surface
- [Database support](docs/databases.md) — the four drivers, and what only PostgreSQL does
- [Icons and illustrations](docs/kinds.md) — SVG kind, theme twins, and querying by kind
- [Host user](docs/host-user.md) — the host's signed-in user, Gates, owner-keyed preferences and host events
- [Architecture](docs/architecture.md) — what core provides, topology, boot order, extension seams
- [Troubleshooting](docs/troubleshooting.md) — the failures that look like something else
- [Release](docs/release.md) — how a version is cut, and what a release carries

### Reference

- [Icon path format](docs/tools/icon-path-format.md) — the one normaliser both spellings go through
- [Blade components](docs/tools/blade-components.md) — `<x-ichava::icon>` and its attributes
- [Global helper](docs/tools/global-helper.md) — the fluent `ichava()` API
- [Artisan commands](docs/tools/artisan-commands.md) — every command core registers
- [Query and model API](docs/tools/query-api.md) — scopes, filters, events, status and the extension interfaces

### Recipes

- [Seed the database](docs/recipes/seed-the-database.md)
- [Add a custom icon set](docs/recipes/add-a-custom-icon-set.md)
- [Use an icon pack](docs/recipes/use-an-icon-pack.md)
- [Seed pack icons](docs/recipes/seed-pack-icons.md)
- [Check pack updates](docs/recipes/check-pack-updates.md)
- [Serve icons from a CDN](docs/recipes/serve-icons-from-a-cdn.md)

## Contributing & security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately through the [security policy](https://github.com/ichava/core/security/policy), never in a public issue.

## License

MIT. © Simtabi LLC. See [LICENSE](LICENSE).
