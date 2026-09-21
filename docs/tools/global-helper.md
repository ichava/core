[← Docs](../../README.md#documentation)

# Global Helper Function

*Reference.*

The `ichava()` helper provides a fluent API for rendering icons:

### Basic Usage

```php
// Slash format
{{ ichava('ichava/ui-icons::ui-icons/check') }}

// Dot format
{{ ichava('ichava/ui-icons::ui-icons.check') }}
```

### With Styling

```php
// CSS classes
{{ ichava('ichava/ui-icons::ui-icons/check')->class('w-6 h-6 text-blue-500') }}

// Inline styles
{{ ichava('ichava/ui-icons::ui-icons.check')->style('color: #3B82F6') }}

// Color shortcut
{{ ichava('ichava/test-icons::test-icons/check')->color('#3B82F6') }}

// Combined
{{ ichava('ichava/ui-icons::ui-icons/check')
    ->class('w-6 h-6 transition-colors')
    ->color('#3B82F6')
    ->style('transform: rotate(45deg)') }}
```

### With Accessibility

```php
{{ ichava('ichava/ui-icons::ui-icons/check')
    ->title('Home Page')
    ->aria('Navigate to homepage')
    ->role('img') }}

// Or using dot format
{{ ichava('ichava/ui-icons::ui-icons.check')
    ->title('Home Page')
    ->aria('Navigate to homepage') }}
```

### Available Methods

| Method | Description |
|--------|-------------|
| `class($class)` | Add CSS classes |
| `style($style)` | Add inline styles |
| `color($color)` | Set fill color |
| `size($size)` | Set width and height |
| `width($w)` / `height($h)` | Set dimensions |
| `title($title)` | Add SVG title |
| `aria($label)` | Set aria-label |
| `role($role)` | Set ARIA role |
| `id($id)` | Set ID attribute |
| `data($key, $value)` | Add data attribute |
| `attributes($attrs)` | Add multiple attributes |
| `defer()` | Enable deferred loading |

---

---

[← Docs index](../../README.md#documentation)
