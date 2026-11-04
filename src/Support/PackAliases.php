<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

/**
 * Sets that moved from one pack to another (D9, contract §8).
 *
 * A pack that takes over a set declares it in its config.json:
 *
 *     "package": { "replaces": [ { "package": "ichava/icon-sets-emoji",
 *                                  "paths": ["openmoji-color/**"], "since": "0.1.0" } ] }
 *
 * The item paths move verbatim, so an alias is a pure package rewrite:
 * `ichava/icon-sets-emoji::openmoji-color/1f600` becomes
 * `ichava/icon-sets-openmoji::openmoji-color/1f600`.
 *
 * Built from the registry's packs, so an alias exists exactly when the
 * replacing pack is installed. Without it the old id resolves in the old
 * pack, exactly as before.
 */
final readonly class PackAliases
{
    /**
     * @param list<array{from: string, paths: list<string>, to: string}> $entries
     */
    public function __construct(
        private array $entries = [],
    ) {}

    /**
     * @param array<string, array<string, mixed>> $packages registry metadata, keyed by package
     */
    public static function fromPackages(array $packages): self
    {
        $entries = [];

        foreach ($packages as $name => $metadata) {
            foreach (is_array($metadata['replaces'] ?? null) ? $metadata['replaces'] : [] as $replace) {
                $from = is_array($replace) ? ($replace['package'] ?? null) : null;
                $paths = is_array($replace) && is_array($replace['paths'] ?? null) ? $replace['paths'] : [];

                if (is_string($from) && $from !== '' && $from !== $name) {
                    $entries[] = [
                        'from'  => $from,
                        'paths' => array_values(array_filter($paths, is_string(...))),
                        'to'    => (string) $name,
                    ];
                }
            }
        }

        return new self($entries);
    }

    /**
     * The package an old pack's item now lives in, or null when it has not
     * moved (or its replacement is not installed).
     */
    public function replacementFor(string $package, string $itemPath): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['from'] !== $package) {
                continue;
            }

            foreach ($entry['paths'] as $pattern) {
                if (KindGlob::matches($pattern, $itemPath)) {
                    return $entry['to'];
                }
            }
        }

        return null;
    }

    /**
     * The id an old id now resolves to, or null when it is not aliased.
     *
     * Dots in the in-pack path read as slashes, as they do everywhere an id
     * is parsed.
     */
    public function rewrite(string $id): ?string
    {
        if ($this->entries === [] || ! str_contains($id, '::')) {
            return null;
        }

        [$package, $path] = explode('::', $id, 2);
        $path = str_replace('.', '/', $path);
        $replacement = $this->replacementFor($package, $path);

        return $replacement === null ? null : $replacement . '::' . $path;
    }

    /**
     * Every alias, for doctor and docs.
     *
     * @return list<array{from: string, paths: list<string>, to: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
