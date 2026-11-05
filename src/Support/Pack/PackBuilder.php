<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Pack;

use Closure;
use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Support\KindGlob;
use Simtabi\Laranail\Ichava\Data\PackDefinition;
use Simtabi\Laranail\Ichava\Support\PackConfigData;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * Builds a {@see PackDefinition}: from a set's config.json, from code, or both.
 *
 * Every method writes one key that a reader already consumes, into the same
 * shape config.json uses, so an override reaches KindResolver, themeTwin(),
 * VariantData and the seeders by the path the file's own value takes. The
 * merge contract, the same for every key:
 *
 * | What                                         | Rule                                          |
 * |----------------------------------------------|-----------------------------------------------|
 * | scalars (prefix, about, kind)                | the fluent value replaces config.json's       |
 * | maps (variants, categories, items, aliases)  | merged per key; the fluent entry wins its key |
 * | kind rules                                   | fluent rules first, then config.json's        |
 *
 * ```php
 * app(IconRegistry::class)->register(
 *     PackBuilder::make('acme/social', storage_path('icons/social/files'))
 *         ->prefix('social')
 *         ->variant('outline', ['fill' => 'none', 'stroke' => 'currentColor'])
 *         ->build(),
 * );
 * ```
 *
 * @see IconRegistry::register()
 */
final class PackBuilder
{
    /** The `about()` keys, and the config.json section each lives in. */
    private const array ABOUT_KEYS = [
        'title'       => 'package',
        'description' => 'package',
        'version'     => 'package',
        'license'     => 'package',
        'keywords'    => 'package',
        'homepage'    => 'metadata',
        'repository'  => 'metadata',
    ];

    /** @var list<array{match: string, kind: string}> fluent kind rules, prepended at build() */
    private array $kindRules = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, mixed> */
    private array $extraMetadata = [];

    private ?bool $categories = null;

    private ?string $providerClass = null;

    private ?string $bladeComponent = null;

    /**
     * @param array<string, mixed> $config the effective config.json, written into by each method
     */
    private function __construct(
        private string $name,
        private string $basePath,
        private string $filesPath,
        private array $config,
    ) {}

    /**
     * A set described by the config.json in $path; its SVGs are under `{$path}/files`.
     *
     * @throws IchavaException when config.json is missing, malformed, or names no package or prefix
     */
    public static function fromDirectory(string $path): self
    {
        $config = Helpers::loadConfigJson($path);

        return new self(self::requiredString($config, 'package', 'name', $path), $path, $path . '/files', $config)
            ->prefix(self::requiredString($config, 'config', 'icon_prefix', $path));
    }

    /**
     * A set with no config.json. $filesPath holds the SVGs; icon paths are stored
     * relative to its parent when it is named `files`, the layout a config.json
     * set uses, and relative to $filesPath itself otherwise. The prefix defaults
     * to the package half of the name.
     */
    public static function make(string $vendorPackage, string $filesPath): self
    {
        $filesPath = rtrim($filesPath, '/\\');
        $basePath = basename($filesPath) === 'files' ? dirname($filesPath) : $filesPath;

        return new self($vendorPackage, $basePath, $filesPath, ['package' => ['name' => $vendorPackage]])
            ->prefix(Str::afterLast($vendorPackage, '/'));
    }

    /** The set's `vendor/package` name, replacing the one config.json declares. */
    public function named(string $vendorPackage): self
    {
        $this->name = $vendorPackage;
        $this->config['package'] = [...$this->section('package'), 'name' => $vendorPackage];

        return $this;
    }

    /** The prefix icons are written with, `{prefix}::name`. */
    public function prefix(string $prefix): self
    {
        $this->config['config'] = [...$this->section('config'), 'icon_prefix' => $prefix];

        return $this;
    }

    /**
     * One descriptive field: title, description, version, license, keywords (a
     * list), homepage or repository.
     *
     * @param string|list<string> $value
     *
     * @throws IchavaException for any other key
     */
    public function about(string $key, string|array $value): self
    {
        $section = self::ABOUT_KEYS[$key] ?? throw IchavaException::invalidConfiguration(
            sprintf("unknown pack field '%s'; expected one of %s", $key, implode(', ', array_keys(self::ABOUT_KEYS))),
        );

        $this->config[$section] = [...$this->section($section), $key => $value];

        return $this;
    }

    /**
     * A variant, merged over the one config.json declares under the same name.
     *
     * @param array<string, mixed> $attributes SVG attributes the variant applies, e.g. `['fill' => 'none']`
     */
    public function variant(string $name, array $attributes = [], ?string $description = null, ?string $preview = null): self
    {
        $variants = $this->data('variants');
        $existing = is_array($variants[$name] ?? null) ? $variants[$name] : [];
        $existingAttributes = is_array($existing['attributes'] ?? null) ? $existing['attributes'] : [];

        $variants[$name] = array_filter([
            ...$existing,
            'name'         => $existing['name'] ?? Str::headline($name),
            'attributes'   => [...$existingAttributes, ...$attributes],
            'description'  => $description ?? ($existing['description'] ?? null),
            'preview_icon' => $preview ?? ($existing['preview_icon'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);

        return $this->setData('variants', $variants);
    }

    /** Whether icons are filed under category folders; config.json decides when this is not called. */
    public function categories(bool $enabled = true): self
    {
        $this->categories = $enabled;

        return $this;
    }

    /** The kind of the whole set (D6 row 5). */
    public function kind(IconKind $kind): self
    {
        $this->config['package'] = [...$this->section('package'), 'kind' => $kind->value];

        return $this;
    }

    /** The kind of one category (D6 row 4). */
    public function categoryKind(string $slug, IconKind $kind): self
    {
        return $this->category($slug, ['kind' => $kind->value]);
    }

    /**
     * A kind rule, `**`, `*` and `?` globs over item paths (D6 row 3). Rules added
     * here are tried before config.json's, in the order they are added.
     *
     * @throws IchavaException when the glob is not a valid KindGlob
     */
    public function kindRule(string $glob, IconKind $kind): self
    {
        if (! KindGlob::isValid($glob)) {
            throw IchavaException::invalidConfiguration("kind rule '{$glob}' is not a valid glob");
        }

        $this->kindRules[] = ['match' => $glob, 'kind' => $kind->value];

        return $this;
    }

    /** The kind of one item, by its path under `files/` without `.svg` (D6 row 2). */
    public function itemKind(string $itemPath, IconKind $kind): self
    {
        $items = $this->data('items');
        $item = is_array($items[$itemPath] ?? null) ? $items[$itemPath] : [];
        $items[$itemPath] = [...$item, 'kind' => $kind->value];

        return $this->setData('items', $items);
    }

    /**
     * A theme twin: in $categorySlug, `name{$suffix}.svg` is the $theme form of `name.svg`.
     */
    public function themeTwin(string $categorySlug, string $theme, string $suffix): self
    {
        $categories = $this->data('categories');
        $category = is_array($categories[$categorySlug] ?? null) ? $categories[$categorySlug] : [];
        $twins = is_array($category['theme_variants'] ?? null) ? $category['theme_variants'] : [];

        return $this->category($categorySlug, ['theme_variants' => [...$twins, $theme => $suffix]]);
    }

    /** An old icon path that now renders $keptPath; IconAliasMap's rules apply at registration. */
    public function alias(string $oldPath, string $keptPath): self
    {
        $this->aliases[$oldPath] = $keptPath;

        return $this;
    }

    /**
     * @param array<string, string> $map old path => kept path
     */
    public function aliases(array $map): self
    {
        foreach ($map as $oldPath => $keptPath) {
            $this->alias($oldPath, $keptPath);
        }

        return $this;
    }

    /** The service provider the set is attributed to. */
    public function providedBy(string $providerClass): self
    {
        $this->providerClass = $providerClass;

        return $this;
    }

    /** The Blade alias the set renders through, recorded for conflict detection; registering it is the provider's. */
    public function component(string $bladeAlias): self
    {
        $this->bladeComponent = $bladeAlias;

        return $this;
    }

    /**
     * Registry metadata merged over the built metadata, last.
     *
     * @param array<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->extraMetadata = [...$this->extraMetadata, ...$metadata];

        return $this;
    }

    /**
     * Apply $then when $condition holds.
     *
     * @param Closure(self): mixed $then
     */
    public function when(bool $condition, Closure $then): self
    {
        if ($condition) {
            $then($this);
        }

        return $this;
    }

    /**
     * The value to register.
     *
     * @throws IchavaException when the name is not `vendor/package` or the prefix is empty
     */
    public function build(): PackDefinition
    {
        if (preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $this->name) !== 1) {
            throw IchavaException::invalidConfiguration("pack name '{$this->name}' must be vendor/package");
        }

        $prefix = $this->section('config')['icon_prefix'] ?? null;

        if (! is_string($prefix) || $prefix === '') {
            throw IchavaException::invalidConfiguration("pack '{$this->name}' needs a non-empty prefix");
        }

        $config = $this->config;

        if ($this->kindRules !== []) {
            $kinds = is_array($config['metadata']['data']['kinds'] ?? null) ? $config['metadata']['data']['kinds'] : [];
            $fileRules = is_array($kinds['rules'] ?? null) ? array_values($kinds['rules']) : [];
            $config = $this->withData($config, 'kinds', [...$kinds, 'rules' => [...$this->kindRules, ...$fileRules]]);
        }

        return new PackDefinition(
            name: $this->name,
            basePath: $this->basePath,
            filesPath: $this->filesPath,
            prefix: $prefix,
            config: $config,
            hasCategories: $this->categories ?? PackConfigData::categories($config) !== [],
            aliases: $this->aliases,
            providerClass: $this->providerClass,
            bladeComponent: $this->bladeComponent,
            extraMetadata: $this->extraMetadata,
        );
    }

    /**
     * @param array<string, mixed> $values merged into the category's block
     */
    private function category(string $slug, array $values): self
    {
        $categories = $this->data('categories');
        $category = is_array($categories[$slug] ?? null) ? $categories[$slug] : [];
        $categories[$slug] = [...$category, ...$values];

        return $this->setData('categories', $categories);
    }

    /** @return array<array-key, mixed> */
    private function section(string $key): array
    {
        $section = $this->config[$key] ?? null;

        return is_array($section) ? $section : [];
    }

    /** @return array<array-key, mixed> one `metadata.data` block */
    private function data(string $key): array
    {
        $block = $this->config['metadata']['data'][$key] ?? null;

        return is_array($block) ? $block : [];
    }

    /** @param array<array-key, mixed> $value */
    private function setData(string $key, array $value): self
    {
        $this->config = $this->withData($this->config, $key, $value);

        return $this;
    }

    /**
     * @param array<string, mixed>    $config
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function withData(array $config, string $key, array $value): array
    {
        $metadata = is_array($config['metadata'] ?? null) ? $config['metadata'] : [];
        $data = is_array($metadata['data'] ?? null) ? $metadata['data'] : [];
        $data[$key] = $value;
        $metadata['data'] = $data;
        $config['metadata'] = $metadata;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws IchavaException
     */
    private static function requiredString(array $config, string $section, string $key, string $path): string
    {
        $values = $config[$section] ?? null;
        $value = is_array($values) ? ($values[$key] ?? null) : null;

        if (! is_string($value)) {
            throw IchavaException::invalidConfig(
                "config.json must declare {$section}.{$key} as a string",
                Str::finish($path, '/') . 'config.json',
            );
        }

        return $value;
    }
}
