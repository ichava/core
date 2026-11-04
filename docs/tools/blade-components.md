# Blade Components

*Reference.*

### Generic icon component. `<x-ichava-core::icon>`

The single component that renders icons from any registered package. It answers to two prefixes:

| Tag | Prefix | Notes |
|---|---|---|
| `<x-ichava-core::icon>` | `ichava-core`, vendor-scoped | Preferred in new code: the prefix names the package that ships it |
| `<x-ichava::icon>` | `ichava`, the ecosystem prefix | Unchanged and fully supported; packs register their own short names under it |

Both resolve the same class, `Simtabi\Laranail\Ichava\View\Components\IconComponent`, and render the same bytes. The examples below use the bare prefix; swap in `ichava-core` anywhere.

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

#### Dark variants. `dark`

`dark` names a dark-mode twin. Both icons render inside a `<span class="ichava-themed">`, and the dark one shows when the page is dark:

```blade
<x-ichava-core::icon name="acme/scenes::scenes/park" dark="acme/scenes::scenes/park-dark" class="w-64" />
```

Each SVG gets the caller's attributes plus `ichava-theme-light` or `ichava-theme-dark`, and a small `<style>` carrying the request's CSP nonce swaps them. `theme_variants.dark_mode` decides what "dark" means: `media` (the default, `prefers-color-scheme`) or `class` (a `.dark` ancestor, as Tailwind's class strategy sets). A `dark` that does not resolve renders the light icon alone.

With `theme_variants.auto_dark` on, an icon whose category declares a `dark` theme twin ([Theme twins](../kinds.md#theme-twins)) renders it without a `dark` attribute. It is off by default, so an icon rendered without `dark` is byte-identical to before. `IconRegistry::themeTwin($name, 'dark')` returns the twin's id, or null.

#### Accessibility role

`role` defaults to `img`. Pass another value (`presentation`, `none`), or, from the fluent helper, `->role(null)` to render no `role` attribute at all.

Size presets square an icon. An illustration keeps its viewBox aspect: `size` sets the width and the height follows. Pass `:lock-aspect-ratio="true"` or `"false"` to choose explicitly; see [Icons and illustrations](../kinds.md).

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

The `test_icons` component is auto-disabled in production, see `components.test_icons` in `config/ichava/ichava-core.php`.

### Per-package components

Each child icon package registers its own thin wrapper component for ergonomics, e.g. `<x-icon-sets-tabler-icon>`, `<x-icon-sets-flag-icon>`, `<x-icon-sets-emoji-icon>`. They forward to the same underlying `IconComponent`, see each child package's README for the exact tag name and accepted attributes.

---

---

[← Docs index](../../README.md#documentation)
