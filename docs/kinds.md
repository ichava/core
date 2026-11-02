# Icons and illustrations

Every seeded SVG has a kind, `icon` or `illustration`, declared by its pack and carried from `config.json` to the database row, the query surface and the renderer.

## Why kind is separate from colour

Kind says what an asset is; colour says how it is painted. Emoji and flags are multicolour **icons**. An illustration is a scene with its own aspect ratio, size budget and rendering defaults. The two facts stay independent, so a multicolour icon is never treated as an illustration.

The values mirror `@ichava/js-ui-kernel`'s `IconKind` union, and core holds them as the backed enum `Simtabi\Laranail\Ichava\Enums\IconKind` (`Icon = 'icon'`, `Illustration = 'illustration'`).

## Declaring kind in a pack

Kind is declared per category, with a pack-level default. No file is inspected to guess it.

```json
{
    "package": { "name": "acme/scenes", "kind": "icon" },
    "metadata": { "data": { "categories": {
        "glyphs": { "name": "Glyphs" },
        "illustrations": {
            "name": "Illustrations",
            "kind": "illustration",
            "theme_variants": { "dark": "-dark" }
        }
    } } }
}
```

Resolution order: the category's `kind`, then `package.kind`, then `icon`. A value that names no kind is ignored and the next level applies, so a typo never invents a kind. The category is the first folder under `files/`, the same folder the seeder files the icon under.

## Theme twins

A category that ships `scene.svg` and `scene-dark.svg` declares `"theme_variants": { "dark": "-dark" }`. The dark file is then a variant of the light one, not an item of its own:

- the light file's row records it in `theme_variants` (`{"dark": {"path": "...", "file_hash": "..."}}`);
- the dark file gets no row, and a row seeded before the category declared twins is removed on the next seed;
- a suffixed file with no light sibling stays an ordinary item, since there is nothing to pair it with.

```php
$icon->themes();                 // ['dark']
$icon->hasTheme('dark');         // true
$icon->themeSvgContent('dark');  // sanitised exactly as svg_content is
```

## What the seeder and the watcher write

`SeedIconsJob` and the file watcher both classify through `Support\IconClassifier`, so they cannot disagree about a file. Kind and twins are part of change detection: a pack that starts declaring a category as illustrations re-files its unchanged files on the next seed, without `--force`.

The `kind` column defaults to `icon`, which is also the backfill for rows seeded before kinds existed.

## Querying by kind

```php
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Models\Icon;

Icon::query()->ofKind(IconKind::Illustration)->get();
Icon::query()->ofKind('icon')->count();
```

`IconBrowserService` carries kind on every surface the browser reads:

| Surface | What it carries |
|---|---|
| `getIcons(['kind' => 'illustration'])` | only that kind; a value naming no kind matches nothing |
| `getFilters()['kinds']` | every kind, with its label and count, zero included |
| `getStatistics()['by_kind']` | `{"icon": n, "illustration": m}` |
| `buildIconTree()` | `kind` on each pack and each category |
| `transformIcon()` | `kind` and `themes` per icon |

Every kind is listed even at zero. Whether to show an empty kind is the client's decision.

## Rendering illustrations

- **Aspect.** `<x-ichava::icon size="lg">` squares an icon, which is drawn on a square grid. An illustration keeps its viewBox aspect: the preset sets the width and the height follows. An explicit `lock-aspect-ratio` attribute still wins either way.
- **File size.** `kinds.max_file_size.<kind>` sets the ceiling per kind (illustrations default to 5 MB); a kind with no entry uses `max_file_size`.
- **Colour.** The sanitiser keeps every fill, stop and gradient of a multicolour illustration, and the opt-in monochrome conversion leaves multicolour SVGs alone.

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `ichava.ichava-core.kinds.default` | `icon` (`ICHAVA_DEFAULT_KIND`) | the kind a browser opens on |
| `ichava.ichava-core.kinds.max_file_size.illustration` | 5 MB (`ICHAVA_ILLUSTRATION_MAX_FILE_SIZE`) | size ceiling for illustrations |

---

[← Docs index](../README.md#documentation)
