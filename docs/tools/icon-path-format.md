# Icon Path Format

*Reference.*

Ichava uses a standardized path format for all icons:

```
vendor/package::category/icon-name
   │      │        │       └── Icon filename (without .svg)
   │      │        └────────── Category/folder path (can be nested)
   │      └─────────────────── Package name (REQUIRED)
   └────────────────────────── Vendor name (REQUIRED)
```

### Supported Formats

Both `/` (slash) and `.` (dot) separators are supported after `::`:

```php
// Format 1: Using slash (/)
ichava('vendor/package::category/icon')

// Format 2: Using dot (.)
ichava('vendor/package::category.icon')
```

### Examples

```php
// Tabler Icons
ichava('ichava/ui-icons::ui-icons/check')     // slash format
ichava('ichava/ui-icons::ui-icons.check')     // dot format
ichava('ichava/test-icons::test-icons/check')
ichava('vendor/your-icons::category/icon-name')
ichava('vendor/your-icons::category.icon-name')
```

### Path Separators

| Separator | Usage | Example |
|-----------|-------|---------|
| `/` | Separates vendor from package | `vendor/your-icons` |
| `::` | Separates package from icon path | `ichava/ui-icons::ui-icons/check` |
| `/` or `.` | Separates category from icon name | `outline/home` or `outline.home` |

Note: The `.` (dot) format is normalized to `/` internally, so both formats work identically.

## Aliases

An alias keeps an old icon name working after a pack renames or merges an icon. The old path renders the icon it points at, through `<x-ichava::icon>`, the `ichava()` helper and `IconRegistry::has()` alike.

A pack registered from a directory ships its aliases as `aliases.json` beside `config.json`. Each key is an old path within the pack, written as it follows `::`, and each value is the path of the icon it now renders:

```json
{
    "outline/eye-open": "outline/eye",
    "outline/sidebar-left": "outline/panel-left"
}
```

With that file, `vendor/pack::outline/eye-open` renders `outline/eye.svg`. A host or provider can add aliases in code too:

```php
app(IconRegistry::class)->alias('vendor/pack', ['shapes/circle' => 'shapes/dot']);
app(IconRegistry::class)->aliases('vendor/pack'); // ['shapes/circle' => 'shapes/dot']
```

Four rules hold:

- **An alias never shadows an icon.** It is consulted only when no icon exists under the requested name, so a file added later under the old name wins.
- **Dot and slash form are the same name**, in keys, values and requests alike.
- **One hop only.** An alias pointing at itself, or at another alias, is refused at registration with an `IchavaException`, as is an `aliases.json` that is not a JSON object.
- **Aliases resolve at render time, not in search.** The database and the icon browser list the kept icon only, which is the point of merging duplicates. `IconSetInterface::get()` called directly on a set does not follow aliases either; go through `IconRegistry` or the renderer.

---

---

[← Docs index](../../README.md#documentation)
