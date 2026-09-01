<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Feature coverage for `make:icon-package`.
 *
 * Exercises the full scaffold pipeline against a temp directory:
 *   • every stub is emitted (auto-discovery walk)
 *   • placeholders are fully resolved (no leftover `{{token}}`)
 *   • generated PHP is syntactically valid
 *   • generated composer.json is valid JSON with the expected shape
 *   • multi-variant scaffolds get the canonical variant schema that
 *     `JsonConfigConstants::getDefaultVariant()` understands
 */
beforeEach(function () {
    $this->scaffoldRoot = sys_get_temp_dir().'/ichava-make-icon-package-'.uniqid();
});

afterEach(function () {
    if (! empty($this->scaffoldRoot) && is_dir($this->scaffoldRoot)) {
        (new Filesystem)->deleteDirectory($this->scaffoldRoot);
    }
});

/**
 * Pull every `{{token}}` occurrence from a tree, **excluding** Blade
 * comments (`{{-- … --}}`) which are valid output, not stale stubs.
 */
function leftoverPlaceholders(string $root): array
{
    $finder = (new Finder)->in($root)->files()->ignoreDotFiles(false);
    $hits = [];
    foreach ($finder as $file) {
        $content = $file->getContents();
        // Scaffolder placeholders are `{{name}}` with no internal whitespace.
        // Blade output expressions (`{{ helper('arg') }}`) always include spaces.
        if (preg_match_all('/\{\{[a-zA-Z][a-zA-Z0-9_]*\}\}/', $content, $matches)) {
            foreach ($matches[0] as $match) {
                $hits[] = $file->getRelativePathname().': '.$match;
            }
        }
    }

    return $hits;
}

describe('make:icon-package', function () {
    it('scaffolds a complete single-set package with no leftover placeholders', function () {
        $this->artisan('make:icon-package', [
            'name' => 'Hero',
            '--vendor' => 'Acme',
            '--email' => 'team@acme.test',
            '--prefix' => 'hi',
            '--type' => 'single',
            '--path' => $this->scaffoldRoot,
            '--force' => true,
        ])->assertSuccessful();

        // 16 stubs → 16 generated files. Class short names are constants
        // (`IconsServiceProvider`, `IconsConstants`, `Variant`,
        // `IconComponent`, `IconSetTest`), disambiguated by namespace.
        expect(is_dir($this->scaffoldRoot))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/composer.json'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/src/Providers/IconsServiceProvider.php'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/src/Constants/IconsConstants.php'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/src/Enums/Variant.php'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/src/View/Components/IconComponent.php'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/tests/Unit/IconSetTest.php'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/.gitignore'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/.gitattributes'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/CHANGELOG.md'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/LICENSE.md'))->toBeTrue();
        expect(file_exists($this->scaffoldRoot.'/phpunit.xml.dist'))->toBeTrue();

        expect(leftoverPlaceholders($this->scaffoldRoot))->toBe([]);
    });

    it('produces valid composer.json with the right namespace, package name, and provider entry', function () {
        $this->artisan('make:icon-package', [
            'name' => 'Hero',
            '--vendor' => 'Acme',
            '--email' => 'team@acme.test',
            '--prefix' => 'hi',
            '--type' => 'single',
            '--path' => $this->scaffoldRoot,
            '--force' => true,
        ])->assertSuccessful();

        $composer = json_decode(file_get_contents($this->scaffoldRoot.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        expect($composer['name'])->toBe('acme/hero-icons');
        expect($composer['authors'][0]['name'])->toBe('Acme');
        expect($composer['authors'][0]['email'])->toBe('team@acme.test');
        expect($composer['autoload']['psr-4'])->toHaveKey('Acme\\HeroIcons\\');
        expect($composer['autoload-dev']['psr-4'])->toHaveKey('Acme\\HeroIcons\\Tests\\');
        expect($composer['extra']['laravel']['providers'])
            ->toContain('Acme\\HeroIcons\\Providers\\IconsServiceProvider');
    });

    it('lints clean across every generated PHP file', function () {
        $this->artisan('make:icon-package', [
            'name' => 'Hero',
            '--vendor' => 'Acme',
            '--email' => 'team@acme.test',
            '--prefix' => 'hi',
            '--type' => 'single',
            '--path' => $this->scaffoldRoot,
            '--force' => true,
        ])->assertSuccessful();

        $finder = (new Finder)->in($this->scaffoldRoot)->files()->name('*.php');
        foreach ($finder as $file) {
            $output = [];
            $exit = 0;
            exec('php -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $exit);
            expect($exit)->toBe(0, 'php -l failed for '.$file->getRelativePathname().": \n".implode("\n", $output));
        }
    });

    it('normalises a vendor name with spaces into a kebab composer name and a studly namespace', function () {
        $this->artisan('make:icon-package', [
            'name' => 'Hero',
            '--vendor' => 'Your Company',
            '--email' => 'team@example.test',
            '--prefix' => 'hi',
            '--type' => 'single',
            '--path' => $this->scaffoldRoot,
            '--force' => true,
        ])->assertSuccessful();

        $composer = json_decode(file_get_contents($this->scaffoldRoot.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($composer['name'])->toBe('your-company/hero-icons');
        expect($composer['autoload']['psr-4'])->toHaveKey('YourCompany\\HeroIcons\\');

        $providerSrc = file_get_contents($this->scaffoldRoot.'/src/Providers/IconsServiceProvider.php');
        expect($providerSrc)->toContain('namespace YourCompany\\HeroIcons\\Providers;');
    });

    it('emits the canonical variant schema for multi-set packages', function () {
        $this->artisan('make:icon-package', [
            'name' => 'Hero',
            '--vendor' => 'Acme',
            '--email' => 'team@acme.test',
            '--prefix' => 'hi',
            '--type' => 'multi',
            '--variants' => 'outline,solid,duotone',
            '--path' => $this->scaffoldRoot,
            '--force' => true,
        ])->assertSuccessful();

        $config = json_decode(
            file_get_contents($this->scaffoldRoot.'/resources/assets/svg/config.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $variants = $config['metadata']['data']['variants'];
        expect($variants)->toHaveKeys(['outline', 'solid', 'duotone']);

        // First variant is the canonical default with empty suffix
        expect($variants['outline']['default'])->toBeTrue();
        expect($variants['outline']['icon_suffix'])->toBe('');
        expect($variants['outline']['display_order'])->toBe(1);

        // Subsequent variants are non-default with `-<slug>` suffix
        expect($variants['solid']['default'])->toBeFalse();
        expect($variants['solid']['icon_suffix'])->toBe('-solid');
        expect($variants['solid']['display_order'])->toBe(2);

        expect($variants['duotone']['default'])->toBeFalse();
        expect($variants['duotone']['icon_suffix'])->toBe('-duotone');
        expect($variants['duotone']['display_order'])->toBe(3);

        // Schema fields required by JsonConfigConstants accessors
        foreach (['outline', 'solid', 'duotone'] as $slug) {
            expect($variants[$slug])->toHaveKeys([
                'name', 'slug', 'description', 'default',
                'icon_suffix', 'display_order', 'attributes',
                'preview_icon', 'color_scheme',
            ]);
        }

        // Multi-set creates a sub-folder per variant with a .gitkeep
        expect(is_dir($this->scaffoldRoot.'/resources/assets/svg/files/outline'))->toBeTrue();
        expect(is_dir($this->scaffoldRoot.'/resources/assets/svg/files/solid'))->toBeTrue();
        expect(is_dir($this->scaffoldRoot.'/resources/assets/svg/files/duotone'))->toBeTrue();
    });
});
