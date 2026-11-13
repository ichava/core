<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Pack;

use Closure;
use Simtabi\Laranail\Ichava\Data\BulkOptions;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\View\ComponentPrefixes;
use Simtabi\Laranail\Ichava\Providers\IconPackServiceProvider;

/**
 * What a pack registers, beyond its config.json: the advanced half of a pack
 * provider ({@see IconPackServiceProvider::pack()}).
 *
 * A pack that keeps everything in config.json never sees this. One that
 * computes rules, ships a second set or an extra Blade component says so here:
 *
 * ```php
 * protected function pack(PackSpec $pack): void
 * {
 *     $pack
 *         ->set(fn (PackBuilder $set) => $set->kindRule('illustrations/**', IconKind::Illustration))
 *         ->addSet('resources/assets/svg-brands', fn (PackBuilder $set) => $set->prefix('brand'))
 *         ->component('flag', FlagComponent::class);
 * }
 * ```
 *
 * Every set goes through PackBuilder and IconRegistry, the same path as a set
 * registered alone, and every component through ComponentPrefixes, so a pack
 * tag follows `components.short_prefix` as core's own does.
 */
final class PackSpec
{
    private ?Closure $shape = null;

    /** @var list<array{path: string, only: list<string>, shape: ?Closure, bulk: bool}> */
    private array $extraSets = [];

    /** @var array<string, class-string> name => component class */
    private array $components = [];

    private string $mainComponent = 'icon';

    private ?string $configFile = null;

    /**
     * @param string $vendorPackage the pack's `vendor/package`
     * @param string $packRoot the pack's root directory
     * @param string $svgPath the default set's directory, `resources/assets/svg`
     * @param class-string $componentClass the pack's main Blade component
     * @param string $providerClass the provider the pack's sets are attributed to
     */
    private function __construct(
        private string $vendorPackage,
        private string $packRoot,
        private string $svgPath,
        private string $componentClass,
        private string $providerClass,
    ) {}

    /**
     * @param class-string $componentClass
     */
    public static function forProvider(string $providerClass, string $vendorPackage, string $packRoot, string $componentClass): self
    {
        $packRoot = rtrim($packRoot, '/\\');

        return new self($vendorPackage, $packRoot, $packRoot . '/resources/assets/svg', $componentClass, $providerClass);
    }

    /**
     * Shape the default set, `resources/assets/svg`.
     *
     * @param Closure(PackBuilder): mixed $shape
     */
    public function set(Closure $shape): self
    {
        $this->shape = $shape;

        return $this;
    }

    /**
     * Another set in the pack, at a path relative to the pack root, holding its own config.json.
     *
     * @param (Closure(PackBuilder): mixed)|null $shape
     */
    public function addSet(string $relativePath, ?Closure $shape = null): self
    {
        $this->extraSets[] = ['path' => $this->packRoot . '/' . ltrim($relativePath, '/\\'), 'only' => [], 'shape' => $shape, 'bulk' => false];

        return $this;
    }

    /**
     * Every sub-directory of $relativePath holding a config.json, or only those
     * named, each shaped by $shape.
     *
     * @param list<string> $only
     * @param (Closure(PackBuilder): mixed)|null $shape
     */
    public function sets(string $relativePath, array $only = [], ?Closure $shape = null): self
    {
        $this->extraSets[] = ['path' => $this->packRoot . '/' . ltrim($relativePath, '/\\'), 'only' => $only, 'shape' => $shape, 'bulk' => true];

        return $this;
    }

    /**
     * An extra Blade component: `<x-{vendor}-{package}::{name}>`, and
     * `<x-{package}-{name}>` while the short prefix is on.
     *
     * @param class-string $componentClass
     */
    public function component(string $name, string $componentClass): self
    {
        $this->components[$name] = $componentClass;

        return $this;
    }

    /** Rename the main component's tag (default `icon`). */
    public function mainComponent(string $name): self
    {
        $this->mainComponent = $name;

        return $this;
    }

    /** Publish `config/{$name}.php` rather than `config/{slug}.php`. */
    public function configFile(string $name): self
    {
        $this->configFile = $name;

        return $this;
    }

    /** The config file name configFile() set, or null for the slug. */
    public function configFileName(): ?string
    {
        return $this->configFile;
    }

    /**
     * Register the components, then the sets: the order the registry relies on
     * to have the main component's alias when it builds the default set's metadata.
     */
    public function registerWith(IconRegistry $registry, ComponentPrefixes $prefixes): void
    {
        $alias = $prefixes->registerPackComponent($this->vendorPackage, $this->mainComponent, $this->componentClass);
        $registry->noteBladeComponent($this->providerClass, $alias);

        foreach ($this->components as $name => $componentClass) {
            $prefixes->registerPackComponent($this->vendorPackage, $name, $componentClass);
        }

        $registry->register($this->shaped(PackBuilder::fromDirectory($this->svgPath), $this->shape)->build());

        $extra = [];

        foreach ($this->extraSets as $set) {
            $builders = $set['bulk'] ? PackBuilder::discover($set['path'], $set['only']) : [PackBuilder::fromDirectory($set['path'])];

            foreach ($builders as $builder) {
                $extra[] = $this->shaped($builder, $set['shape']);
            }
        }

        if ($extra !== []) {
            $registry->registerMany($extra, new BulkOptions(name: $this->vendorPackage, failFast: true));
        }
    }

    /**
     * @param (Closure(PackBuilder): mixed)|null $shape
     */
    private function shaped(PackBuilder $builder, ?Closure $shape): PackBuilder
    {
        $builder->providedBy($this->providerClass);

        if ($shape !== null) {
            $shape($builder);
        }

        return $builder;
    }
}
