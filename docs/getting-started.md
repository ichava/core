[← Docs](../README.md#documentation)

# Getting started

*How-to guide.* Your first icon on the page, in Blade and in PHP, once [Installation](installation.md) is done.

## Before you start

Core ships **no icons**. It is the engine: registry, seeder, sanitiser, cache and the base Blade component. Icons arrive in packs, so a working install is core plus at least one pack:

```bash
composer require ichava/icon-sets-tabler
php artisan ichava::ichava-core.database seed --package=ichava/icon-sets-tabler
```

Until that seed runs, every lookup returns nothing and the component renders empty. That is the single most common "it does not work" — see [Troubleshooting](troubleshooting.md).

## Render an icon in Blade

The base component is generic: it works with any installed pack, and the pack is named in the path rather than in the tag.

```blade
{{-- Generic Blade component, works with any installed pack --}}
<x-ichava::icon name="ichava/icon-sets-tabler::outline/home" class="w-6 h-6" />
```

## Render an icon in PHP

The global helper returns a fluent object, so attributes chain and the icon renders when it is cast to a string:

```blade
{{ ichava('ichava/icon-sets-tabler::filled/home')->color('#FFD700')->class('w-5 h-5') }}
```

Both spellings of the path resolve to the same icon — slash and dot are normalised by one resolver:

```php
ichava('ichava/icon-sets-tabler::outline/home');   // slash form
ichava('ichava/icon-sets-tabler::outline.home');   // dot form
```

[Icon path format](tools/icon-path-format.md) covers the grammar; [Global helper](tools/global-helper.md) covers the full fluent surface.

## Add the visual browser

Core has no HTTP surface at all — no routes, no middleware, no REST endpoints. The icon browser at `/ichava/icons` and the JSON API live in a separate package:

```bash
composer require ichava/browser
```

See [`ichava/browser`](https://opensource.simtabi.com/documentation/ichava/browser/installation) for its installation and configuration.

## Scaffold your own pack

Creating a pack is not core's job either. The generator is a dev-only package:

```bash
composer require --dev ichava/icon-sets-package-scaffolder
php artisan ichava::icon-sets-package-scaffolder.make
```

It replaced `ichava::ichava-core.make:icon-package`, which core shipped up to `0.2.7`. Full guidance is in the [scaffolder's documentation](https://opensource.simtabi.com/documentation/ichava/icon-sets-package-scaffolder/creating-icon-packages).

## See also

- [Configuration](configuration.md)
- [Use an icon pack](recipes/use-an-icon-pack.md)
- [Blade components](tools/blade-components.md)
- [Artisan commands](tools/artisan-commands.md)

---

[← Docs index](../README.md#documentation)
