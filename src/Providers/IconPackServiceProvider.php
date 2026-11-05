<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Providers;

use Illuminate\View\Component;
use Simtabi\Laranail\Ichava\Support\Pack\PackSpec;
use Simtabi\Laranail\Ichava\View\ComponentPrefixes;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Ichava\Constants\JsonConfigConstants;
use Simtabi\Laranail\Package\Tools\Exceptions\InvalidPath;
use Simtabi\Laranail\Package\Tools\Exceptions\InvalidPackage;

/**
 * The provider an icon pack extends: everything a pack repeated, done once.
 *
 * Every pack provider in the estate did the same five things -- name the
 * package from its constants class, set the path from its own file, register
 * its config file when it ships one, register its Blade component, and hand
 * `resources/assets/svg` to the IconRegistry -- and differed only in which
 * constants class and which component class it named. So a pack now declares
 * those two and nothing else:
 *
 * ```php
 * final class IconsServiceProvider extends IconPackServiceProvider
 * {
 *     protected function constantsClass(): string
 *     {
 *         return IconsConstants::class;
 *     }
 *
 *     protected function componentClass(): string
 *     {
 *         return IconComponent::class;
 *     }
 * }
 * ```
 *
 * It also means a pack names no `laranail/package-tools` type. The
 * `configurePackage(Package $package)` signature is package-tools', so a pack
 * that wrote it had to import that package; here core writes it, and a pack
 * depends on `ichava/core` alone.
 *
 * That is the simple path. The advanced one is one optional hook, pack(),
 * which receives a {@see PackSpec}: kind rules, theme twins and aliases in
 * code, more sets (one, or every config-bearing sub-directory of a path),
 * extra Blade components and a config file under another name.
 *
 * `PackServiceProvider`, which this extends, still works for a provider that
 * writes its own `configurePackage()`.
 *
 * @see PackServiceProvider
 */
abstract class IconPackServiceProvider extends PackServiceProvider
{
    /** What the pack registers, built once in configurePackage(). */
    private ?PackSpec $spec = null;

    /**
     * Name from the constants class, path from the provider's own file
     * (`<pack>/src/Providers/` is two levels below the pack root), and the
     * config file when the pack ships `config/{slug}.php`.
     *
     * The config call is what the packs that ship one already made.
     * `PackServiceProvider::newPackage()` auto-loads `config/*.php` too, and
     * package-tools de-duplicates by name, so this is a statement of the file
     * rather than a second merge.
     *
     * @throws InvalidPath
     * @throws InvalidPackage
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->setName(($this->constantsClass())::getVendorPackage())
            ->setPathFrom(source: $this, levelsUp: 2);

        $this->spec = PackSpec::forProvider(static::class, ($this->constantsClass())::getVendorPackage(), $package->basePath(), $this->componentClass());
        $this->pack($this->spec);

        $config = $this->spec->configFileName() ?? $this->packSlug();

        if (is_file($package->basePath("config/{$config}.php"))) {
            $package->hasConfigFile($config);
        }
    }

    /**
     * Register the Blade component, then the icon directory -- the order every
     * pack used, and the one the registry relies on to have the component
     * alias recorded when it builds the pack's metadata.
     */
    public function bootingPackage(): void
    {
        $this->packSpec()->registerWith(
            $this->app->make(IconRegistry::class),
            $this->app->make(ComponentPrefixes::class),
        );
    }

    /**
     * Shape the pack beyond its config.json: kind rules, theme twins and
     * aliases computed in code, further sets, extra Blade components, or a
     * config file under another name. Optional; the default does nothing, so
     * a pack that keeps everything in config.json writes nothing here.
     */
    protected function pack(PackSpec $pack): void {}

    /**
     * The pack's constants class, which reads its `config.json`.
     *
     * @return class-string<JsonConfigConstants>
     */
    abstract protected function constantsClass(): string;

    /**
     * The pack's Blade component class, registered as `<x-{slug}-icon>`.
     *
     * @return class-string<Component>
     */
    abstract protected function componentClass(): string;

    /**
     * The pack's short name: the package half of its composer name
     * (`ichava/icon-sets-tabler` → `icon-sets-tabler`). It names the Blade
     * component and the config file. Override only if a pack's differ.
     */
    protected function packSlug(): string
    {
        return ($this->constantsClass())::getPackageName();
    }

    /** The pack's spec; built here when bootingPackage() runs before configurePackage() did. */
    private function packSpec(): PackSpec
    {
        if ($this->spec === null) {
            $this->spec = PackSpec::forProvider(static::class, ($this->constantsClass())::getVendorPackage(), $this->package->basePath(), $this->componentClass());
            $this->pack($this->spec);
        }

        return $this->spec;
    }
}
