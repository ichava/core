# Query and model API

Core's PHP surface for host apps and pack authors: the `Icon` and `IconTerm` models, the browser service's filters, the registration builder, the events, and the two extension interfaces.

## `Icon` (`Simtabi\Laranail\Ichava\Models\Icon`)

### Scopes

| Scope | Use |
|---|---|
| `search($q, ?$language)` | full-text search on PostgreSQL (a chosen language goes through `FtsLanguageHelper::guardLanguage()`), LIKE elsewhere |
| `fuzzySearch($q)` | the portable LIKE search every driver supports |
| `package($name\|[...])`, `category($slug\|[...])`, `variant($slug)` | taxonomy filters |
| `ofKind(IconKind\|'icon'\|'illustration')` | icons or illustrations; an unknown kind matches nothing |
| `whereHasTag($tag\|[...])`, `whereHasKeyword($kw\|[...])` | JSON-array membership, on all four drivers |
| `recentlyModified()` | orders by `file_modified_at`, which is the file's mtime at install time, so a fresh `composer install` gives every icon the same value. To sort by when a row was last seeded, sort by `updated_at` |
| `byHash($md5)` | rows with one file hash (duplicate files) |

### Model methods

| Method | Note |
|---|---|
| `hasCategory($slug)`, `hasVariant($slug)` | taxonomy checks |
| `hasTag($tag)`, `hasKeyword($kw)` | membership checks |
| `addTag()`, `removeTag()`, `addKeyword()`, `removeKeyword()` | edit the row. **The next seed rewrites `tags` and `keywords`** from the file path, so an edit made here does not survive a re-seed |
| `attachCategory($slug)`, `attachVariant($slug)` | attach a term; seeding attaches in bulk and does not use these |
| `getMetadata($key)`, `setMetadata($key, $value)` | the `metadata` JSON column. The seeder does not write it, so it holds only what your app puts there |
| `themes()`, `hasTheme($theme)`, `themeSvgContent($theme)` | theme twins; see [Icons and illustrations](../kinds.md) |

## `IconTerm` (`Simtabi\Laranail\Ichava\Models\IconTerm`)

| Member | Use |
|---|---|
| `inPackage($name)` | scope: one pack's terms |
| `isRoot()`, `hasChildren()` | position in the tree |
| `isAncestorOf($term)`, `isDescendantOf($term)` | ancestry, by id or model |
| `getIconCount()`, `getTotalIconCount()` | icons on this term; the total includes every descendant term |

## Browser service filters

`IconBrowserService::getIcons($filters)`, `getFilters($filters)` and `buildIconTree($filters)` share one filter path, so the grid, the facet counts and the tree always agree:

| Filter | Meaning |
|---|---|
| `search` | narrows the other filters, never replaces them |
| `language` | PostgreSQL only: the full-text configuration for this search; `FtsLanguageHelper::selectableLanguages()` lists the choices (empty elsewhere) |
| `packages[]`, `kind` | pack and kind |
| `categories[]`, `subs[]` | categories; `subs` matches nested categories and the variants within a category, as the tree's `sub` lists them |
| `variants[]`, `tags[]`, `keywords[]` | variant terms and JSON-array membership |

With filters, each facet in `getFilters()` is counted over what the *other* filters select, and the tree is built over the selection. Without filters both are corpus-wide and cached. `getFilters()` also returns `variants_by_package` (each pack's `VariantData`, in display order) and marks the default pack with `default: true`.

## Registration builder (`RegistrationConfig`)

`IconRegistry::package('acme/icons')` returns a builder consumed by `register()`:

| Method | Use |
|---|---|
| `useIconSet(IconSetInterface $set)` | register a set you built yourself |
| `providedBy(string $providerClass)` | attribute the set to its provider (used by `IchavaPackageInterface`) |
| `withMetadata(array $metadata)` | extra registry metadata. Browser payloads whitelist their fields, so metadata never leaks into an API response by itself |

## Events

| Event | Accessors |
|---|---|
| `IconRegistrationEvent` | `isProcessing()`, `isRegistered()`, `isCompleted()`, `isFailed()`, `getException()`, `getDuration()` |
| `IconCacheEvent` | `isInvalidated()`, `getClearedKeys()`, `isRebuilt()`, `getRebuildStats()` (the last rebuild also feeds `Ichava::status()`) |

Host events (`FavoriteToggled`, `CollectionChanged`, `PreferencesChanged`) are in [Host user](../host-user.md).

## Extension interfaces

- **`IchavaPackageInterface`** is implemented by `Support\ServiceProvider`, the base every pack extends. `getPackageName()`, `getIconSetName()` and `getPackageMetadata()` read the registry, which is the one reader of `config.json`. `getPackageVersion()` reads Composer (`dev` when Composer does not know the package).
- **`IconDriverInterface`** is implemented by `Drivers\DirectoryIconDriver`, which serves icons from one directory: `setIconPath()`, then `all()`, `has()` and `render()`. Rendering goes through `SvgDriver`, so containment, the size limit and the sanitiser all apply. `SvgDriver` itself does not implement the interface, because its `render()` takes a resolved `IconData`.

## Status

`Ichava::status()` returns `Data\StatusData`: lifecycle, registry conflicts, orphaned rows (seeded packages that no registered pack owns), discovery counts, the last watcher scan, logging flags, the last cache rebuild and the render pipeline. It carries no absolute paths. `php artisan ichava::ichava-core.info status` prints it, and `info doctor` checks a working install, exiting non-zero when something it needs is missing.

---

[← Docs index](../../README.md#documentation)
