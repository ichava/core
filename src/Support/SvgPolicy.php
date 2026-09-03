<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use RuntimeException;

/**
 * Loader for `resources/security/svg-policy.json`, the single definition of
 * what survives SVG sanitisation.
 *
 * Four runtimes sanitise SVG in this ecosystem -- PHP here, the Vue client, the
 * React client and the Python build pipeline -- and until this file existed each
 * carried its own hand-maintained lists. They diverged, silently and in both
 * directions: a 2026-09-02 census measured 3,507 icons that render correctly on
 * the Blade path and wrong in the SPA, because one side was widened and the
 * other was not. Two hand-maintained copies of one policy is not a policy.
 *
 * The JSON is the source of truth. This class is the PHP reader for it; the two
 * TypeScript runtimes and the Python one read the same file, and a test in each
 * asserts they have not drifted from it.
 *
 * @see \Simtabi\Laranail\Ichava\Services\Traits\SanitizesSvg
 */
final class SvgPolicy
{
    /**
     * Path is resolved from this file rather than through `base_path()`, so the
     * policy loads identically in a host application, in the package's own test
     * suite, and from a `composer require`d vendor directory.
     */
    private const RELATIVE_PATH = '/../../resources/security/svg-policy.json';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = self::path();

        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("SVG policy not readable at {$path}.");
        }

        $decoded = json_decode($raw, true);

        /*
         * Fail loudly. A policy that silently falls back to an empty array would
         * strip every element from every icon, and a policy that silently falls
         * back to a permissive default would be a security hole -- both look like
         * a working install until someone renders something.
         */
        if (! is_array($decoded)) {
            throw new RuntimeException(
                "SVG policy at {$path} is not valid JSON: ".json_last_error_msg()
            );
        }

        return self::$cache = $decoded;
    }

    public static function path(): string
    {
        return realpath(__DIR__.self::RELATIVE_PATH) ?: __DIR__.self::RELATIVE_PATH;
    }

    /**
     * @return list<string>
     */
    public static function allowedTags(): array
    {
        return array_values((array) (self::all()['allowedTags'] ?? []));
    }

    /**
     * The by-name allow-list, which is NOT simply the policy's
     * `allowedAttributes` array.
     *
     * The policy separates attributes that are safe by name from those that are
     * safe only after a value check, and puts the second kind in its own blocks:
     * `style` under `styleAttribute`, `href`/`xlink:href` under
     * `fragmentOnlyRefs`. A by-name check that reads only `allowedAttributes`
     * therefore strips `style` -- which is the sole paint source for 261 of 501
     * metronic icons, so they render as solid black shapes. That regression was
     * caught here by `SvgStyleValueTest` on the first run after this class was
     * wired in; it is exactly `SEC-1b` reappearing from the other direction.
     *
     * So the value-restricted names are merged back in. Their VALUES are still
     * checked -- `SanitizesSvg` rejects a non-fragment `url()`, `expression(`,
     * `behavior:` and `-moz-binding` in a style value, and admits a reference
     * only as a same-document fragment. Being on this list means "the name may
     * appear", never "the value is trusted".
     *
     * The `aria-` prefix is deliberately NOT expanded here. It is matched by
     * shape in `SanitizesSvg::isAllowedAttribute()`, so a newly standardised
     * ARIA attribute does not become an accessibility regression.
     *
     * @return list<string>
     */
    public static function allowedAttributes(): array
    {
        $policy = self::all();

        $names = (array) ($policy['allowedAttributes'] ?? []);

        if (isset($policy['styleAttribute'])) {
            $names[] = 'style';
        }

        foreach ((array) ($policy['fragmentOnlyRefs']['attributes'] ?? []) as $ref) {
            $names[] = $ref;
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    public static function forbiddenTags(): array
    {
        return array_values((array) (self::all()['forbiddenTags'] ?? []));
    }

    /**
     * @return list<string>
     */
    public static function allowedAttributePrefixes(): array
    {
        return array_values((array) (self::all()['allowedAttributePrefixes'] ?? []));
    }

    /**
     * The regex a `href`/`xlink:href` value must match to survive. Fragments are
     * inert; everything else is blocked.
     */
    public static function fragmentPattern(): string
    {
        $raw = (string) (self::all()['fragmentOnlyRefs']['allow'] ?? '^#[A-Za-z_][\w.:-]*$');

        return '/'.$raw.'/';
    }

    /**
     * Only for tests that mutate the file on disk. Nothing in the request path
     * should need it.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
