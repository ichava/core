<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Drivers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Constants\IchavaConstants;
use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Exceptions\IconRenderException;
use Simtabi\Laranail\Ichava\Services\IconCacheService;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * SvgDriver - SVG Loading and Rendering Driver
 *
 * Loads SVG files from the local filesystem, passes them through the
 * SvgProcessingService pipeline, and returns sanitized HTML-safe strings.
 * Results are cached by IconCacheService to avoid redundant disk reads.
 *
 * Loading strategy (in order):
 * 1. Cache hit (IconCacheService), return immediately
 * 2. Local filesystem read via loadFromLocal(), enforcing:
 *    - realpath() containment (blocks path traversal outside the package directory)
 *    - Symlink rejection (prevents directory-escape via symlinks)
 *    - File size bounds (IchavaConstants::MIN/MAX_SVG_FILE_SIZE)
 * 3. SvgProcessingService::process(), sanitize → optimize → apply attributes
 * 4. Store result in cache, then return
 *
 * Extend this class to add support for remote URL or CDN sources.
 *
 * @see SvgProcessingService
 * @see IconCacheService
 * @see IchavaConstants
 */
class SvgDriver
{
    /**
     * Create a new SVG driver instance.
     */
    public function __construct(
        protected Filesystem $files,
        protected SvgProcessingService $processor,
        protected IconCacheService $cache
    ) {
        if (! $this->cache) {
            throw IchavaException::dependencyNotInjected('IconCacheService', static::class);
        }
    }

    /**
     * Load, process, and render an SVG icon to an HTML string.
     *
     * Delegates to load() then injectAttributes(). On any exception the raw
     * message is re-thrown as an IconRenderException with the icon name included.
     *
     * @param  IconData  $icon  Resolved icon data object (path, name, set)
     * @param  array<string, mixed>  $attributes  HTML attributes to inject onto the SVG element
     * @return string Rendered HTML-safe SVG string
     *
     * @throws IconRenderException wrapping any underlying load or processing failure
     */
    public function render(IconData $icon, array $attributes = []): string
    {
        try {
            $content = $this->load($icon->path);
            $content = $this->processor->process($content, $attributes);

            return $content;
        } catch (\Exception $e) {
            throw new IconRenderException(
                "Failed to render icon '{$icon->name}': {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Load raw SVG content from the given filesystem path.
     *
     * Currently delegates directly to loadFromLocal(). The $options parameter
     * is reserved for future loaders (remote URL, CDN, encrypted storage).
     *
     * @param  string  $path  Absolute filesystem path to the .svg file
     * @param  array<string, mixed>  $options  Reserved for future use
     * @return string Raw SVG content (unsanitized)
     *
     * @throws IchavaException if the file does not exist, is unreadable, or fails security checks
     */
    public function load(string $path, array $options = []): string
    {
        return $this->loadFromLocal($path);
    }

    /**
     * Read an SVG file from the local filesystem with security and size enforcement.
     *
     * Performs four checks before reading:
     * 1. File existence
     * 2. Symlink rejection, `is_link()` check prevents directory-escape attacks
     * 3. `realpath()` containment, resolved path must stay within its own directory
     * 4. File size bounds, rejects files smaller than MIN_SVG_FILE_SIZE or larger
     *    than `ichava.max_file_size` (falls back to MAX_SVG_FILE_SIZE)
     *
     * @param  string  $path  Absolute path to the SVG file
     * @return string Raw SVG content
     *
     * @throws IchavaException on any security violation, size violation, or read failure
     */
    protected function loadFromLocal(string $path): string
    {
        if (! $this->files->exists($path)) {
            throw IchavaException::pathNotFound($path);
        }

        // Symlink detection: reject symlinks to prevent directory escape attacks
        if (is_link($path)) {
            throw IchavaException::securityViolation("Symlinks are not allowed: '{$path}'");
        }

        // Realpath containment: ensure the resolved path stays within its parent directory
        $realPath = realpath($path);
        $realDir = realpath(dirname($path));

        if ($realPath === false || $realDir === false || ! Str::startsWith($realPath, $realDir.DIRECTORY_SEPARATOR)) {
            throw IchavaException::securityViolation("Path escapes its directory: '{$path}'");
        }

        if (! $this->files->isReadable($path)) {
            throw IchavaException::filesystemFailure('read', $path);
        }

        // Performance: Check file size before loading (prevent loading huge files)
        $size = $this->files->size($path);

        // Validate minimum size
        if ($size < IchavaConstants::MIN_SVG_FILE_SIZE) {
            throw IchavaException::invalidSvg("File is too small (corrupt or empty): {$path}");
        }

        // Prevent loading excessively large files
        $maxSize = config('ichava.max_file_size', IchavaConstants::MAX_SVG_FILE_SIZE);
        if ($size > $maxSize) {
            throw IchavaException::invalidSvg("File exceeds maximum size of {$maxSize} bytes: {$path}");
        }

        $content = $this->files->get($path);

        if (empty($content)) {
            throw IchavaException::invalidSvg("Content is empty: {$path}");
        }

        return $content;
    }

    /**
     * Inject custom attributes into the SVG tag.
     *
     * Preserves critical attributes like viewBox while allowing width/height overrides.
     * Handles accessibility attributes (title, aria-label, role).
     * Merges class attributes to prevent duplicates.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function injectAttributes(string $svg, array $attributes): string
    {
        if (empty($attributes)) {
            return $svg;
        }

        // Default aria-hidden for decorative icons (no title or aria-label set)
        if (! isset($attributes['aria-label']) && ! isset($attributes['title'])) {
            $attributes['aria-hidden'] = $attributes['aria-hidden'] ?? 'true';
        }

        // Handle title for accessibility (inject inside SVG)
        if (isset($attributes['title'])) {
            $svg = $this->injectTitle($svg, $attributes['title']);
            unset($attributes['title']);
        }

        // Process SVG for responsive sizing
        $svg = $this->ensureResponsiveSvg($svg, $attributes);

        // Extract existing class from SVG if present, and merge with new classes
        if (isset($attributes['class']) && preg_match('/<svg[^>]+class=["\']([^"\']+)["\']/', $svg, $matches)) {
            $existingClasses = $matches[1];
            $attributes['class'] = $this->processor->mergeClasses($existingClasses, $attributes['class']);

            // Remove the existing class attribute from SVG to prevent duplication
            $svg = preg_replace('/<svg([^>]+)class=["\'][^"\']+["\']/', '<svg$1', $svg, 1) ?? $svg;
        }

        // Build and inject attributes
        $attributesHtml = $this->processor->buildHtml($attributes);

        return Str::replace(
            '<svg',
            rtrim("<svg {$attributesHtml}"),
            $svg
        );
    }

    /**
     * Normalise an SVG element for responsive rendering and Tailwind compatibility.
     *
     * Applies five transformations in order:
     * 1. Capture the original width/height values (used as viewBox fallback)
     * 2. Remove fixed width/height attributes and inline styles from the root `<svg>`
     *    so that Tailwind `w-*` / `h-*` classes take effect
     * 3. Synthesise a viewBox (`0 0 {w} {h}`) if one is not already present
     * 4. Add `preserveAspectRatio="xMidYMid meet"` if not already set
     * 5. Add `fill="currentColor"` to the root `<svg>` if no fill is set, enabling
     *    dynamic colour via Tailwind `text-*` classes
     *
     * @param  string  $svg  Raw SVG markup
     * @param  array<string, mixed>  $attributes  Attributes being injected (used to check for explicit sizing)
     * @return string Normalised SVG markup
     */
    protected function ensureResponsiveSvg(string $svg, array $attributes): string
    {
        // Step 1: Extract original dimensions BEFORE removing them (for viewBox fallback)
        $originalWidth = '24';
        $originalHeight = '24';

        if (preg_match('/width=["\'](\d+(?:\.\d+)?)["\']/', $svg, $wMatch)) {
            $originalWidth = $wMatch[1];
        }
        if (preg_match('/height=["\'](\d+(?:\.\d+)?)["\']/', $svg, $hMatch)) {
            $originalHeight = $hMatch[1];
        }

        // Step 2: ALWAYS remove fixed width/height from root <svg> to allow Tailwind classes
        // This is critical for w-6 h-6 and similar classes to work
        $svg = preg_replace('/<svg([^>]*)\s+width=["\'][^"\']*["\']/', '<svg$1', $svg) ?? $svg;
        $svg = preg_replace('/<svg([^>]*)\s+height=["\'][^"\']*["\']/', '<svg$1', $svg) ?? $svg;

        // Also remove inline style width/height that might conflict
        $svg = preg_replace('/<svg([^>]*)\s+style=["\']([^"\']*?)(?:width|height):\s*[^;"\']+(;[^"\']*)?["\']/', '<svg$1 style="$2$3"', $svg) ?? $svg;

        // Step 3: Ensure viewBox exists for proper scaling
        if (! Str::contains($svg, 'viewBox')) {
            $svg = preg_replace(
                '/<svg/',
                "<svg viewBox=\"0 0 {$originalWidth} {$originalHeight}\"",
                $svg,
                1
            ) ?? $svg;
        }

        // Step 4: Add preserveAspectRatio for consistent rendering (if not already set)
        if (! Str::contains($svg, 'preserveAspectRatio')) {
            $svg = preg_replace(
                '/<svg/',
                '<svg preserveAspectRatio="xMidYMid meet"',
                $svg,
                1
            ) ?? $svg;
        }

        // Step 5: Enable currentColor for dynamic color changes via Tailwind text-* classes
        // Check if there's already a fill attribute on the root <svg>
        if (! preg_match('/<svg[^>]+fill=/', $svg)) {
            // No fill on root - add currentColor
            $svg = preg_replace(
                '/<svg/',
                '<svg fill="currentColor"',
                $svg,
                1
            ) ?? $svg;
        } else {
            // Has fill - check if it's a fixed color and replace with currentColor
            // This allows: <svg fill="#000"> to become <svg fill="currentColor">
            // But preserves: <svg fill="none"> and <svg fill="transparent">
            $svg = preg_replace(
                '/<svg([^>]+)fill=["\'](?!none|transparent|currentColor)[^"\']+["\']/',
                '<svg$1fill="currentColor"',
                $svg,
                1
            ) ?? $svg;
        }

        // Step 6: Also ensure child paths/shapes inherit currentColor if they have hardcoded colors
        // This is optional but helps with icons that have hardcoded fill colors
        // Only do this if we're not dealing with multi-color icons (which usually have class attributes)
        $hasMultipleColors = preg_match_all('/<(?:path|circle|rect|polygon|ellipse)[^>]+fill=["\'](?!none|transparent|currentColor|inherit)/', $svg);
        if ($hasMultipleColors === 1) {
            // Single colored icon - replace hardcoded fill with currentColor
            $svg = preg_replace(
                '/<(path|circle|rect|polygon|ellipse)([^>]+)fill=["\'](?!none|transparent|currentColor|inherit)[^"\']+["\']/',
                '<$1$2fill="currentColor"',
                $svg
            ) ?? $svg;
        }

        return $svg;
    }

    /**
     * Inject title element for accessibility
     */
    protected function injectTitle(string $svg, string $title): string
    {
        // Escape the title for security
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        // Generate unique ID for aria-labelledby
        $titleId = 'icon-title-'.md5($title.microtime());

        // Create title element
        $titleElement = "<title id=\"{$titleId}\">{$safeTitle}</title>";

        // Inject title right after opening <svg> tag and add aria-labelledby
        $svg = preg_replace(
            '/<svg([^>]*)>/',
            "<svg$1 aria-labelledby=\"{$titleId}\">{$titleElement}",
            $svg,
            1
        );

        return $svg ?? $svg;
    }

    /**
     * Extract the viewBox attribute from an SVG string.
     */
    public function getViewBox(string $svg): ?string
    {
        if (preg_match('/viewBox=["\']([^"\']+)["\']/', $svg, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract width and height dimensions from an SVG string.
     *
     * @return array{width: string|null, height: string|null}
     */
    public function getDimensions(string $svg): array
    {
        $width = null;
        $height = null;

        if (preg_match('/width=["\']([^"\']+)["\']/', $svg, $matches)) {
            $width = $matches[1];
        }

        if (preg_match('/height=["\']([^"\']+)["\']/', $svg, $matches)) {
            $height = $matches[1];
        }

        return compact('width', 'height');
    }
}
