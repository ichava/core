# Icons and illustrations

Every seeded SVG has a kind, `icon` or `illustration`, declared by its pack and carried from `config.json` to the database row, the query surface and the renderer.

## Why kind is separate from colour

Kind says what an asset is; colour says how it is painted. Emoji and flags are multicolour **icons**. An illustration is a scene with its own aspect ratio, size budget and rendering defaults. The two facts stay independent, so a multicolour icon is never treated as an illustration.

The values mirror `@ichava/js-ui-kernel`'s `IconKind` union, and core holds them as the backed enum `Simtabi\Laranail\Ichava\Enums\IconKind` (`Icon = 'icon'`, `Illustration = 'illustration'`).

## Declaring kind in a pack

A pack declares kind at four levels: per item, by ordered glob rule, per category and pack-wide. No file is inspected to decide it.

```json
{
    "package": { "name": "acme/scenes", "kind": "icon" },
    "metadata": { "data": {
        "categories": {
            "illustrations": { "name": "Illustrations", "kind": "illustration", "theme_variants": { "dark": "-dark" } }
        },
        "kinds": { "rules": [
            { "match": "spot/tiny-*", "kind": "icon", "note": "spot glyphs are 24px" },
            { "match": "spot/**",     "kind": "illustration" }
        ] },
        "items": {
            "glyphs/hero-banner": { "kind": "illustration" }
        }
    } }
}
```

| Item path | Kind | Decided by | Rule |
|---|---|---|---|
| `glyphs/hero-banner` | illustration | `item` | -- |
| `glyphs/home` | icon | `pack` | -- |
| `spot/tiny-cat` | icon | `rule` | `spot/tiny-*` |
| `spot/big/park` | illustration | `rule` | `spot/**` |
| `illustrations/office` | illustration | `category` | `illustrations` |

The **item path** is the SVG's path under `files/`, with forward slashes, `.svg` stripped and its on-disk case: `files/illustrations/office.svg` is `illustrations/office`. It is the part of the icon id after `::`.

- `items` maps an item path to an object. Only `kind` is read; other keys are ignored, so per-item metadata can grow later.
- `kinds.rules` is an array, not a map, because the first matching rule wins and JSON object order is not guaranteed. Put specific rules before broad ones. A rule is `{ "match", "kind", "note"? }`.
- Every key is also read from the top level of `config.json` (`items`, `kinds.rules`, `categories`) when the documented `metadata.data` shape does not hold it.
- **A mixed pack is derived, never declared.** There is no `"kind": "mixed"`: a pack is mixed when its rows hold both kinds, and `package.kind` stays its default.
- A pack written before these keys existed loads exactly as before: every item is an icon.

### Resolution order

The first row that yields a valid kind wins:

| Row | Source | Where |
|---|---|---|
| 1 | `host` | the app's `kinds.overrides` (below) |
| 2 | `item` | the pack's `items.<item path>.kind` |
| 3 | `rule` | the first of the pack's `kinds.rules[]` whose `match` matches |
| 4 | `category` | the pack's `categories.<slug>.kind`, the slug being the item path's first folder |
| 5 | `pack` | `package.kind` |
| 6 | `default` | `icon` |

A value that names no kind (case and surrounding spaces are forgiven) is ignored and the next row applies, so a typo never invents a kind. Doctor reports it as `kind.invalid_value`.

### Glob syntax

Rules and host overrides match the whole item path, case-sensitively:

| Token | Matches |
|---|---|
| `*` | zero or more characters, never `/` |
| `?` | exactly one character, never `/` |
| `**` | zero or more whole segments; only as a whole segment (`a/**/b`, `**/x`, `a/**`) |
| anything else | itself |

`[ ] { } \` are reserved. An empty pattern, a leading `/`, a `..` or empty segment, or `**` inside a segment (`a**`) make the pattern invalid: it matches nothing and doctor reports `kind.invalid_glob`. icon-sets-sync implements the same syntax.

### Host overrides

An app decides an item's kind ahead of everything the pack says, in `config/ichava-core.php`:

```php
'kinds' => [
    'overrides' => [
        'ichava/icon-sets-metronic::illustrations/**' => 'icon',
        'acme/scenes::spot/hero'                      => 'illustration',
        '*::scenes/**'                                => 'illustration',
    ],
],
```

A key is `<package>::<pattern>`, where the package is an exact composer name or `*`. Exact keys (a named package and a pattern with no `*` or `?`) are checked first, then glob keys in the order written. A key without `::` or with an invalid pattern is ignored and reported as `kind.override_invalid`. Values are plain strings, so `config:cache` stays safe.

Kind is stored when an icon is seeded, so a changed override takes effect on the next seed. Until then doctor's `kind.drift` lists the rows that differ and the command to run.

### Which row decided

Every row stores its provenance beside its kind: `kind_source` (`host`, `item`, `rule`, `category`, `pack` or `default`) and `kind_rule` (the override key, the rule's `match` or the category slug; null otherwise). Rows seeded before provenance existed read `default` until the next seed re-files them.

```php
use Simtabi\Laranail\Ichava\Facades\IchavaFacade as Ichava;

Ichava::kindOf('acme/scenes::spot/big/park');          // IconKind::Illustration
Ichava::kindResolution('acme/scenes::spot/big/park');  // KindResolution{kind, source: KindSource::Rule, rule: 'spot/**'}
$icon->kindSource();                                    // KindSource::Rule
```

`kind_source` is served on every API icon. `kind_rule` is not, because it can carry a host config pattern; it appears in doctor, in `artisan about` (in aggregate) and in `info icons --format=json`.

### Suggestions (never applied)

`php artisan ichava::ichava-core.info doctor --suggest-kinds` reads every SVG and suggests `illustration` for an item that resolved `icon` from the default row when three or more of these fire, at least one of them `bytes` or `elements`:

| Signal | Fires when |
|---|---|
| `aspect` | viewBox width / height is outside [0.8, 1.25] |
| `palette` | 4 or more distinct literal paint colours |
| `bytes` | more than 20,000 bytes |
| `elements` | more than 150 elements |
| `canvas` | the larger viewBox side is more than 256 |

`--suggest-kinds=all` considers items decided by `package.kind` too. Items decided by a host override, an item key, a rule or a category are human decisions and are never second-guessed. When at least 5 items share a folder and 80% of them are suggested, one rule (`<folder>/**`) is suggested instead. Each record carries an `accept` fragment to paste into `config.json`; nothing writes a suggestion anywhere on its own.

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

`SeedIconsJob` and the file watcher both classify through `Support\IconClassifier`, which resolves through `Support\KindResolver`, so they cannot disagree about a file. Kind, provenance and twins are part of change detection: a pack that starts declaring a category as illustrations, or a host override added for an item, re-files unchanged files on the next seed, without `--force`.

The `kind` column defaults to `icon` and `kind_source` to `default`, which is also the backfill for rows seeded before either existed.

## A set that moved to another pack

A pack that takes over a set from another declares it, and the old pack marks the moved categories deprecated:

```json
"package": {
  "name": "ichava/icon-sets-openmoji",
  "replaces": [ { "package": "ichava/icon-sets-emoji", "paths": ["openmoji-color/**"], "since": "0.1.0" } ]
}
```

With the replacing pack installed, `ichava/icon-sets-emoji::openmoji-color/1f600` resolves to `ichava/icon-sets-openmoji::openmoji-color/1f600` wherever an id is parsed (Blade, the helper, render and `kindOf`), and `Ichava::canonicalId()` returns the new id for callers that look ids up themselves. Each rewritten id is logged once per process to the `ichava` channel as `[DEPRECATED]`. The old pack's moved categories are not seeded, so nothing is counted twice. Without the replacing pack the old id resolves in the old pack, as before.

Doctor's `alias.deprecated_ids` scans `app/`, `resources/views`, `resources/js` and `config/` for old ids and reports each `file:line` (up to 50); `alias.replacement_missing` warns when a deprecated set is installed and its replacement is not.

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
| `getIcons(['kind' => ['icon', 'illustration']])` | any of the kinds (OR), duplicates collapsed; a list naming no valid kind matches nothing |
| `getFilters()['kinds']` | every kind, with its label and count, zero included, counted over every other filter |
| `getFilters()['packages'][n]['by_kind']` | `{"icon": n, "illustration": m}` for that pack, within the current filters |
| `getStatistics()['by_kind']` | `{"icon": n, "illustration": m}` |
| `getStatistics()['by_package_kind']` | the same per pack; `[]` when nothing is seeded |
| `buildIconTree()` | `kind` on each pack and each category |
| `transformIcon()` | `kind`, `kind_source` and `themes` per icon |

Every kind is listed even at zero. Whether to show an empty kind is the client's decision.

## Rendering illustrations

- **Aspect.** `<x-ichava::icon size="lg">` squares an icon, which is drawn on a square grid. An illustration keeps its viewBox aspect: the preset sets the width and the height follows. An explicit `lock-aspect-ratio` attribute still wins either way.
- **File size.** `kinds.max_file_size.<kind>` sets the ceiling per kind (illustrations default to 5 MB); a kind with no entry uses `max_file_size`. It is checked when an icon is seeded and again before it is served. The sanitiser applies its own ceiling to every kind, `svg.max_bytes` (4,500,000 bytes) and `svg.max_elements` (30,000), so in practice an illustration is limited to 4,500,000 bytes. See [SVG sanitiser policy](configuration.md#svg-sanitiser-policy).
- **Colour.** The sanitiser keeps every fill, stop and gradient of a multicolour illustration, and the opt-in monochrome conversion leaves multicolour SVGs alone.

## Doctor and `about`

`php artisan about` adds four lines to the Ichava section: `Icons by kind` (`icon 17,301 · illustration 46`), `Default kind`, `Kind sources` (seeded rows per deciding row, non-zero only) and `Kind overrides` (how many are configured).

`php artisan ichava::ichava-core.info doctor` reports, as warnings that never fail it: `kind.invalid_value`, `kind.invalid_glob`, `kind.override_invalid`, `kind.rule_unused` (a rule or override that matches no item, including one that matches only theme twins), `kind.item_missing`, `kind.drift`, plus `kind.non_default` (which row decided each non-default kind) and, with `--suggest-kinds`, `kind.suggestions`. `--format=json` prints every check as `{ "id", "status", "count", "detail" }`.

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `ichava.ichava-core.kinds.default` | `icon` (`ICHAVA_DEFAULT_KIND`) | the kind a browser opens on |
| `ichava.ichava-core.kinds.overrides` | `[]` | host overrides, `<package \| *>::<pattern>` => kind; no env var, since it is a map |
| `ichava.ichava-core.kinds.max_file_size.illustration` | 5 MB (`ICHAVA_ILLUSTRATION_MAX_FILE_SIZE`) | size ceiling for illustrations |

---

[← Docs index](../README.md#documentation)
