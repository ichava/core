<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Actions;

/**
 * The three ways to write one icon: helper call, Blade directive, component tag.
 *
 * Pure. It takes a package's registry metadata and a name, and returns strings.
 * No filesystem, no cache, no database -- which is the point of extracting it
 * from `IconDiscoveryService`, where it sat between a composer.lock parser and a
 * database query and could only be exercised by standing all of that up.
 *
 * That cost something real. The component branch read `blade_component` without
 * a null-coalesce for the whole life of the method, so every call emitted
 * `Undefined array key "blade_component"` and the hint was always null. It was
 * recorded as F-4.2 and fixed in `0.3.1`; a method this small, tested on its
 * own, would not have carried it that long.
 */
final readonly class BuildIconUsageSyntax
{
    /**
     * @param array<string, mixed> $packageData registry metadata for the pack
     *
     * @return array{helper: string, directive: string, component: string|null}
     */
    public function __invoke(
        string $package,
        array $packageData,
        string $name,
        ?string $variant = null,
    ): array {
        // The pack's own prefix when it declares one; the package name is the
        // fallback, so an unregistered pack still produces something readable
        // rather than `:home`.
        $prefix = $packageData['prefix'] ?? $package;
        $iconName = $prefix . ':' . $name;

        if ($variant !== null && $variant !== '') {
            $iconName .= ':' . $variant;
        }

        $component = $packageData['blade_component'] ?? null;

        return [
            'helper'    => "ichava('{$iconName}')",
            'directive' => "@ichava('{$iconName}')",
            // Null rather than an invented tag: a pack that registered no Blade
            // component has no component to show, and guessing one would print
            // markup that does not resolve.
            'component' => is_string($component) && $component !== ''
                ? "<x-{$component} name=\"{$name}\" />"
                : null,
        ];
    }
}
