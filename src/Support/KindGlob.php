<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

/**
 * The glob syntax of pack kind rules and host kind overrides.
 *
 * Anchored to the whole item path and case-sensitive:
 *
 * - `*`  zero or more characters, never `/`;
 * - `?`  exactly one character (one Unicode code point), never `/`;
 * - `**` zero or more whole segments, and only as a whole segment
 *        (`a/**\/b`, `**\/x`, `a/**`);
 * - anything else matches itself.
 *
 * `[ ] { } \` are reserved, and an empty pattern, a leading `/`, a `..` or
 * empty segment, or a `**` inside a segment make the pattern invalid. An
 * invalid pattern matches nothing; doctor reports it.
 *
 * icon-sets-sync's `internal/core/kinds/glob.go` implements the same syntax
 * and carries the same vector table as its test, so a pattern means the same
 * thing to the tool that writes a pack and to the package that reads it.
 */
final class KindGlob
{
    /** @var array<string, string|false> pattern => compiled regex, or false when invalid */
    private static array $compiled = [];

    public static function isValid(string $pattern): bool
    {
        return self::compile($pattern) !== false;
    }

    public static function matches(string $pattern, string $path): bool
    {
        $regex = self::compile($pattern);

        return $regex !== false && preg_match($regex, $path) === 1;
    }

    /** Whether a pattern contains a wildcard, as opposed to naming one exact path. */
    public static function isLiteral(string $pattern): bool
    {
        return strpbrk($pattern, '*?') === false;
    }

    private static function compile(string $pattern): string|false
    {
        if (array_key_exists($pattern, self::$compiled)) {
            return self::$compiled[$pattern];
        }

        // A bounded memo: patterns come from config and pack manifests, so the
        // set is small, but nothing should grow without limit in a worker.
        if (count(self::$compiled) >= 1024) {
            self::$compiled = [];
        }

        return self::$compiled[$pattern] = self::build($pattern);
    }

    private static function build(string $pattern): string|false
    {
        if ($pattern === '' || $pattern[0] === '/' || strpbrk($pattern, '[]{}\\') !== false) {
            return false;
        }

        $segments = explode('/', $pattern);
        $regex = '';
        $last = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($segment === '' || $segment === '..') {
                return false;
            }

            if ($segment === '**') {
                // Zero or more whole segments. A leading `**` consumes its own
                // trailing slashes, a middle or trailing one its leading
                // slashes, so `**/x` matches `x` and `a/**` matches `a`.
                $regex .= match (true) {
                    $last === 0      => '.*',
                    $index === 0     => '(?:[^/]*/)*',
                    $index === $last => '(?:/.*)?',
                    default          => '(?:/[^/]*)*',
                };

                continue;
            }

            if (str_contains($segment, '**')) {
                return false;
            }

            // The separator before this segment, unless a leading `**` has
            // already consumed it.
            if ($index > 0 && ! ($index === 1 && $segments[0] === '**')) {
                $regex .= '/';
            }

            $regex .= strtr(preg_quote($segment, '#'), ['\\*' => '[^/]*', '\\?' => '[^/]']);
        }

        return '#\A' . $regex . '\z#u';
    }
}
