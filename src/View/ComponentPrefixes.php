<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\View;

use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Core's Blade prefixes, and the tags a pack registers under the same rules.
 *
 * `ichava-core` is canonical: always registered (unless `components.enabled`
 * is false), and the one the docs lead with, `<x-ichava-core::icon>`. The short
 * prefix, `components.short_prefix` (default `ichava`, so `<x-ichava::icon>`),
 * is a convenience beside it; null or '' turns it off. It is validated against
 * Blade's tag parser at boot, which admits `[\w\-\:\.]` and spends `::` between
 * prefix and component, so a slash or `::` in a prefix would truncate the tag
 * silently rather than fail.
 *
 * A pack follows the same two tiers (C-08): `{vendor}-{package}::{name}` always,
 * and `{package}-{name}` while the short prefix is on.
 */
final readonly class ComponentPrefixes
{
    public const string CANONICAL = 'ichava-core';

    public const string COMPONENTS_NAMESPACE = 'Simtabi\\Laranail\\Ichava\\View\\Components';

    private const string VALID_PREFIX = '/^[a-z][a-z0-9-]*$/';

    public function __construct(
        private ConfigRepository $config,
        private BladeCompiler $blade,
    ) {}

    /** Whether core registers any Blade tag (`components.enabled`). */
    public function enabled(): bool
    {
        return $this->config->get('ichava.ichava-core.components.enabled', true) !== false;
    }

    /**
     * Whether the bundled test-icons component should be registered:
     * `components.test_icons` (default: on when `APP_ENV` is `local`), and
     * never while `components.enabled` is false. Core ships no such
     * component; ichava/icon-browser registers it and reads this.
     */
    public function testIconsEnabled(): bool
    {
        return $this->enabled() && (bool) $this->config->get('ichava.ichava-core.components.test_icons', false);
    }

    /**
     * The short prefix, or null when it is off.
     *
     * @throws IchavaException when it is not a prefix Blade can parse, or is the canonical one
     */
    public function shortPrefix(): ?string
    {
        $prefix = $this->config->get('ichava.ichava-core.components.short_prefix', 'ichava');

        if ($prefix === null || $prefix === '' || $prefix === false) {
            return null;
        }

        if (! is_string($prefix) || preg_match(self::VALID_PREFIX, $prefix) !== 1 || $prefix === self::CANONICAL) {
            throw IchavaException::invalidConfiguration(sprintf(
                "components.short_prefix must be lowercase letters, digits and hyphens, start with a letter, and differ from '%s'; got %s",
                self::CANONICAL,
                is_string($prefix) ? "'{$prefix}'" : get_debug_type($prefix),
            ));
        }

        return $prefix;
    }

    /**
     * The prefixes core registers, canonical first.
     *
     * @return list<string>
     */
    public function registered(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $short = $this->shortPrefix();

        return $short === null ? [self::CANONICAL] : [self::CANONICAL, $short];
    }

    /**
     * Register core's component namespace and `{prefix}::icon` for each prefix.
     *
     * @return list<string> the prefixes registered, canonical first
     */
    public function register(): array
    {
        $prefixes = $this->registered();

        foreach ($prefixes as $prefix) {
            // The namespace alone would look for `Components\Icon`, so the
            // headline component needs its alias too.
            $this->blade->componentNamespace(self::COMPONENTS_NAMESPACE, $prefix);
            $this->blade->component(IconComponent::class, $prefix . '::icon');
        }

        return $prefixes;
    }

    /**
     * Register one of a pack's components: `{vendor}-{package}::{name}` always,
     * and `{package}-{name}` while the short prefix is on.
     *
     * @return string the alias the registry records for conflict detection: the short one when registered, else the canonical
     */
    public function registerPackComponent(string $vendorPackage, string $name, string $componentClass): string
    {
        $vendorPackage = strtolower($vendorPackage);
        $canonical = str_replace('/', '-', $vendorPackage) . '::' . $name;
        $this->blade->component($componentClass, $canonical);

        if ($this->shortPrefix() === null) {
            return $canonical;
        }

        $short = Str::afterLast($vendorPackage, '/') . '-' . $name;
        $this->blade->component($componentClass, $short);

        return $short;
    }

    /**
     * The class `{short}::icon` resolves to when another provider has replaced
     * it with something that is not an IconComponent; null when it is intact
     * or the short prefix is off. Doctor's `component.short_prefix_shadowed`.
     */
    public function shadowedShortAlias(): ?string
    {
        $short = $this->enabled() ? $this->shortPrefix() : null;

        if ($short === null) {
            return null;
        }

        $class = $this->blade->getClassComponentAliases()[$short . '::icon'] ?? null;

        if (! is_string($class) || $class === IconComponent::class || is_subclass_of($class, IconComponent::class)) {
            return null;
        }

        return $class;
    }
}
