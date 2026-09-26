<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Parked\Support;

/**
 * Members with no caller, moved here verbatim from `src/Support/PathResolver.php` on 2026-09-26.
 *
 * NOT autoloaded and NOT shipped (`/.parked export-ignore`). Kept so a member
 * can be restored from a file rather than from history, if a caller ever
 * appears. See `.parked/README.md` for the measurement behind each entry.
 */
trait PathResolverMethods
{
    /**
     * Resolve config value or build default path from class file
     *
     * @param mixed $configValue Config value (if set)
     * @param string $classFile __FILE__ from calling class
     * @param string $defaultRelativePath Default path relative to package root
     * @param int $levelsUp How many levels to go up from class file to package root
     *
     * @return string Resolved absolute path
     */
    public static function resolveConfigOrDefault(
        mixed $configValue,
        string $classFile,
        string $defaultRelativePath,
        int $levelsUp = 3,
    ): string {
        // If config value is set, use it
        if ($configValue) {
            return app(self::class)->normalize($configValue);
        }

        // Build default path from class file location
        $packageRoot = dirname($classFile, $levelsUp);

        return app(self::class)->join($packageRoot, ltrim($defaultRelativePath, '/'));
    }

    /**
     * Validate that a path is a file
     *
     * @throws IchavaException
     */
    public function ensureFile(string $path, string $context = 'File'): string
    {
        $this->ensureExists($path, $context);

        if (! File::isFile($path)) {
            throw IchavaException::invalidConfiguration("{$context} is not a file: {$path}");
        }

        return $path;
    }
}
