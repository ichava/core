<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support\Seeder;

use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Simtabi\Laranail\Ichava\Services\IconRegistry;

/**
 * Icon Terms Seeder
 *
 * Dynamically generates categories and variants from registered icon packages
 * by scanning the filesystem structure.
 *
 * WORKFLOW:
 * 1. Scan all registered packages from IconRegistry
 * 2. For each package, scan the 'files/' directory structure
 * 3. Generate nested categories based on folder hierarchy
 * 4. Extract variants from config.json or folder patterns
 * 5. Create IconTerm records with proper parent-child relationships
 *
 * STRUCTURE:
 * - Root folder = package category (e.g., "bootstrap-icons")
 * - Subfolders = nested categories (e.g., "logos", "regular", "solid")
 * - Variants extracted from config.json metadata.data.variants
 *
 * @see IchavaSeeder Main seeder that orchestrates term + icon seeding
 */
class IconTermsSeeder extends Seeder
{
    protected IconRegistry $packageRegistry;

    public function __construct()
    {
        $this->packageRegistry = app(IconRegistry::class);
    }

    public function run(): void
    {
        $this->output('🔍 Scanning registered packages for categories and variants...', 'info');
        $this->output('', 'newLine');

        $packages = $this->packageRegistry->all();

        if (empty($packages)) {
            $this->output('No icon packages registered. Please register packages first.', 'warn');

            return;
        }

        foreach ($packages as $packageName => $packageData) {
            $this->seedSinglePackage($packageName, $packageData);
        }

        $this->output('', 'newLine');
        $this->output('✅ Icon terms seeded successfully!', 'info');
    }

    /**
     * Seed terms for a single package (used by IchavaSeeder)
     */
    public function seedSinglePackage(string $packageName, array $packageData): void
    {
        $this->output("  Processing: <fg=cyan>{$packageName}</fg=cyan>");

        // Seed categories from filesystem
        $this->seedPackageCategories($packageName, $packageData);

        // Seed variants from config.json
        $this->seedPackageVariants($packageName, $packageData);
    }

    /**
     * Safe output - only outputs if command is set
     */
    protected function output(string $message, string $type = 'line'): void
    {
        if (! $this->command) {
            return;
        }

        match ($type) {
            'info'    => $this->command->info($message),
            'warn'    => $this->command->warn($message),
            'error'   => $this->command->error($message),
            'newLine' => $this->command->newLine(),
            default   => $this->command->line($message),
        };
    }

    /**
     * Seed categories for a specific package by scanning filesystem
     */
    protected function seedPackageCategories(string $packageName, array $packageData): void
    {
        $basePath = $packageData['base_path'] ?? $packageData['path'] ?? null;

        if (! $basePath || ! File::isDirectory($basePath)) {
            $this->output("    ⚠ Base path not found: {$basePath}", 'warn');

            return;
        }

        // Look for 'files/' directory
        $filesPath = $basePath . '/files';
        if (! File::isDirectory($filesPath)) {
            $filesPath = $basePath; // Fallback to base path
        }

        // Scan folder structure
        $categories = $this->scanFolderStructure($filesPath, $packageName);

        if (empty($categories)) {
            $this->output('    <fg=yellow>⚠</fg=yellow> No categories found');

            return;
        }

        // Insert categories
        $this->insertTerms(IconTerm::TYPE_CATEGORY, $categories, null, $packageName);

        $count = $this->countCategories($categories);
        $this->output("    <fg=green>✓</fg=green> Seeded {$count} categories");
    }

    /**
     * Seed variants for a specific package from config.json
     */
    protected function seedPackageVariants(string $packageName, array $packageData): void
    {
        $basePath = $packageData['base_path'] ?? $packageData['path'] ?? null;

        if (! $basePath) {
            return;
        }

        // Check for config.json
        $configPath = $basePath . '/config.json';
        if (! File::exists($configPath)) {
            return;
        }

        $config = json_decode(File::get($configPath), true);
        $variants = $config['metadata']['data']['variants'] ?? [];

        if (empty($variants)) {
            return;
        }

        // Convert variants to seeder format
        $variantTerms = [];
        foreach ($variants as $variantSlug => $variantData) {
            $variantTerms[] = [
                'name'        => $variantData['name'] ?? ucwords(str_replace(['-', '_'], ' ', $variantSlug)),
                'slug'        => $variantSlug,
                'description' => $variantData['description'] ?? null,
            ];
        }

        $this->insertTerms(IconTerm::TYPE_VARIANT, $variantTerms, null, $packageName);

        $this->output('    <fg=green>✓</fg=green> Seeded ' . count($variantTerms) . ' variants');
    }

    /**
     * Recursively scan folder structure and build category hierarchy
     */
    protected function scanFolderStructure(
        string $basePath,
        string $packageName,
        int $maxDepth = 3,
        int $currentDepth = 0,
    ): array {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $categories = [];
        $directories = File::directories($basePath);

        foreach ($directories as $dir) {
            $folderName = basename($dir);

            // Skip common non-icon directories
            if (in_array($folderName, ['config', 'lang', 'vendor', 'node_modules', '.git', 'dist', 'build'])) {
                continue;
            }

            // Check if this folder contains SVG files or has subdirectories with SVGs
            $hasSvgFiles = $this->hasSvgFiles($dir);
            $hasSubdirectories = ! empty(File::directories($dir));

            if (! $hasSvgFiles && ! $hasSubdirectories) {
                continue;
            }

            // Build category
            $category = [
                'name'        => $this->formatCategoryName($folderName),
                'slug'        => $folderName,
                'description' => $this->generateCategoryDescription($folderName),
            ];

            // Recursively scan for nested categories
            $children = $this->scanFolderStructure($dir, $packageName, $maxDepth, $currentDepth + 1);

            if (! empty($children)) {
                $category['children'] = $children;
            }

            $categories[] = $category;
        }

        return $categories;
    }

    /**
     * Check if directory contains SVG files (direct children only)
     */
    protected function hasSvgFiles(string $directory): bool
    {
        $svgFiles = File::glob($directory . '/*.svg');

        return ! empty($svgFiles);
    }

    /**
     * Format category name for display
     */
    protected function formatCategoryName(string $slug): string
    {
        // Convert kebab-case/snake_case to Title Case
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Generate category description based on name
     */
    protected function generateCategoryDescription(string $slug): ?string
    {
        // Simple heuristic descriptions
        $descriptions = [
            'logos'         => 'Brand logos and company identities',
            'regular'       => 'Regular style icons with standard line weight',
            'solid'         => 'Solid filled icons',
            'outlined'      => 'Outlined style icons',
            'filled'        => 'Filled style icons',
            'linear'        => 'Linear style with thin lines',
            'bold'          => 'Bold style with thick lines',
            'duotone'       => 'Two-tone style icons',
            'brands'        => 'Brand and company icons',
            'social'        => 'Social media platform icons',
            'ui'            => 'User interface elements and controls',
            'arrows'        => 'Directional arrows and navigation',
            'files'         => 'File types and document icons',
            'communication' => 'Communication and messaging icons',
        ];

        return $descriptions[$slug] ?? null;
    }

    /**
     * Count total categories including nested
     */
    protected function countCategories(array $categories): int
    {
        $count = count($categories);

        foreach ($categories as $category) {
            if (! empty($category['children'])) {
                $count += $this->countCategories($category['children']);
            }
        }

        return $count;
    }

    /**
     * Insert terms (supports unlimited nesting).
     *
     * @param string $type category, variant, etc.
     * @param array $items nested term definitions
     * @param int|null $parentId parent term id
     * @param string $package vendor/package name
     */
    protected function insertTerms(string $type, array $items, ?int $parentId, string $package): void
    {
        foreach ($items as $item) {
            $name = $item['name'];
            $slug = $item['slug'] ?? Str::slug($name);
            $description = $item['description'] ?? null;

            /** @var IconTerm $term */
            $term = IconTerm::firstOrNew(
                [
                    'type'    => $type,
                    'slug'    => $slug,
                    'package' => $package,
                ],
                [
                    'name'        => $name,
                    'description' => $description,
                ],
            );

            // Set parent_id safely (avoid circular reference)
            if (! $term->exists) {
                $term->parent_id = $parentId;
                $term->save();
            } elseif ($term->parent_id !== $parentId && $parentId !== $term->id) {
                // Update existing term if parent changed and not circular
                $term->parent_id = $parentId;
                $term->save();
            }

            // Update description if changed
            if ($term->exists && $term->description !== $description && $description !== null) {
                $term->description = $description;
                $term->save();
            }

            // Recursively seed children (use fresh ID, not parent's)
            if (! empty($item['children']) && is_array($item['children'])) {
                // Refresh to ensure we have the correct ID
                $term->refresh();
                $this->insertTerms($type, $item['children'], $term->id, $package);
            }
        }
    }
}
