<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\PackConfigData;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;

/**
 * Everything the registry needs to register one icon set, as one value.
 *
 * Built by {@see PackBuilder} from a config.json, from fluent calls, or both,
 * and registered by {@see IconRegistry::register()}, the one path every way in
 * funnels through (fromDirectory(), package(), the bulk registrar and
 * `custom-icons`). `$config` is the set's effective config.json: the file as
 * read, with every fluent override written into it in the same shape, so every
 * reader of the shape ({@see PackConfigData}) sees the overrides with no
 * second code path.
 */
final readonly class PackDefinition
{
    /**
     * @param string $name `vendor/package`
     * @param string $basePath the directory holding config.json; icon paths are stored relative to it
     * @param string $filesPath the directory holding the SVGs, usually `{$basePath}/files`
     * @param array<string, mixed> $config the effective config.json
     * @param bool $hasCategories whether the set files its icons under category folders
     * @param array<string, string> $aliases old path => kept path, registered beside any `aliases.json`
     * @param array<string, mixed> $extraMetadata merged over the built metadata, last
     * @param string|null $defaultVariant the variant a name without one resolves in
     */
    public function __construct(
        public string $name,
        public string $basePath,
        public string $filesPath,
        public string $prefix,
        public array $config,
        public bool $hasCategories,
        public array $aliases = [],
        public ?string $providerClass = null,
        public ?string $bladeComponent = null,
        public array $extraMetadata = [],
        public ?string $defaultVariant = null,
    ) {}

    /**
     * The class every icon renders with when the caller gives none, from
     * `config.defaults.class`; null keeps the registry's default.
     */
    public function defaultClass(): ?string
    {
        $class = $this->defaults()['class'] ?? null;

        return is_string($class) && $class !== '' ? $class : null;
    }

    /**
     * The SVG attributes every icon renders with: `config.defaults.attributes`,
     * plus `stroke-width` from `config.defaults.stroke_width` when the
     * attributes do not name one.
     *
     * @return array<string, mixed>
     */
    public function defaultAttributes(): array
    {
        $defaults = $this->defaults();
        $attributes = [];

        foreach (is_array($defaults['attributes'] ?? null) ? $defaults['attributes'] : [] as $key => $value) {
            $attributes[(string) $key] = $value;
        }

        $strokeWidth = $defaults['stroke_width'] ?? null;

        if ((is_int($strokeWidth) || is_float($strokeWidth) || is_string($strokeWidth)) && ! isset($attributes['stroke-width'])) {
            $attributes['stroke-width'] = (string) $strokeWidth;
        }

        return $attributes;
    }

    /** The icon rendered when a name is not found, from `config.defaults.fallback`. */
    public function fallback(): ?string
    {
        $fallback = $this->defaults()['fallback'] ?? null;

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * The variant names, in declared order.
     *
     * @return list<string>
     */
    public function variantNames(): array
    {
        return array_map(strval(...), array_keys(PackConfigData::variants($this->config)));
    }

    /**
     * The registry's metadata for this set.
     *
     * @param int $total SVGs counted under the files path
     * @param string|null $notedBladeComponent the alias the provider noted with the registry, used when the definition names none
     *
     * @return array<string, mixed>
     */
    public function toMetadata(int $total, ?string $notedBladeComponent = null): array
    {
        $config = $this->config;
        $declaredKind = PackConfigData::packageKind($config);

        return array_merge([
            'package_name'    => $this->name,
            'name'            => $this->value('package', 'title'),
            'description'     => $this->value('package', 'description') ?? '',
            'vendor'          => Helpers::getVendorFromPackage($this->name),
            'version'         => $this->value('package', 'version'),
            'license'         => $this->value('package', 'license') ?? 'Unknown',
            'homepage'        => $this->value('metadata', 'homepage'),
            'repository'      => $this->value('metadata', 'repository'),
            'keywords'        => $this->value('package', 'keywords') ?? [],
            'total'           => $total,
            'base_path'       => $this->basePath,
            'icon_set_name'   => $this->name,
            'provider_class'  => $this->providerClass,
            'blade_component' => $this->bladeComponent ?? $notedBladeComponent,
            'prefix'          => $this->prefix,
            // What the set's SVGs are, and the declaration behind it: KindResolver
            // tells "declared icon" (row 5) from "declared nothing" (row 6).
            'kind'           => ($declaredKind ?? IconKind::Icon)->value,
            'declared_kind'  => $declaredKind?->value,
            'category_kinds' => array_map(
                static fn (IconKind $kind): string => $kind->value,
                PackConfigData::categoryKinds($config),
            ),
            // The rest of the kind resolution order (D6 rows 2 and 3), plus what
            // doctor reports, so KindResolver and doctor never re-read config.json.
            'item_kinds' => array_map(
                static fn (IconKind $kind): string => $kind->value,
                PackConfigData::items($config),
            ),
            'kind_rules' => array_map(
                static fn (array $rule): array => ['match' => $rule['match'], 'kind' => $rule['kind']->value],
                PackConfigData::kindRules($config),
            ),
            'kind_issues'    => PackConfigData::kindIssues($config),
            'theme_variants' => PackConfigData::themeVariants($config),
            // The variant blocks as declared, for VariantData and the terms seeder.
            'variant_metadata' => array_filter(PackConfigData::variants($config), is_array(...)),
        ], $this->extraMetadata);
    }

    /** @return array<array-key, mixed> */
    private function defaults(): array
    {
        $config = $this->config['config'] ?? null;
        $defaults = is_array($config) ? ($config['defaults'] ?? null) : null;

        return is_array($defaults) ? $defaults : [];
    }

    /** One key of one config section, or null when either is absent. */
    private function value(string $section, string $key): mixed
    {
        $values = $this->config[$section] ?? null;

        return is_array($values) ? ($values[$key] ?? null) : null;
    }
}
