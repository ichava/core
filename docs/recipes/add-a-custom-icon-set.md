# Registering Custom Icon Sets

*How-to guide.*

For icon sets that live **inside your own application** (rather than a distributed composer package), use the `ichava.ichava-core.custom-icons.sets` config key. Ichava loads each entry on boot and registers it with the `IconRegistry`, no service provider or package scaffolding required.

### Publish the config

```bash
php artisan vendor:publish --tag=ichava::ichava-core-config
```

Then edit `config/ichava/ichava-core.php` and add entries under `custom-icons.sets`:

```php
// config/ichava/ichava-core.php
return [
    // ...

    'custom-icons' => [
        'sets' => [

            // Social media icons: storage/icons/social/*.svg
            'social' => [
                'enabled'             => true,
                'path'                => storage_path('icons/social'),
                'prefix'              => 'social',
                'default_variant'     => null,
                'variants'            => [],
                'supports_categories' => false,
                'default_class'       => 'social-icon',
                'default_attributes'  => ['aria-hidden' => 'true'],
                'fallback'            => null,
                'blade_namespace'     => 'ichava',
                'blade_component'     => 'social',
            ],

            // Brand / logo icons (flat folder): resources/icons/brands/*.svg
            'brand' => [
                'enabled'         => true,
                'path'            => resource_path('icons/brands'),
                'prefix'          => 'brand',
                'default_class'   => 'brand-icon',
                'blade_namespace' => 'ichava',
                'blade_component' => 'brand',
            ],

            // Project icons with variants: resources/icons/custom/{outline,filled}/*.svg
            'custom' => [
                'enabled'             => false,
                'path'                => resource_path('icons/custom'),
                'prefix'              => 'custom',
                'default_variant'     => 'outline',
                'variants'            => ['outline', 'filled'],
                'supports_categories' => true,
                'default_class'       => 'custom-icon',
                'blade_namespace'     => 'ichava',
                'blade_component'     => 'custom',
            ],

            // Category-based icons: storage/icons/app/{navigation,actions,status}/*.svg
            'app' => [
                'enabled'             => false,
                'path'                => storage_path('icons/app'),
                'prefix'              => 'app',
                'supports_categories' => true,
                'default_class'       => 'app-icon',
                'blade_namespace'     => 'ichava',
                'blade_component'     => 'app',
            ],

        ],
    ],
];
```

### Usage in Blade

A directory with no `config.json` registers as `app/{entry key}` (or the entry's `package`), and its icons are addressed by that name, like any pack's:

```blade
<x-ichava::icon name="app/social::twitter" />
<x-ichava::icon name="app/brand::partner-a" class="w-8 h-8" />
<x-ichava::icon name="app/custom::filled/home" />
<x-ichava::icon name="app/app::navigation/menu" />
```

A directory holding a `config.json` registers under the `package.name` it declares, with the entry's keys applied over the file.

### Field reference

| Key | Required | Description |
|---|---|---|
| `enabled` |. | `false` skips the entry (default `true`) |
| `path` | ✅ | Absolute path to the SVG directory, or to a directory holding a `config.json` |
| `package` |. | The `vendor/package` a directory with no `config.json` registers as (default `app/{entry key}`) |
| `bulk` |. | `true` registers every sub-directory of `path` that holds a `config.json` |
| `only` |. | With `bulk`, the sub-directory names to register (default all) |
| `prefix` |. | The set's prefix (default the package half of its name) |
| `default_variant` |. | Variant used when none specified (e.g. `'outline'`) |
| `variants` |. | Array of supported subdirectory variants |
| `supports_categories` |. | `true` if icons are nested by category folders |
| `default_class` |. | CSS class auto-applied to all icons from this set |
| `default_attributes` |. | Map of attributes auto-merged onto every SVG |
| `fallback` |. | Per-set fallback icon name if lookup fails |
| `blade_component` |. | The Blade alias recorded for the set, `{blade_namespace}-{blade_component}`, so the registry can report a clash |
| `blade_namespace` |. | The prefix of that recorded alias |

Every key is applied through `PackBuilder::withOptions()`, the same path a pack takes; see [Pack registration](../tools/pack-registration.md).

### Difference from custom *packages*

| Scenario | Use |
|---|---|
| Icons live in your app, no redistribution | **Custom icon sets** (this section) |
| Icons will ship as a composer package to other apps | **Custom icon packages** (next section) |

---

---

[← Docs index](../../README.md#documentation)
