<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Contracts;

/**
 * IchavaPackageInterface
 *
 * Contract for Ichava icon packages.
 * Ensures consistent metadata across all child packages.
 */
interface IchavaPackageInterface
{
    /**
     * Get the composer package name
     *
     * @return string e.g., 'ichava/tabler-icons'
     */
    public function getPackageName(): string;

    /**
     * Get the package version
     *
     * @return string e.g., '1.0.0' or 'dev'
     */
    public function getPackageVersion(): string;

    /**
     * Get the icon set name
     *
     * @return string e.g., 'tabler', 'metronic'
     */
    public function getIconSetName(): string;

    /**
     * Get package metadata for registry
     *
     * @return array Metadata including icon count, categories, etc.
     */
    public function getPackageMetadata(): array;
}
