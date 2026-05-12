# Ichava Core

[![Latest Version](https://img.shields.io/packagist/v/ichava/core.svg)](https://packagist.org/packages/ichava/core)
[![License](https://img.shields.io/packagist/l/ichava/core.svg)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/ichava/core.svg)](https://packagist.org/packages/ichava/core)

The engine for the [Ichava Laravel icon ecosystem](https://github.com/ichava/documentation). Services, registry, seeder, scaffolder, helpers, base Blade component. No HTTP surface. Works without a JS toolchain.

## What's in core

| | |
|---|---|
| Security | DOM-based SVG sanitiser with a strict allow-list. XXE-safe (`LIBXML_NONET`, `resolveExternals=false`). Blocks `javascript:`, `vbscript:`, `file:`, all `on*` handlers. |
| Performance | Icon-level cache, SVG optimisation, indexed DB lookup, pre-built manifest for production. |
| Blade | The base `<x-ichava::icon>` component every icon pack extends. |
| Fluent API | `ichava('vendor/pkg::category/name')->color('...')->class('...')`. |
| Seeder | Queue-backed pipeline with multi-level dedup, change detection, Horizon-aware. |
| Scaffolder | `php artisan make:icon-package <Name>` bootstraps a new icon pack from a stub tree. |
| Logging | Three dedicated channels: `ichava`, `ichava-icons`, `ichava-queue`. |
| Search | PostgreSQL full-text search (recommended) or MySQL 8+. |

Zero HTTP surface. No REST endpoints, no middleware, no routes. The HTTP layer (REST API + Vue/Vite SPA) lives in the optional [`ichava/browser`](https://github.com/ichava/browser) package.

## Requirements

- PHP 8.3+
- Laravel 13+
- PostgreSQL (recommended) or MySQL 8+

## Install

```bash
composer require ichava/core
```

Publish the config and run migrations:

```bash
php artisan vendor:publish --tag=ichava-config
php artisan migrate
```

Install at least one icon pack (core ships with no icons):

```bash
composer require ichava/tabler-icons
php artisan ichava:database seed --package=ichava/tabler-icons
```

Add `ichava/browser` if you want the visual icon browser plus REST API:

```bash
composer require ichava/browser
```

A convenience metapackage (`ichava/ichava`) that pulls core + browser + a default pack is planned for a future release.

## Quick example

```blade
{{-- Generic Blade component, works with any installed pack --}}
<x-ichava::icon name="ichava/tabler-icons::home" class="w-6 h-6" />

{{-- Fluent helper --}}
{{ ichava('ichava/tabler-icons::home')->color('#FFD700')->class('w-5 h-5') }}
```

## Documentation

Full documentation lives in a dedicated repo: [`ichava/documentation`](https://github.com/ichava/documentation).

Per-topic shortcuts:

- [Installation](https://github.com/ichava/documentation/blob/main/core/installation.md)
- [Configuration](https://github.com/ichava/documentation/blob/main/core/configuration.md)
- [Environment variables](https://github.com/ichava/documentation/blob/main/core/environment.md)
- [Icon path format](https://github.com/ichava/documentation/blob/main/core/icon-path-format.md)
- [Blade components](https://github.com/ichava/documentation/blob/main/core/blade-components.md)
- [Global helper](https://github.com/ichava/documentation/blob/main/core/global-helper.md)
- [Artisan commands](https://github.com/ichava/documentation/blob/main/core/artisan-commands.md)
- [Database seeding](https://github.com/ichava/documentation/blob/main/core/database-seeding.md)
- [Custom icon sets](https://github.com/ichava/documentation/blob/main/core/custom-icon-sets.md)
- [Creating icon packages](https://github.com/ichava/documentation/blob/main/core/creating-icon-packages.md)

Cross-cutting:

- [Architecture](https://github.com/ichava/documentation/blob/main/architecture.md)
- [Security model](https://github.com/ichava/documentation/blob/main/security-model.md)
- [Troubleshooting](https://github.com/ichava/documentation/blob/main/troubleshooting.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Full ecosystem development workflow lives in the documentation repo.

## Security

Email `security@simtabi.com` privately. Do not open public issues for security problems. See [SECURITY.md](SECURITY.md).

## License

This project is licensed under the MIT License.  

© Simtabi LLC
