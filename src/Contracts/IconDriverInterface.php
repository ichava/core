<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

interface IconDriverInterface
{
    /**
     * Get all available icons
     */
    public function all(): array;

    /**
     * Render an icon as SVG string
     *
     * @param  string  $name  Icon name
     * @param  array  $attributes  HTML attributes
     */
    public function render(string $name, array $attributes = []): string;

    /**
     * Check if an icon exists
     *
     * @param  string  $name  Icon name
     */
    public function has(string $name): bool;

    /**
     * Set configuration for the driver
     *
     * @return $this
     */
    public function setConfig(array $config): static;

    /**
     * Get configuration
     */
    public function getConfig(): array;

    /**
     * Set the icon path
     *
     * @return $this
     */
    public function setIconPath(string $path): static;

    /**
     * Get the icon path
     */
    public function getIconPath(): string;
}
