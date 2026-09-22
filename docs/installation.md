# Installation

*How-to guide.*

## Requirements

Relocated from the README, which is now a slim pointer.

- **PHP** `^8.4.1 || ^8.5`
- **Laravel** `^13`
- **A database**: SQLite, PostgreSQL, MySQL 8+ or MariaDB 10.3+ -- all four are supported and tested, and they are not equivalent. Only PostgreSQL gets indexed search; see [Database support](databases.md).

## 1. Point Composer at the repositories

**Nothing in the Ichava ecosystem is published on Packagist yet.** `composer require ichava/core`
on its own fails with "could not be found". Add the VCS repositories to your application's
`composer.json` first, including the three `laranail/*` packages core depends on, which are also
unpublished:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ichava/core" },
        { "type": "vcs", "url": "https://github.com/laranail/package-tools.git" },
        { "type": "vcs", "url": "https://github.com/laranail/console.git" },
        { "type": "vcs", "url": "https://github.com/laranail/enumerator.git" }
    ]
}
```

Composer reads `repositories` only from the root package, so a package's own entries do not carry
across to yours: every unpublished package in the tree has to be named here, core's dependencies
included.

## 2. Require the package

```bash
composer require ichava/core:^0.1
```

The `IchavaServiceProvider` registers automatically via Laravel package discovery.

## 3. Publish the config

```bash
php artisan vendor:publish --tag=ichava::core-config
```

Creates `config/ichava/core.php`. See [configuration](configuration.md) for the keys.

## 4. Run the migrations

```bash
php artisan migrate
```

Creates the `icons` and `icon_terms` tables (or your configured table prefix).

## 5. Install at least one icon pack

Core ships with no icons. The publicly available packs are:

| Pack | Icons | Add this repository |
|---|---|---|
| `ichava/icon-sets-tabler` | 6,184 | `https://github.com/ichava/icon-sets-tabler` |
| `ichava/icon-sets-flag` | 542 | `https://github.com/ichava/icon-sets-flag` |
| `ichava/icon-sets-emoji` | engine wiring only, assets pending | `https://github.com/ichava/icon-sets-emoji` |

```bash
composer require ichava/icon-sets-tabler:^0.1
```

`ichava/icon-sets-bundled` (121,314 icons) and `ichava/icon-sets-metronic` (501) are **private**: they are
part of the ecosystem but not distributable, so they are available only to accounts with access.

Or build your own with the dev-only [`ichava/icon-sets-package-scaffolder`](https://github.com/ichava/icon-sets-package-scaffolder) package:

```bash
composer require --dev ichava/icon-sets-package-scaffolder
php artisan ichava::icon-sets-package-scaffolder.make
```

See [creating icon packages](https://opensource.simtabi.com/documentation/ichava/icon-sets-package-scaffolder/creating-icon-packages).

## 6. Seed the icon database

```bash
php artisan ichava::ichava-core.database seed
```

Optional but recommended. The seeder builds the index used by the icon renderer and (if installed) the visual browser. See [database seeding](recipes/seed-the-database.md).

## 7. Use icons in Blade

```blade
<x-ichava::icon name="ichava/icon-sets-tabler::home" class="w-6 h-6" />
```

Or with the helper:

```blade
{{ ichava('ichava/icon-sets-tabler::home')->color('#4338ca')->class('w-6 h-6') }}
```

## What you get with just core

- `<x-ichava::icon>` Blade component
- `ichava()` global helper
- `ichava:*` Artisan commands
- The icon registry, scaffolder, seeder
- No HTTP routes, no Vue, no Vite

For the visual icon browser at `/ichava/icons` and the REST API, install [`ichava/icon-browser`](https://opensource.simtabi.com/documentation/ichava/icon-browser/installation) on top.

## See also

- [Configuration](configuration.md)
- [Environment variables](environment.md)
- [Database support](databases.md)
- [Database seeding](recipes/seed-the-database.md)
- [Browser package installation](https://opensource.simtabi.com/documentation/ichava/icon-browser/installation)
- [Troubleshooting](troubleshooting.md)

---

[← Docs index](../README.md#documentation)
