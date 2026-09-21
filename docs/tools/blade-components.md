[← Docs](../../README.md#documentation)

# Blade Components

*Reference.*

### Generic icon component. `<x-ichava::icon>`

The single component that renders icons from any registered package.

```blade
{{-- Basic usage --}}
<x-ichava::icon name="ichava/ui-icons::ui-icons/check" class="w-6 h-6" />

{{-- With accessibility --}}
<x-ichava::icon
    name="vendor/your-icons::category/icon-name"
    class="w-5 h-5"
    title="Google"
    aria="Google logo"
/>

{{-- With sizing --}}
<x-ichava::icon name="vendor/your-icons::category/check" size="lg" />

{{-- Works with every package --}}
<x-ichava::icon name="ichava/ui-icons::ui-icons/check" />
<x-ichava::icon name="vendor/your-icons::category/icon-name" />
<x-ichava::icon name="vendor/your-icons::category/icon-name" />
```

### Bundled test components

The core ships two pre-registered Blade components used internally by the icon browser and available to any host application:

| Tag | Class | Purpose |
|---|---|---|
| `<x-ichava::ichava-test-icons />` | `IchavaTestIconComponent` | Renders icons from the bundled `test-icons` set (used by the package's own integration tests) |
| `<x-ichava::ichava-ui-icons />` | `IchavaUiIconComponent` | Renders icons from the bundled `ui-icons` set (used by the icon-browser UI itself) |

Both extend `IconComponent` and accept the same `name`, `class`, `title`, `aria`, and `size` attributes as `<x-ichava::icon>`.

```blade
<x-ichava::ichava-test-icons name="check" class="w-5 h-5 text-green-500" />
<x-ichava::ichava-ui-icons   name="search" class="w-5 h-5" />
```

The `test_icons` component is auto-disabled in production, see `components.test_icons` in `config/ichava/core.php`.

### Per-package components

Each child icon package registers its own thin wrapper component for ergonomics, e.g. `<x-icon-sets-tabler-icon>`, `<x-icon-sets-bundled-icon>`, `<x-icon-sets-metronic-icon>`. They forward to the same underlying `IconComponent`, see each child package's README for the exact tag name and accepted attributes.

---

---

[← Docs index](../../README.md#documentation)
