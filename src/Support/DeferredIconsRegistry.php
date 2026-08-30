<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * Registry for deferred icon loading
 *
 * Stores icon definitions for <use> references to reduce HTML size
 *
 * @example
 * // Register an icon
 * $registry->register('tabler-home', '<svg>...</svg>');
 *
 * // Render as <use> reference
 * echo $registry->render('tabler-home', ['class' => 'w-6 h-6']);
 *
 * // Output all definitions
 * echo $registry->renderDefinitions();
 */
final class DeferredIconsRegistry
{
    /**
     * Registered icon definitions
     *
     * @var array<string, string>
     */
    private array $icons = [];

    public function __construct(
        private readonly SvgProcessingService $svgProcessor,
    ) {}

    /**
     * Register an icon definition
     */
    public function register(string $id, string $svg): void
    {
        if (! isset($this->icons[$id])) {
            $this->icons[$id] = $this->convertToSymbol($svg, $id);
        }
    }

    /**
     * Check if icon is registered
     */
    public function has(string $id): bool
    {
        return isset($this->icons[$id]);
    }

    /**
     * Get registered icon definition
     */
    public function get(string $id): ?string
    {
        return $this->icons[$id] ?? null;
    }

    /**
     * Render icon as <use> reference
     */
    public function render(string $id, array $attributes = []): string
    {
        $attributesHtml = $this->svgProcessor->buildHtml($attributes);

        return sprintf(
            '<svg%s><use xlink:href="#%s" /></svg>',
            $attributesHtml ? " {$attributesHtml}" : '',
            $id,
        );
    }

    /**
     * Render all definitions for page head/footer
     */
    public function renderDefinitions(): string
    {
        if (empty($this->icons)) {
            return '';
        }

        $symbols = implode("\n", $this->icons);

        return <<<HTML
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
{$symbols}
</svg>
HTML;
    }

    /**
     * Get count of registered icons
     */
    public function count(): int
    {
        return count($this->icons);
    }

    /**
     * Clear all registered icons
     */
    public function clear(): void
    {
        $this->icons = [];
    }

    /**
     * Get all registered icon IDs
     *
     * @return array<int, string>
     */
    public function getRegisteredIds(): array
    {
        return array_keys($this->icons);
    }

    /**
     * Generate unique icon ID
     */
    public function generateId(string $set, string $name, ?string $variant = null): string
    {
        $id = "ichava-{$set}-{$name}";

        if ($variant) {
            $id .= "-{$variant}";
        }

        // Sanitize ID (remove invalid characters)
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '-', $id);

        return $sanitized ?? $id;
    }

    /**
     * Convert SVG to <symbol> element
     */
    private function convertToSymbol(string $svg, string $id): string
    {
        // Extract viewBox
        $viewBox = '0 0 24 24'; // default
        if (preg_match('/viewBox=["\']([^"\']+)["\']/', $svg, $matches)) {
            $viewBox = $matches[1];
        }

        // Extract inner content (remove <svg> wrapper)
        $content = preg_replace('/<svg[^>]*>/', '', $svg);
        $content = preg_replace('/<\/svg>/', '', $content ?? '');

        // Remove width/height attributes from inner content
        $content = preg_replace('/\s+(width|height)=["\'][^"\']*["\']/', '', $content ?? '');

        return sprintf(
            '<symbol id="%s" viewBox="%s">%s</symbol>',
            $id,
            $viewBox,
            trim($content ?? ''),
        );
    }
}
