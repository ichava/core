<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Auth;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The Gates Ichava checks, defined with safe defaults the host can override.
 *
 * - `ichava.admin` is the umbrella ability. When the host has not defined it,
 *   Ichava defines it as a denial, so nothing is administrable by default.
 * - `ichava.manage-cache` is the ability ichava/icon-browser already checks
 *   before clearing or rebuilding the cache. When the host defines
 *   `ichava.admin` but not `manage-cache`, `manage-cache` falls back to
 *   `ichava.admin`, so a host grants admin access by defining one Gate.
 *
 * `manage-cache` is deliberately left undefined when the host defined neither:
 * icon-browser reports an undefined ability as "not configured", which tells
 * the operator what to do, where a defined denial would read as a refusal.
 * A host that defines `manage-cache` itself keeps its own rule.
 *
 * Runs after every provider has booted, so a host's own definitions, made in
 * any provider, are seen first and are never overwritten.
 */
final class IchavaGates
{
    public const string ADMIN = 'ichava.admin';

    public const string MANAGE_CACHE = 'ichava.manage-cache';

    public static function define(Gate $gate): void
    {
        $hostDefinedAdmin = $gate->has(self::ADMIN);

        if (! $hostDefinedAdmin) {
            $gate->define(self::ADMIN, static fn (?Authenticatable $user = null): bool => false);
        }

        if ($hostDefinedAdmin && ! $gate->has(self::MANAGE_CACHE)) {
            $gate->define(
                self::MANAGE_CACHE,
                static fn (?Authenticatable $user = null): bool => $user !== null && $gate->forUser($user)->allows(self::ADMIN),
            );
        }
    }
}
