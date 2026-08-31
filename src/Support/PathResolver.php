<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use ReflectionClass;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Data\IconPathResult;
use Simtabi\Laranail\Ichava\Constants\IchavaConstants;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * Parses Ichava icon paths and resolves filesystem locations. See README
 * § "Icon Path Format" for the path grammar; both slash and dot separators
 * after `::` are accepted and normalized to slash form.
 */
class PathResolver
{
    /**
     * Default SVG assets path constant
     * Used for child packages following Ichava conventions
     */
    private const SVG_PATH = IchavaConstants::SVG_ASSETS_PATH;

    protected string $pathSeparator;

    protected string $variantSeparator;

    public function __construct()
    {
        $this->pathSeparator = config('ichava.core.separators.path', '::');
        $this->variantSeparator = config('ichava.core.separators.variant', '/');
    }

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
     * Resolve package path using reflection
     *
     * @param string|object $caller The calling class name or instance
     * @param int $levelsUp Number of directory levels to go up (default: 3 for src/Subdirectory/File.php to package root)
     * @param string $append Optional path to append
     */
    public static function resolvePackagePath(string|object $caller, int $levelsUp = 3, string $append = ''): string
    {
        $reflection = new ReflectionClass($caller);
        $callerFile = $reflection->getFileName();

        $basePath = dirname($callerFile, $levelsUp);

        if ($append !== '') {
            $basePath .= '/' . ltrim($append, '/');
        }

        return $basePath;
    }

    /**
     * Get the package root path from a service provider
     *
     * @param string|object $provider The service provider class or instance
     * @param int $levelsUp Number of directory levels to go up from provider file (default: 3 for src/Providers/ServiceProvider.php)
     */
    public static function packageRootFromProvider(string|object $provider, int $levelsUp = 3): string
    {
        return self::resolvePackagePath($provider, $levelsUp);
    }

    /**
     * Get the SVG assets path from a service provider
     *
     * @param string|object $provider The service provider class or instance
     * @param int $levelsUp Number of directory levels to go up from provider file (default: 3 for src/Providers/ServiceProvider.php)
     */
    public static function svgPathFromProvider(string|object $provider, int $levelsUp = 3): string
    {
        return self::resolvePackagePath($provider, $levelsUp, self::SVG_PATH);
    }

    /**
     * Get the SVG assets path from an IconSet or absolute path
     *
     * Accepts:
     * - IconSet class/instance (uses reflection to find package SVG path)
     * - Absolute path (validates exists and is readable)
     * - Relative path from base_path() (e.g., 'platform/icons-bundle')
     *
     * @param string|object $iconSetOrPath IconSet class/instance OR path to icons
     * @param int $levelsUp Number of directory levels to go up from IconSet file (default: 3)
     *
     * @return string Absolute path to icons directory
     *
     * @throws IchavaException If path doesn't exist, isn't readable, or isn't a directory
     */
    public static function svgPathFromIconSet(string|object $iconSetOrPath, int $levelsUp = 3): string
    {
        $resolvedPath = null;

        // If it's a string, check if it's an absolute or relative path
        if (is_string($iconSetOrPath)) {
            // Absolute path (starts with / on Unix or C:\ on Windows)
            if (Str::startsWith($iconSetOrPath, '/') || preg_match('/^[A-Z]:\\\\/i', $iconSetOrPath)) {
                $resolvedPath = $iconSetOrPath;
            }
            // Check if it's a class name (contains backslashes)
            elseif (Str::contains($iconSetOrPath, '\\')) {
                // It's a class name, use reflection
                $resolvedPath = self::resolvePackagePath($iconSetOrPath, $levelsUp, self::SVG_PATH);
            }
            // Otherwise treat as relative path from base_path()
            else {
                $resolvedPath = base_path($iconSetOrPath);
            }
        } else {
            // It's an object, use reflection
            $resolvedPath = self::resolvePackagePath($iconSetOrPath, $levelsUp, self::SVG_PATH);
        }

        // Validate path exists
        if (! File::exists($resolvedPath)) {
            throw IchavaException::pathNotFound(
                "Icon path does not exist: {$resolvedPath}",
            );
        }

        // Validate path is readable
        if (! File::isReadable($resolvedPath)) {
            throw IchavaException::pathNotReadable(
                $resolvedPath,
                'Icon directory must be readable',
            );
        }

        // Validate path is a directory
        if (! File::isDirectory($resolvedPath)) {
            throw IchavaException::invalidPathType(
                $resolvedPath,
                'directory',
                'file',
            );
        }

        return $resolvedPath;
    }

    /**
     * Parse `vendor/package::category/icon-name` (or dot form) into its parts.
     *
     * @throws IchavaException When the path does not match the Ichava format.
     */
    public function parseIconPath(string $path): IconPathResult
    {
        // SECURITY: Validate path length (prevent DOS)
        if (strlen($path) > IchavaConstants::MAX_PATH_LENGTH) {
            throw IchavaException::pathTooLong($path, IchavaConstants::MAX_PATH_LENGTH);
        }

        // SECURITY: Check for path traversal attempts
        if ($this->hasPathTraversal($path)) {
            throw IchavaException::pathTraversalAttempt($path);
        }

        // ENFORCE: vendor/package format is REQUIRED
        if (! Str::contains($path, $this->pathSeparator)) {
            throw IchavaException::missingPackageInPath($path);
        }

        $vendor = null;
        $package = null;
        $set = null;

        // Step 1: Parse vendor/package (REQUIRED)
        if (Str::contains($path, '/') && Str::contains($path, $this->pathSeparator)) {
            // Full format: vendor/package:icon
            [$vendorPackage, $iconPath] = explode($this->pathSeparator, $path, 2);

            // NORMALIZE: Convert dots to slashes in icon path for consistency
            // This allows both formats: vendor/package::ui.icon OR vendor/package::ui/icon
            // Both will work and be normalized to: vendor/package::ui/icon
            $iconPath = str_replace('.', '/', $iconPath);

            if (Str::contains($vendorPackage, '/')) {
                [$vendor, $package] = explode('/', $vendorPackage, 2);
                $set = $vendorPackage; // Use full vendor/package as the set identifier
            } else {
                // Invalid: has colon but no slash before it
                throw IchavaException::invalidIconPath($path);
            }
        } else {
            // Invalid: missing vendor/package format
            throw IchavaException::invalidIconPath($path);
        }

        // SECURITY: Validate vendor and package names (alphanumeric + dash/underscore only)
        if (! $this->isValidIdentifier($vendor) || ! $this->isValidIdentifier($package)) {
            throw IchavaException::invalidIdentifier($path);
        }

        // Step 2: Parse flexible path structure
        // The icon path can have ANY depth: variant/category/sub/whatever/icon
        // We ALWAYS take the LAST segment as the icon name
        $parts = explode($this->variantSeparator, $iconPath);
        $partsCount = count($parts);

        // SECURITY: Validate nesting depth (prevent DOS)
        if ($partsCount > IchavaConstants::MAX_NESTING_DEPTH) {
            throw IchavaException::pathTooDeep($path, IchavaConstants::MAX_NESTING_DEPTH);
        }

        // Icon name is ALWAYS the last part
        $iconName = array_pop($parts);

        // SECURITY: Validate icon name
        if (! $this->isValidIconName($iconName)) {
            throw IchavaException::invalidIconName($iconName);
        }

        // SECURITY: Validate each path segment
        foreach ($parts as $part) {
            if (! $this->isValidPathSegment($part)) {
                throw IchavaException::invalidPathSegment($part);
            }
        }

        // Everything before the icon name forms the flexible path
        // For backward compatibility, we map first parts to variant/category
        $variant = $parts[0] ?? null;
        $category = $parts[1] ?? null;

        // Store the full path for flexible icon set implementations
        $fullPath = $iconPath;

        return new IconPathResult($set, $iconName, $variant, $category, $vendor, $package, $fullPath);
    }

    /**
     * Build icon path string from components
     */
    public function buildIconPath(
        string $name,
        ?string $set = null,
        ?string $variant = null,
        ?string $category = null,
        ?string $vendor = null,
        ?string $package = null,
    ): string {
        $parts = [];

        // Add vendor/package or set
        if ($vendor && $package) {
            $parts[] = $vendor . '/' . $package . $this->pathSeparator;
        } elseif ($set) {
            $parts[] = $set . $this->pathSeparator;
        }

        // Add variant
        if ($variant) {
            $parts[] = $variant . $this->variantSeparator;
        }

        // Add category
        if ($category) {
            $parts[] = $category . $this->variantSeparator;
        }

        // Add icon name
        $parts[] = $name;

        return implode('', $parts);
    }

    /**
     * Resolve manifest path from configuration
     *
     * @param string|null $configuredPath Path from config if set
     *
     * @return string Full path to manifest file
     */
    public function resolveManifestPath(?string $configuredPath = null): string
    {
        if ($configuredPath !== null) {
            return $this->normalize($configuredPath);
        }

        return $this->getDefaultManifestPath();
    }

    /**
     * Get default manifest path
     */
    public function getDefaultManifestPath(): string
    {
        return base_path('bootstrap/cache/ichava-manifest.php');
    }

    /**
     * Get manifest path from config with fallback to default
     */
    public function getManifestPathFromConfig(): string
    {
        return $this->resolveManifestPath(
            config('ichava.core.manifest.path'),
        );
    }

    /**
     * Normalize a path to absolute format
     */
    public function normalize(string $path): string
    {
        // Already absolute (Unix)
        if (Str::startsWith($path, '/')) {
            return $path;
        }

        // Already absolute (Windows)
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
            return $path;
        }

        // Relative - make absolute to Laravel base path
        return $this->join(base_path(), $path);
    }

    /**
     * Join path segments safely
     */
    public function join(string ...$segments): string
    {
        if (empty($segments)) {
            return '';
        }

        $isAbsolute = Str::startsWith($segments[0], '/');
        $isWindowsAbsolute = preg_match('/^[a-zA-Z]:/', $segments[0]);

        // Normalize all segments
        $normalized = array_map(function ($segment) {
            return trim($segment, '/\\');
        }, $segments);

        // Remove empty segments
        $normalized = Arr::where($normalized, fn ($seg) => $seg !== '');

        // Join with DIRECTORY_SEPARATOR
        $joined = implode(DIRECTORY_SEPARATOR, $normalized);

        // Restore leading slash for absolute paths (Unix)
        if ($isAbsolute && ! Str::startsWith($joined, '/')) {
            $joined = '/' . $joined;
        }

        return $joined;
    }

    /**
     * Check if a path is absolute
     */
    public function isAbsolute(string $path): bool
    {
        return Str::startsWith($path, '/')
            || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path);
    }

    /**
     * Resolve path relative to a base directory
     */
    public function resolveRelativeTo(string $basePath, string $relativePath): string
    {
        if ($this->isAbsolute($relativePath)) {
            return $relativePath;
        }

        return $this->join($basePath, $relativePath);
    }

    /**
     * Get package base path from service provider class
     */
    public function getPackageBasePath(string $providerClass): string
    {
        $reflection = new ReflectionClass($providerClass);
        $providerPath = $reflection->getFileName();

        if (! $providerPath) {
            throw IchavaException::invalidConfiguration("Could not determine path for provider: {$providerClass}");
        }

        // Navigate up from Provider class to package root
        // Typically: src/Providers/ServiceProvider.php -> ../../
        return dirname($providerPath, 3);
    }

    /**
     * Validate that a path exists
     *
     * @throws IchavaException
     */
    public function ensureExists(string $path, string $context = 'Path'): string
    {
        if (! File::exists($path)) {
            throw IchavaException::pathNotFound("{$context}: {$path}");
        }

        return $path;
    }

    /**
     * Validate that a path is a directory
     *
     * @throws IchavaException
     */
    public function ensureDirectory(string $path, string $context = 'Directory'): string
    {
        $this->ensureExists($path, $context);

        if (! File::isDirectory($path)) {
            throw IchavaException::invalidConfiguration("{$context} is not a directory: {$path}");
        }

        return $path;
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

    /**
     * Get relative path from base to target
     */
    public function getRelativePath(string $from, string $to): string
    {
        $from = str_replace('\\', '/', realpath($from) ?: $from);
        $to = str_replace('\\', '/', realpath($to) ?: $to);

        $fromParts = explode('/', trim($from, '/'));
        $toParts = explode('/', trim($to, '/'));

        // Find common base
        $commonLength = 0;
        $minLength = min(count($fromParts), count($toParts));

        for ($i = 0; $i < $minLength; $i++) {
            if ($fromParts[$i] !== $toParts[$i]) {
                break;
            }
            $commonLength++;
        }

        // Build relative path
        $upLevels = count($fromParts) - $commonLength;
        $downPath = array_slice($toParts, $commonLength);

        $relativeParts = array_merge(
            array_fill(0, $upLevels, '..'),
            $downPath,
        );

        return implode('/', $relativeParts);
    }

    /**
     * Create directory if it doesn't exist
     */
    public function ensureDirectoryExists(string $path, int $permissions = 0755): string
    {
        if (! File::isDirectory($path)) {
            File::makeDirectory($path, $permissions, true);
        }

        return $path;
    }

    /**
     * Check for path traversal attempts
     *
     * Detects:
     * - ../
     * - ..\
     * - %2e%2e/
     * - %2e%2e\
     * - ..%2f
     * - ..%5c
     * - URL encoded variants
     */
    private function hasPathTraversal(string $path): bool
    {
        // Decode URL encoding
        $decoded = urldecode($path);

        // Check for directory traversal patterns
        $patterns = [
            '../',
            '..\\',
            '%2e%2e/',
            '%2e%2e\\',
            '..%2f',
            '..%5c',
            '%252e%252e',  // Double-encoded
        ];

        $lowerPath = Str::lower($decoded);

        foreach ($patterns as $pattern) {
            if (Str::contains($lowerPath, $pattern)) {
                return true;
            }
        }

        // Check for multiple dots in sequence
        if (preg_match('/\.{2,}/', $path)) {
            return true;
        }

        return false;
    }

    /**
     * Validate identifier (vendor/package name)
     *
     * Allows: alphanumeric, dash, underscore
     * Disallows: special characters, whitespace, path separators
     */
    private function isValidIdentifier(string $identifier): bool
    {
        return (bool) preg_match('/^[a-z0-9_-]+$/i', $identifier);
    }

    /**
     * Validate icon name
     *
     * Allows: alphanumeric, dash, underscore, dot
     * Disallows: path separators, special characters
     *
     * Also validates file extension if present (must be .svg)
     *
     * @param string $name Icon name to validate
     *
     * @return bool True if valid
     */
    private function isValidIconName(string $name): bool
    {
        // Empty not allowed
        if (empty(trim($name))) {
            return false;
        }

        // Check for invalid characters
        if (! preg_match('/^[a-z0-9._-]+$/i', $name)) {
            return false;
        }

        // If has extension, must be .svg
        if (Str::contains($name, '.')) {
            $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
            if ($extension !== '' && $extension !== 'svg') {
                return false;
            }
        }

        // Check length
        if (strlen($name) > IchavaConstants::MAX_ICON_NAME_LENGTH) {
            return false;
        }

        return true;
    }

    /**
     * Validate path segment (variant/category/etc)
     *
     * Allows: alphanumeric, dash, underscore
     * Disallows: special characters, whitespace
     *
     * @param string $segment Path segment to validate
     *
     * @return bool True if valid
     */
    private function isValidPathSegment(string $segment): bool
    {
        // Empty segments not allowed
        if (empty(trim($segment))) {
            return false;
        }

        // Only alphanumeric, dash, underscore allowed
        if (! preg_match('/^[a-z0-9_-]+$/i', $segment)) {
            return false;
        }

        // Check length
        if (strlen($segment) > IchavaConstants::MAX_PATH_SEGMENT_LENGTH) {
            return false;
        }

        return true;
    }
}
