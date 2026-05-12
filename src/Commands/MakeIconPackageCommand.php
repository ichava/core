<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Artisan command to scaffold a new Ichava icon package
 *
 * Creates the complete structure for a new icon package including:
 * - Service provider
 * - Constants class
 * - Icon set class
 * - Configuration file
 * - Test files
 * - README and documentation
 *
 * Uses stub files from stubs/icon-package/ directory.
 *
 * @api
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
class MakeIconPackageCommand extends BaseCommand
{
    protected $signature = 'make:icon-package
                           {name? : The name of the icon package (e.g., HeroIcons)}
                           {--vendor= : Vendor name (default: YourVendor)}
                           {--email= : Author email address}
                           {--path= : Destination path for the package (required)}
                           {--prefix= : Blade component prefix (default: kebab-case of name)}
                           {--type= : Icon set type: single or multi}
                           {--variants= : Comma-separated variant names for multi-set (e.g., regular,solid,outline)}
                           {--force : Overwrite existing package}';

    protected $description = 'Scaffold a new Ichava icon package with all necessary files';

    protected Filesystem $files;

    /**
     * Stub replacements map
     */
    protected array $replacements = [];

    /**
     * Icon set type (single or multi)
     */
    protected string $iconSetType = 'single';

    /**
     * Variant names for multi-set packages
     */
    protected array $variants = [];

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        intro('📦 Create New Ichava Icon Package');

        // Get package name
        $name = $this->getPackageName();

        // Get a vendor name
        $vendor = $this->getVendorName();

        // Get author email
        $email = $this->getAuthorEmail();

        // Get prefix
        $prefix = $this->getPrefix($name);

        // Get icon set type (single or multi)
        $this->iconSetType = $this->getIconSetType();

        // Get variants for multi-set
        if ($this->iconSetType === 'multi') {
            $this->variants = $this->getVariants();
        }

        $studlyName = Str::studly($name);
        $kebabName = Str::kebab($name);

        // Get and validate path
        $path = $this->getValidatedPath($kebabName);

        if ($path === null) {
            return self::FAILURE;
        }

        // Build replacements map
        $this->buildReplacements($vendor, $studlyName, $kebabName, $prefix, $email, $this->iconSetType, $this->variants);

        $packageName = $this->replacements['{{packageName}}'];
        info("Creating icon package: {$packageName}");

        // Check if a directory exists
        if ($this->files->isDirectory($path) && ! $this->option('force')) {
            $overwrite = confirm(
                label: "Package directory already exists at: {$path}. Overwrite?",
                default: false,
                yes: 'Yes, overwrite',
                no: 'No, cancel',
                hint: '⚠️ Existing files will be replaced!'
            );

            if (! $overwrite) {
                warning('Operation cancelled.');

                return self::FAILURE;
            }
        }

        // Generate files from stubs (auto-discovers every *.stub under the stubs root)
        $this->generateFiles($path);

        // Create icon files directory structure based on type
        $this->createIconFilesStructure($path);

        $this->displayNextSteps($path);

        outro('🎉 Icon package scaffolded successfully!');

        return self::SUCCESS;
    }

    /**
     * Get package name from argument or prompt
     */
    protected function getPackageName(): string
    {
        $name = $this->argument('name');

        if (empty($name)) {
            $name = text(
                label: 'Package name',
                placeholder: 'e.g., HeroIcons, FontAwesome, MyIcons',
                required: 'Package name is required',
                validate: fn (string $value) => strlen($value) < 2
                    ? 'Package name must be at least 2 characters'
                    : null,
                hint: 'The name will be converted to StudlyCase automatically'
            );
        }

        return $name;
    }

    /**
     * Get vendor name from option or prompt
     */
    protected function getVendorName(): string
    {
        $vendor = $this->option('vendor');

        if (empty($vendor)) {
            $vendor = text(
                label: 'Vendor name',
                placeholder: 'e.g., YourCompany, MyOrg',
                default: 'YourVendor',
                required: 'Vendor name is required',
                hint: 'Used in namespace and composer package name'
            );
        }

        return $vendor;
    }

    /**
     * Get author email from option or prompt
     */
    protected function getAuthorEmail(): string
    {
        $email = $this->option('email');

        if (empty($email)) {
            $email = text(
                label: 'Author email address',
                placeholder: 'your-email@example.com',
                required: 'Email address is required',
                validate: fn (string $value) => ! filter_var($value, FILTER_VALIDATE_EMAIL)
                    ? 'Please enter a valid email address'
                    : null,
                hint: 'Used in composer.json author field'
            );
        }

        return $email;
    }

    /**
     * Get prefix from option or derive from name
     */
    protected function getPrefix(string $name): string
    {
        $prefix = $this->option('prefix');

        if (empty($prefix)) {
            $defaultPrefix = Str::kebab($name);

            $prefix = text(
                label: 'Blade component prefix',
                placeholder: $defaultPrefix,
                default: $defaultPrefix,
                hint: 'Used for Blade components: <x-{prefix}-icon name="..." />'
            );
        }

        return $prefix ?: Str::kebab($name);
    }

    /**
     * Get icon set type from option or prompt
     */
    protected function getIconSetType(): string
    {
        $type = $this->option('type');

        if (empty($type)) {
            $type = select(
                label: 'Icon set type',
                options: [
                    'single' => 'Single-set - All icons in one folder (e.g., files/*.svg)',
                    'multi' => 'Multi-set - Icons organized by variant (e.g., files/regular/*.svg, files/solid/*.svg)',
                ],
                default: 'single',
                hint: 'Choose how your icons will be organized'
            );
        }

        if (! in_array($type, ['single', 'multi'])) {
            warning("Invalid type '{$type}', defaulting to 'single'");
            $type = 'single';
        }

        return $type;
    }

    /**
     * Get variant names for multi-set packages
     */
    protected function getVariants(): array
    {
        $variantsOption = $this->option('variants');

        if (! empty($variantsOption)) {
            return array_map('trim', explode(',', $variantsOption));
        }

        // Common variant presets
        $presets = [
            'custom' => 'Custom - Enter your own variant names',
            'regular,solid' => 'Regular + Solid (2 variants)',
            'outline,solid' => 'Outline + Solid (2 variants)',
            'regular,solid,outline' => 'Regular + Solid + Outline (3 variants)',
            'thin,light,regular,bold,fill' => 'Thin + Light + Regular + Bold + Fill (5 variants)',
        ];

        $selected = select(
            label: 'Select variant preset or choose custom',
            options: $presets,
            default: 'regular,solid',
            hint: 'Variants define different styles of the same icon'
        );

        if ($selected === 'custom') {
            $customVariants = text(
                label: 'Enter variant names (comma-separated)',
                placeholder: 'e.g., regular, solid, outline, filled',
                required: 'At least one variant is required',
                hint: 'These will become subdirectories in files/',
                validate: function (string $value) {
                    $variants = array_filter(array_map('trim', explode(',', $value)));
                    if (count($variants) < 1) {
                        return 'Please enter at least one variant name';
                    }

                    return null;
                }
            );

            return array_filter(array_map('trim', explode(',', $customVariants)));
        }

        return array_map('trim', explode(',', $selected));
    }

    /**
     * Get and validate the destination path
     */
    protected function getValidatedPath(string $kebabName): ?string
    {
        $path = $this->option('path');

        // If no path provided, ask the user
        if (empty($path)) {
            $path = text(
                label: 'Destination path for the icon package',
                placeholder: "ichava-{$kebabName}-icons",
                default: base_path("ichava-{$kebabName}-icons"),
                required: 'A destination path is required',
                hint: "Absolute path or relative to project root. The Ichava ecosystem convention is `ichava-{$kebabName}-icons`.",
                validate: function (string $value) {
                    if (empty(trim($value))) {
                        return 'Path cannot be empty';
                    }

                    return null;
                }
            );
        }

        // Validate the path
        if (empty($path)) {
            $this->failure('A destination path is required.');

            return null;
        }

        // Normalize the path
        $path = rtrim($path, '/\\');

        // Check if path is absolute, if not make it relative to base_path
        if (! $this->isAbsolutePath($path)) {
            $path = base_path($path);
        }

        // Validate parent directory exists or can be created
        $parentDir = dirname($path);

        if (! $this->files->isDirectory($parentDir)) {
            $createParent = confirm(
                label: "Parent directory '{$parentDir}' does not exist. Create it?",
                default: true,
                yes: 'Yes, create it',
                no: 'No, cancel'
            );

            if (! $createParent) {
                $this->failure('Cannot create package without a valid parent directory.');

                return null;
            }

            try {
                $this->files->ensureDirectoryExists($parentDir);
            } catch (\Exception $e) {
                $this->failure("Failed to create parent directory: {$e->getMessage()}");

                return null;
            }
        }

        // Check if parent directory is writable
        if (! $this->files->isWritable($parentDir)) {
            $this->failure("Parent directory is not writable: {$parentDir}");

            return null;
        }

        return $path;
    }

    /**
     * Generate every file in the stubs directory tree.
     *
     * Walks `stubs/icon-package/` recursively and for each file:
     *   1. Strips a trailing `.stub` suffix from the destination filename if
     *      one is present (the suffix is *optional*, it exists purely so
     *      contributors can keep stubs out of language tooling indices; a
     *      file already named `.editorconfig` or `LICENSE` will still be
     *      copied verbatim).
     *   2. Substitutes mustache placeholders inside the path itself
     *      (e.g. `{{studlyName}}`) using the same replacement map applied
     *      to file contents, one consistent token language across both.
     *   3. Renders the file contents with {@see replaceStubPlaceholders()}.
     *   4. Writes to `{$path}/{relative-dest}`, creating parent directories
     *      as needed.
     *
     * Adding a new file to the scaffold is a single, declarative operation:
     * drop the file into `stubs/icon-package/` and use mustache placeholders
     * wherever variable values are required (in the filename, in the path
     * segments, or in the contents). No command changes required.
     */
    protected function generateFiles(string $path): void
    {
        $stubsRoot = $this->getStubsPath();
        $stubFiles = $this->discoverStubs($stubsRoot);

        if (empty($stubFiles)) {
            warning("No stub files found in: {$stubsRoot}");

            return;
        }

        $count = count($stubFiles);

        spin(
            callback: function () use ($stubFiles, $stubsRoot, $path) {
                foreach ($stubFiles as $stubAbsPath) {
                    $relativeStub = $this->relativeStubPath($stubsRoot, $stubAbsPath);
                    $relativeDest = $this->resolveStubDestination($relativeStub);

                    $this->generateFromStub($relativeStub, "{$path}/{$relativeDest}");
                }
            },
            message: "Generating {$count} files from stubs..."
        );

        info("✅ Generated {$count} files from stubs");
    }

    /**
     * Recursively discover every file under the stubs root, sorted for a
     * deterministic generation order. The `.stub` suffix is *not* required ,
     * any file present in the tree is treated as a stub. Hidden files
     * (e.g. `.gitignore.stub`, `.editorconfig`) are included; common OS
     * cruft is filtered out.
     *
     * @return string[] Absolute paths to each stub file.
     */
    protected function discoverStubs(string $stubsRoot): array
    {
        if (! $this->files->isDirectory($stubsRoot)) {
            return [];
        }

        $finder = Finder::create()
            ->in($stubsRoot)
            ->files()
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            ->notName(['.DS_Store', 'Thumbs.db', 'desktop.ini'])
            ->sortByName();

        $paths = [];
        foreach ($finder as $file) {
            $paths[] = $file->getPathname();
        }

        return $paths;
    }

    /**
     * Convert an absolute stub path to a stubs-root-relative path using
     * forward slashes, regardless of host OS separator.
     */
    protected function relativeStubPath(string $stubsRoot, string $absolutePath): string
    {
        $relative = ltrim(substr($absolutePath, strlen($stubsRoot)), '/\\');

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    /**
     * Resolve a stub's destination filename: strip a trailing `.stub` suffix
     * (when present) and substitute mustache filename placeholders using the
     * same replacement map as file contents.
     *
     * Filename tokens use the same `{{token}}` mustache form as content
     * tokens, one convention everywhere, no special filename grammar to
     * remember. The braces are valid filename characters on every supported
     * OS, and the placeholders only ever exist inside the `stubs/` tree.
     */
    protected function resolveStubDestination(string $relativeStubPath): string
    {
        $dest = preg_replace('/\.stub$/', '', $relativeStubPath);

        return str_replace(
            array_keys($this->replacements),
            array_values($this->replacements),
            $dest
        );
    }

    /**
     * Display next steps after scaffolding.
     *
     * The composer.json, psr-4 autoload, and `extra.laravel.providers` entry
     * are all generated correctly by the stubs, Laravel's package
     * auto-discovery picks up the provider on the next `composer install`
     * with no manual `config/app.php` edit. The remaining steps are: drop
     * SVGs into the right folder, fine-tune the metadata, and require the
     * package from a host application.
     */
    protected function displayNextSteps(string $path): void
    {
        $packageName = $this->replacements['{{packageName}}'] ?? '';
        $iconsRoot = "{$path}/resources/assets/svg/files/";

        $this->newLine();
        note('📋 Next steps:');
        $this->line("  1. Drop your SVG icons into: {$iconsRoot}");

        if ($this->iconSetType === 'multi' && ! empty($this->variants)) {
            $first = Str::slug($this->variants[0]);
            $this->line("     (one sub-folder per variant, e.g. {$iconsRoot}{$first}/home.svg)");
        }

        $this->line('  2. Fine-tune resources/assets/svg/config.json (description, homepage, repository).');
        $this->line('  3. (Optional) Run `composer install` inside the package to install dev dependencies.');
        $this->line("  4. From a host Laravel app, require the package: `composer require {$packageName}`");
        $this->line('     The service provider is auto-discovered; no config/app.php edits required.');
        $this->newLine();
        note('💡 All package metadata is driven by config.json, no hardcoded constants.');
    }

    /**
     * Build the replacements map for stub processing.
     *
     * Tokens are grouped by intent so the right form is used in each
     * context; mixing them up is the most common scaffolding bug.
     *
     *  • Vendor variants, pick the form that matches the destination
     *    grammar: `vendorStudly` for PHP namespaces, `vendorKebab` for
     *    composer / URL paths, `vendorSnake` for env-var prefixes,
     *    `vendor` for human-facing display.
     *  • Package-name variants, `studlyName` for class names,
     *    `camelName` for variables, `kebabName` for routes/composer
     *    fragments, `snakeName` for tables/env, `humanName` for prose.
     *  • Composed identifiers, pre-assembled `namespace`,
     *    `namespaceEscaped`, `packageName`, and `bladeNamespace` so
     *    stubs don't have to re-glue tokens.
     *
     * Class short names (`IconsServiceProvider`, `IconsConstants`,
     * `IconComponent`, `Variant`) are **constants**, not tokens, every
     * scaffolded package uses the same names, disambiguated by
     * namespace. This eliminates a class of name-collision bugs
     * (e.g. `HeroIconsIconsConstants` when a user typed `HeroIcons`
     * instead of `Hero`) and keeps stubs free of filename grammar.
     *
     * Version constraints (PHP, Laravel, Testbench, Pest, etc.) are *not*
     * tokenised, they live as literals inside `composer.json.stub`. The
     * supported matrix changes infrequently and editing the stub directly
     * is clearer than indirection through the replacement map.
     */
    protected function buildReplacements(string $vendor, string $studlyName, string $kebabName, string $prefix, string $email, string $type, array $variants): void
    {
        $variantsJson = $this->buildVariantsJson($variants);

        $vendorStudly = Str::studly($vendor);
        $vendorKebab = Str::kebab($vendor);
        $vendorSnake = Str::snake($vendor);

        $namespace = $vendorStudly.'\\'.$studlyName.'Icons';
        $namespaceEscaped = str_replace('\\', '\\\\', $namespace);
        $packageName = $vendorKebab.'/'.$kebabName.'-icons';
        $humanName = ucwords(str_replace('-', ' ', $kebabName));

        $this->replacements = [
            // Vendor variants
            '{{vendor}}' => $vendor,
            '{{vendorStudly}}' => $vendorStudly,
            '{{vendorLower}}' => Str::lower($vendor),
            '{{vendorKebab}}' => $vendorKebab,
            '{{vendorSnake}}' => $vendorSnake,

            // Package-name variants
            '{{studlyName}}' => $studlyName,
            '{{camelName}}' => Str::camel($studlyName),
            '{{kebabName}}' => $kebabName,
            '{{snakeName}}' => Str::snake($studlyName),
            '{{snakeNameUpper}}' => Str::upper(Str::snake($studlyName)),
            '{{humanName}}' => $humanName,

            // Composed identifiers
            '{{namespace}}' => $namespace,
            '{{namespaceEscaped}}' => $namespaceEscaped,
            '{{packageName}}' => $packageName,
            '{{bladeNamespace}}' => $kebabName.'-icons',

            // Author / metadata
            '{{prefix}}' => $prefix,
            '{{email}}' => $email,
            '{{year}}' => date('Y'),
            '{{date}}' => date('Y-m-d'),
            '{{iconSetType}}' => $type,
            '{{variantsJson}}' => $variantsJson,
        ];
    }

    /**
     * Build the variants JSON structure for `config.json`.
     *
     * Emits the full schema expected by `JsonConfigConstants`:
     * `default`, `icon_suffix`, `display_order`, `attributes`,
     * `description`, `preview_icon`, `color_scheme`. Without these,
     * `getDefaultVariant()` silently falls back to `array_key_first()`
     * and `getVariantSuffix()` returns an empty string for every
     * variant, both bugs that only surface at icon-render time.
     *
     * Convention applied to scaffolded packages:
     *   • The first variant is flagged `default: true` with empty
     *     `icon_suffix` (its icons live at `files/<slug>/icon.svg`).
     *   • Subsequent variants get `icon_suffix: "-<slug>"` so two
     *     conventions are supported: per-variant subdirectories
     *     (`files/solid/home.svg`) *or* suffix-on-name within a
     *     shared folder (`files/home-solid.svg`). The user's choice
     *     of layout is honoured by `JsonConfigConstants` either way.
     */
    protected function buildVariantsJson(array $variants): string
    {
        if (empty($variants)) {
            return '{}';
        }

        $variantsData = [];
        $order = 1;
        foreach ($variants as $variant) {
            $slug = Str::slug($variant);
            $isDefault = $order === 1;
            $variantsData[$slug] = [
                'name' => Str::title($variant),
                'slug' => $slug,
                'description' => Str::title($variant).' icon style',
                'default' => $isDefault,
                'icon_suffix' => $isDefault ? '' : '-'.$slug,
                'display_order' => $order,
                'attributes' => (object) [],
                'preview_icon' => 'home',
                'color_scheme' => 'adaptive',
            ];
            $order++;
        }

        return json_encode($variantsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get the path to the stubs directory
     */
    protected function getStubsPath(): string
    {
        return dirname(__DIR__, 2).'/stubs/icon-package';
    }

    /**
     * Generate a file from a stub template
     */
    protected function generateFromStub(string $stubName, string $destinationPath): void
    {
        $stubPath = $this->getStubsPath().'/'.$stubName;

        if (! $this->files->exists($stubPath)) {
            throw new \RuntimeException("Stub file not found: {$stubPath}");
        }

        $content = $this->files->get($stubPath);
        $content = $this->replaceStubPlaceholders($content);

        // Ensure destination directory exists
        $this->files->ensureDirectoryExists(dirname($destinationPath));

        $this->files->put($destinationPath, $content);
    }

    /**
     * Replace all placeholders in stub content
     */
    protected function replaceStubPlaceholders(string $content): string
    {
        return str_replace(
            array_keys($this->replacements),
            array_values($this->replacements),
            $content
        );
    }

    /**
     * Create icon files directory structure based on type
     */
    protected function createIconFilesStructure(string $path): void
    {
        $filesPath = "{$path}/resources/assets/svg/files";

        if ($this->iconSetType === 'multi' && ! empty($this->variants)) {
            // Multi-set: Create subdirectory for each variant
            foreach ($this->variants as $variant) {
                $variantSlug = Str::slug($variant);
                $variantPath = "{$filesPath}/{$variantSlug}";
                $this->files->ensureDirectoryExists($variantPath);

                // Create .gitkeep in each variant folder
                $this->files->put("{$variantPath}/.gitkeep", "# Place your {$variant} SVG icons here\n");
            }

            info('✅ Created multi-set structure with variants: '.implode(', ', $this->variants));
        } else {
            // Single-set: Just create the files directory
            $this->files->ensureDirectoryExists($filesPath);
            $this->files->put("{$filesPath}/.gitkeep", "# Place your SVG icons here\n");

            info('✅ Created single-set structure');
        }
    }

    /**
     * Check if a path is absolute
     */
    protected function isAbsolutePath(string $path): bool
    {
        // Unix absolute path
        if (Str::startsWith($path, '/')) {
            return true;
        }

        // Windows absolute path (e.g., C:\, D:\)
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
            return true;
        }

        return false;
    }
}
