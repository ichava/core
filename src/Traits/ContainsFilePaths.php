<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Traits;

use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * One implementation of "this file must stay inside that directory".
 *
 * `SvgDriver::loadFromLocal()` and `IconWatcherService::extractIconData()` both
 * read SVGs from disk and both need the same boundary, and until now each
 * carried its own copy of it. Two copies of a security check drift, and the one
 * that drifts is the one nobody is looking at.
 *
 * The check is deliberately `realpath()`-based rather than string manipulation
 * on the path: `realpath()` resolves `..` segments *and* symlinked components,
 * so a path that leaves the tree by either route fails the prefix test. A purely
 * textual check would pass a symlinked directory.
 */
trait ContainsFilePaths
{
    /**
     * Assert that `$path` resolves to somewhere inside `$baseDir`.
     *
     * Falls back to the file's own directory when no base is given, which keeps
     * the check meaningful for a caller that has no package root to hand: it
     * still refuses a path that resolves somewhere else entirely.
     *
     * @throws IchavaException when the path escapes, or when either side cannot
     *                         be resolved -- an unresolvable path is refused
     *                         rather than waved through, since `realpath()`
     *                         returns false for a file that does not exist.
     */
    protected function assertPathContained(string $path, ?string $baseDir = null): void
    {
        $realPath = realpath($path);
        $realBase = $baseDir !== null && $baseDir !== ''
            ? realpath($baseDir)
            : realpath(dirname($path));

        if ($realPath === false || $realBase === false) {
            throw IchavaException::securityViolation("Path escapes its directory: '{$path}'");
        }

        $prefix = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (! Str::startsWith($realPath, $prefix)) {
            throw IchavaException::securityViolation("Path escapes its directory: '{$path}'");
        }
    }
}
