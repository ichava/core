# Pack registration

Three ways to register icon sets, one path underneath: every set becomes a `PackDefinition` and goes through `IconRegistry::register()`.

```text
config.json ──┐
              ├──► PackBuilder ──build()──► PackDefinition ──► IconRegistry::register()
fluent calls ─┘
```

A set registered by a pack provider, by `custom-icons` config, in bulk or from code carries the same metadata, the same kinds and the same aliases, whichever way it came in.

## The simple path: two methods

A pack whose `resources/assets/svg/config.json` says everything about it extends `Simtabi\Laranail\Ichava\Providers\IconPackServiceProvider` and names two classes:

```php
use Simtabi\Laranail\Ichava\Providers\IconPackServiceProvider;

final class IconsServiceProvider extends IconPackServiceProvider
{
    protected function constantsClass(): string
    {
        return IconsConstants::class;
    }

    protected function componentClass(): string
    {
        return IconComponent::class;
    }
}
```

The base names the package from the constants class, registers `config/{slug}.php` when the pack ships one, registers the Blade component as `<x-{vendor}-{package}::icon>` (and `<x-{package}-icon>` while the [short tag](blade-components.md#short-tag) is on), and registers `resources/assets/svg`.

`config.json`'s `config.defaults` is the one place a pack's default rendering lives: `class`, `attributes` and `stroke_width` apply to the pack's own tag, to `<x-ichava-core::icon>` and to `IconRegistry::render()` alike.

## The advanced path: `PackSpec`

The same provider can override one optional hook, `pack()`. It receives a `Simtabi\Laranail\Ichava\Support\Pack\PackSpec`:

```php
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Support\Pack\PackSpec;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;

protected function pack(PackSpec $pack): void
{
    $pack
        ->set(fn (PackBuilder $set) => $set
            ->kindRule('illustrations/**', IconKind::Illustration)
            ->themeTwin('illustrations', 'dark', '-dark')
            ->alias('shapes/circle', 'shapes/dot'))
        ->addSet('resources/assets/svg-brands', fn (PackBuilder $set) => $set->prefix('brand'))
        ->sets('resources/assets/svg/sets', only: ['fontawesome', 'bootstrap'])
        ->component('flag', FlagComponent::class);
}
```

| Method | Does |
|---|---|
| `set(Closure)` | Shapes the default set, `resources/assets/svg`, with a `PackBuilder` |
| `addSet(string $path, ?Closure)` | Registers another set, a directory under the pack root holding its own `config.json` |
| `sets(string $path, array $only = [], ?Closure)` | Registers every sub-directory of `$path` holding a `config.json`, or those named |
| `component(string $name, string $class)` | Registers `<x-{vendor}-{package}::{name}>`, and `<x-{package}-{name}>` while the short tag is on |
| `mainComponent(string $name)` | Renames the main tag from `icon` |
| `configFile(string $name)` | Registers `config/{$name}.php` instead of `config/{slug}.php` |

A pack that does not override `pack()` registers exactly as on the simple path.

## `PackBuilder`

`Simtabi\Laranail\Ichava\Support\Pack\PackBuilder` builds one set. `PackBuilder::fromDirectory($path)` starts from the `config.json` in `$path` (read by `build()`, so a broken file fails where it is registered); `PackBuilder::make('vendor/package', $filesPath)` starts from nothing.

| Method | Writes |
|---|---|
| `named('vendor/package')` | `package.name` |
| `prefix('ti')` | `config.icon_prefix` |
| `about('title' \| 'description' \| 'version' \| 'license' \| 'keywords' \| 'homepage' \| 'repository', $value)` | the descriptive fields |
| `variant('outline', ['fill' => 'none'], 'Stroked', 'outline/home')` | one variant block |
| `defaultVariant('outline')` | the variant a name without one resolves in |
| `categories(true)` | whether icons are filed under category folders |
| `kind(IconKind)` · `categoryKind($slug, IconKind)` · `kindRule($glob, IconKind)` · `itemKind($path, IconKind)` | the kind rows ([Icons and illustrations](../kinds.md)) |
| `themeTwin($category, 'dark', '-dark')` | a category's theme twins |
| `alias($old, $kept)` · `aliases([...])` | icon aliases, beside any `aliases.json` |
| `defaultClass('w-6 h-6')` · `defaultAttributes([...])` · `fallback('misc/missing')` | `config.defaults` |
| `providedBy(Provider::class)` · `component('alias')` · `metadata([...])` | attribution, the recorded Blade alias, extra registry metadata |
| `withOptions([...])` | the keys above in the array shape `custom-icons` entries use |
| `when($condition, fn (PackBuilder $b) => ...)` | a conditional chain |

The merge contract with `config.json`, the same for every key:

| What | Rule |
|---|---|
| scalars (prefix, about fields, kind) | the fluent value replaces the file's |
| maps (variants, categories, items, aliases, default attributes) | merged per key; the fluent entry wins its key |
| kind rules | fluent rules first, in the order added, then the file's (first match wins) |

From a host, with no provider and no `config.json`:

```php
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;

app(IconRegistry::class)->register(
    PackBuilder::make('acme/social', storage_path('icons/social/files'))
        ->prefix('social')
        ->variant('outline', ['fill' => 'none', 'stroke' => 'currentColor'])
        ->build(),
);
```

A pack's constants class (`JsonConfigConstants`) reads the registered definition, so it sees what the builder registered, not a second read of the file.

## Bulk registration

`IconRegistry::registerMany()` registers several definitions or builders as one run. Each set goes through `register()`, so a set registered in bulk is the same as one registered alone.

```php
use Simtabi\Laranail\Ichava\Data\BulkOptions;

$result = app(IconRegistry::class)->registerMany(
    PackBuilder::discover(resource_path('icons/vendored'), only: ['fontawesome', 'bootstrap']),
    new BulkOptions(name: 'vendored', failFast: false, log: true),
);

$result->registered; // ['acme/fontawesome', 'acme/bootstrap']
$result->failed;     // set => message, for a set that failed to build or register
```

| `BulkOptions` | Default | Meaning |
|---|---|---|
| `name` | `bulk` | the label the run's events and log line carry |
| `failFast` | `false` | rethrow the first failure; otherwise report it in `BulkResult::$failed` and register the rest |
| `log` | `false` | log one line with the counts |

A builder whose directory does not exist is reported in `BulkResult::$skipped`. One registrar id covers the run, and `IconRegistrationEvent` reports it as `started` (mode `bulk`), each set's `processing` and `registered` (or `failed`), then one `completed` whose `statistics` are `['registered' => n, 'skipped' => n, 'failed' => n]`. `AutoSeedIconsOnRegistration` acts on `registered` only, so a bulk run seeds each set once.

Three ways in:

```php
// 1. A pack, declaratively:
$pack->sets('resources/assets/svg/sets', only: ['fontawesome', 'bootstrap'],
            shape: fn (PackBuilder $set) => $set->kind(IconKind::Icon));

// 2. A PackServiceProvider, through the registrar (an empty map discovers every set):
$this->registerBulkIconSets(
    basePath: $this->svgAssetsPath('sets'),
    iconSets: ['fontawesome' => ['prefix' => 'fa'], 'bootstrap' => []],
    vendor:   'Icons bundle',
)->trackStatistics()->enableLogging();

// 3. A host, in config/ichava/ichava-core.php:
'custom-icons' => ['sets' => [
    'vendored' => ['path' => resource_path('icons/vendored'), 'bulk' => true, 'only' => []],
]],
```

`IchavaRegistrar`, behind `registerBulkIconSets()`, applies each set's values through `PackBuilder::withOptions()`; `packagePrefix()`/`iconSetSuffix()` build a prefix for a set that names none, `bladeNamespace()` records `{namespace}-{set}` as its component alias, and `statistics()` returns the run's `BulkResult` after `trackStatistics()`. `enableLogging()` logs the run whether it is called before or after `configure()`.

Core registers its own bundled test set through `registerMany()`, so the bulk path runs on every boot.

---

[← Docs index](../../README.md#documentation)
