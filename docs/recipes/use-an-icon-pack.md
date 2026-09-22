[← Docs index](../../README.md#documentation)

# Using an icon pack

*How-to guide.*

This page covers what's the same for every Ichava icon pack: install, render, fluent helper, browser preview. Pack-specific knobs (stroke width, variant defaults, brand attribution) live in each pack's own `docs/` directory.

## 1. Install a pack

Pick one (or several) and require it alongside `ichava/core`.

Nothing is on Packagist yet, so declare the VCS repositories first: see
[core installation](../installation.md) step 1.

```bash
composer require ichava/core:^0.1 ichava/icon-sets-tabler:^0.1
```

The pack's service provider registers automatically via Laravel package discovery. No manual `config/app.php` edit.

## 2. Render an icon in a Blade view

The generic component works with any installed pack:

```blade
<x-ichava:icon name="ichava/icon-sets-tabler:home" class="w-6 h-6" />
```

The `name` follows the [icon path format](../tools/icon-path-format.md): `vendor/package:category/icon`.

## 3. Render with the global helper

```blade
{{ ichava('ichava/icon-sets-tabler:home')->color('#4338ca')->class('w-6 h-6') }}
```

See [global helper](../tools/global-helper.md) for the full fluent API.

## 4. Search visually with `ichava/icon-browser`

If you also have `ichava/icon-browser` installed, the visual browser renders every icon from every installed pack:

```
http://example.com/ichava/icons
```

Filter by pack, by variant or category, by name. Copy the path with one click.

## 5. Seed pack icons into the database

Optional, but recommended for production. Seeding builds a fast lookup index used by the search API and the Blade renderer.

```bash
php artisan ichava::ichava-core.database seed --package=ichava/icon-sets-tabler
```

See [seeding pack icons](seed-pack-icons.md).

## Where to find pack-specific docs

Each pack's repository has a `docs/` directory:

- [`ichava/icon-sets-tabler/docs/`](https://github.com/ichava/icon-sets-tabler/tree/main/docs)
- [`ichava/icon-sets-bundled/docs/`](https://github.com/ichava/icon-sets-bundled/tree/main/docs)
- [`ichava/icon-sets-metronic/docs/`](https://github.com/ichava/icon-sets-metronic/tree/main/docs)

Look there for stroke widths, variant lists, attribution, and any pack-specific behaviour.

## See also

- [Icon path format](../tools/icon-path-format.md)
- [Blade components](../tools/blade-components.md)
- [Global helper](../tools/global-helper.md)
- [Seeding pack icons](seed-pack-icons.md)
- [Building your own pack](https://opensource.simtabi.com/documentation/ichava/icon-sets-package-scaffolder/recipes/build-your-own-pack)

---

[← Docs index](../../README.md#documentation)
