[← Docs index](../../README.md#documentation)

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

// Icons Bundle (70+ icon sets)
ichava('vendor/your-icons::category/icon-name')
ichava('vendor/your-icons::category.icon-name')
ichava('vendor/your-icons::category/icon-name')
ichava('vendor/your-icons::category.icon-name')
ichava('vendor/your-icons::category/icon-name')
ichava('vendor/your-icons::category/icon-name')

// Metronic Icons
ichava('vendor/your-icons::category/icon-name')
ichava('vendor/your-icons::category.icon-name')
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

---

---

[← Docs index](../../README.md#documentation)
