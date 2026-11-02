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
                "SVG policy at {$path} is not valid JSON: " . json_last_error_msg(),
            );
        }

        $policy = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException("SVG policy at {$path} must be a JSON object, not a list.");
            }

            $policy[$key] = $value;
        }

        return self::$cache = $policy;
    }

    public static function path(): string
    {
        return realpath(__DIR__ . self::RELATIVE_PATH) ?: __DIR__ . self::RELATIVE_PATH;
    }

    /**
     * @return list<string>
     */
    public static function allowedTags(): array
    {
        return self::names('allowedTags', self::all()['allowedTags'] ?? []);
    }

    /**
     * The by-name allow-list, which is NOT simply the policy's
     * `allowedAttributes` array.
     *
     * The policy separates attributes that are safe by name from those that are
     * safe only after a value check, and puts the second kind in its own blocks:
     * `style` under `styleAttribute`, `href`/`xlink:href` under
     * `fragmentOnlyRefs`. A by-name check that reads only `allowedAttributes`
     * therefore strips `style` -- which is the sole paint source for over half of
     * one pack's icons, so they render as solid black shapes. That regression was
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

        $names = self::names('allowedAttributes', $policy['allowedAttributes'] ?? []);

        if (isset($policy['styleAttribute'])) {
            $names[] = 'style';
        }

        foreach (self::referenceAttributes() as $ref) {
            $names[] = $ref;
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    public static function forbiddenTags(): array
    {
        return self::names('forbiddenTags', self::all()['forbiddenTags'] ?? []);
    }

    /**
     * @return list<string>
     */
    public static function allowedAttributePrefixes(): array
    {
        return self::names('allowedAttributePrefixes', self::all()['allowedAttributePrefixes'] ?? []);
    }

    /**
     * The attributes carrying a reference, from the policy rather than a
     * constant in the sanitiser. Emptying this in the policy must actually
     * change what the server admits.
     *
     * @return list<string>
     */
    public static function referenceAttributes(): array
    {
        return self::names('fragmentOnlyRefs.attributes', self::fragmentOnlyRefs()['attributes'] ?? []);
    }

    /**
     * The regex a `href`/`xlink:href` value must match to survive. Fragments are
     * inert; everything else is blocked.
     */
    public static function fragmentPattern(): string
    {
        $raw = self::fragmentOnlyRefs()['allow'] ?? '^#[A-Za-z_][\w.:-]*$';

        if (! is_string($raw)) {
            throw new RuntimeException('SVG policy field fragmentOnlyRefs.allow must be a string.');
        }

        return '/' . $raw . '/';
    }

    public static function stripDoctype(): bool
    {
        return (bool) (self::all()['stripDoctype'] ?? true);
    }

    public static function stripEntities(): bool
    {
        return (bool) (self::all()['stripEntities'] ?? true);
    }

    public static function stripComments(): bool
    {
        return (bool) (self::all()['stripComments'] ?? true);
    }

    /**
     * Only for tests that mutate the file on disk. Nothing in the request path
     * should need it.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * The `fragmentOnlyRefs` block. A missing or non-object block reads as
     * empty, exactly as the nested `?? []` reads it replaces did.
     *
     * @return array<array-key, mixed>
     */
    private static function fragmentOnlyRefs(): array
    {
        $block = self::all()['fragmentOnlyRefs'] ?? [];

        return is_array($block) ? $block : [];
    }

    /**
     * A policy list, read with the same `(array)` widening as before (a lone
     * string becomes a one-item list). An entry that is not a string fails
     * loudly: the sanitiser could only ever have choked on it later.
     *
     * @return list<string>
     */
    private static function names(string $field, mixed $value): array
    {
        $names = [];

        foreach ((array) $value as $name) {
            if (! is_string($name)) {
                throw new RuntimeException("SVG policy field {$field} must list strings only.");
            }

            $names[] = $name;
        }

        return $names;
    }
}
