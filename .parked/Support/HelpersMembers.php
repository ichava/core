<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Parked\Support;

/**
 * Members with no caller, moved here verbatim from `src/Support/Helpers.php` on 2026-09-26.
 *
 * NOT autoloaded and NOT shipped (`/.parked export-ignore`). Kept so a member
 * can be restored from a file rather than from history, if a caller ever
 * appears. See `.parked/README.md` for the measurement behind each entry.
 */
trait HelpersMembers
{
    public const ICHAVA_PGSQL_LANGUAGES = [
        'english' => 'english',
        'simple'  => 'simple',
    ];

    public const ICHAVA_PGSQL_DEFAULT_LANGUAGE = self::ICHAVA_PGSQL_LANGUAGES['simple'];

    /**
     * Sanitize path by removing leading/trailing slashes
     *
     * @param string $path Path to sanitize
     *
     * @return string Sanitized path
     */
    public static function sanitizePath(string $path): string
    {
        return trim($path, '/\\');
    }
}
