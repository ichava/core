<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * Old icon names that resolve to an icon kept under another name.
 *
 * A pack that merges duplicate icons keeps one file and records every name it
 * dropped here, so `vendor/pack::old/name` still renders `vendor/pack::kept/name`.
 * Keys and targets are icon paths within one set, written the way they follow
 * `::` (`shapes/circle`, dot or slash form, no `.svg`).
 *
 * The map is consulted only after the real lookup misses, so an alias can never
 * shadow an icon that exists under its name. Each alias resolves in one hop: a
 * target that is itself an alias is refused at registration, so a lookup can
 * neither chain nor loop.
 */
final class IconAliasMap
{
    /**
     * @var array<string, array<string, string>> set => [old path => kept path]
     */
    private array $aliases = [];

    /**
     * Add aliases for one set, replacing any already given for the same names.
     *
     * @param array<array-key, mixed> $aliases old path => kept path
     *
     * @throws IchavaException when an entry is not a string pair, names itself, or chains to another alias
     */
    public function register(string $set, array $aliases): void
    {
        $merged = $this->aliases[$set] ?? [];

        foreach ($aliases as $from => $to) {
            if (! is_string($from) || ! is_string($to) || trim($from) === '' || trim($to) === '') {
                throw IchavaException::invalidConfiguration("alias entries for [{$set}] must map a non-empty icon path to a non-empty icon path");
            }

            $merged[self::normalise($from)] = self::normalise($to);
        }

        foreach ($merged as $from => $to) {
            if ($from === $to) {
                throw IchavaException::invalidConfiguration("alias [{$set}::{$from}] points at itself");
            }

            if (isset($merged[$to])) {
                throw IchavaException::invalidConfiguration("alias [{$set}::{$from}] points at [{$to}], which is itself an alias; point it at [{$merged[$to]}]");
            }
        }

        $this->aliases[$set] = $merged;
    }

    /**
     * The kept path for an old one, or null when the name is not an alias.
     */
    public function resolve(string $set, string $path): ?string
    {
        return $this->aliases[$set][self::normalise($path)] ?? null;
    }

    /**
     * Every alias registered for a set.
     *
     * @return array<string, string> old path => kept path
     */
    public function for(string $set): array
    {
        return $this->aliases[$set] ?? [];
    }

    /**
     * Drop a set's aliases, as when the set is unregistered.
     */
    public function forget(string $set): void
    {
        unset($this->aliases[$set]);
    }

    /**
     * Dot and slash form are one name, as in PathResolver::parseIconPath().
     */
    private static function normalise(string $path): string
    {
        return trim(str_replace('.', '/', $path), '/');
    }
}
