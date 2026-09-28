<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;
use Simtabi\Laranail\Ichava\Support\Seeder\IconSeederHelpers;

function snippetService(): IconBrowserService
{
    return app(IconBrowserService::class);
}

function snippetIcon(string $package, string $name, ?string $path): Icon
{
    return new Icon([
        'package' => $package,
        'name'    => $name,
        'path'    => $path,
    ]);
}

describe('Icon snippet full paths', function () {
    it('emits the variant segment for nested icons', function () {
        $icon = snippetIcon('ichava/icon-sets-tabler', 'ad-circle', 'files/outline/ad-circle.svg');

        expect(snippetService()->generateBladeComponent($icon))
            ->toBe('<x-ichava::icon name="ichava/icon-sets-tabler::outline/ad-circle" class="w-6 h-6" />');
        expect(snippetService()->generateHelperCode($icon))
            ->toBe("{{ ichava('ichava/icon-sets-tabler::outline/ad-circle')->class('w-6 h-6') }}");
    });

    it('emits plain names for root-level icons', function () {
        $icon = snippetIcon('ichava/icon-sets-tabler', 'home', 'files/home.svg');

        expect(snippetService()->generateBladeComponent($icon))
            ->toBe('<x-ichava::icon name="ichava/icon-sets-tabler::home" class="w-6 h-6" />');
    });

    it('keeps deeper nesting in order', function () {
        $icon = snippetIcon('ichava/icons-bundle', 'facebook', 'files/brand-logos/social/facebook.svg');

        expect(snippetService()->fullIconPath($icon))
            ->toBe('ichava/icons-bundle::brand-logos/social/facebook');
    });

    it('emits short refs with full segments for the generic grammar', function () {
        $icon = snippetIcon('ichava/icon-sets-tabler', 'ad-circle', 'files/filled/ad-circle.svg');

        expect(snippetService()->generateBladeComponent($icon, false))
            ->toBe('<x-ichava-icon name="icon-sets-tabler:filled/ad-circle" class="w-6 h-6" />');
        expect(snippetService()->shortIconRef($icon))->toBe('icon-sets-tabler:filled/ad-circle');
    });

    it('falls back to the bare name when no stored path exists', function () {
        $icon = snippetIcon('ichava/icon-sets-tabler', 'home', null);

        expect(snippetService()->fullIconPath($icon))->toBe('ichava/icon-sets-tabler::home');
        expect(snippetService()->shortIconRef($icon))->toBe('icon-sets-tabler:home');
    });
});

describe('Seeder taxonomy extraction', function () {
    it('extracts the variant only when the folder is a declared variant', function () {
        $probe = new class
        {
            use IconSeederHelpers;

            public function variant(string $path, array $map): ?string
            {
                return $this->extractVariantSlug($path, $map);
            }
        };

        $map = ['outline' => 5, 'filled' => 6];

        expect($probe->variant('files/outline/a-b.svg', $map))->toBe('outline');
        expect($probe->variant('files/filled/a-b.svg', $map))->toBe('filled');
        expect($probe->variant('files/test-icons/triangle.svg', $map))->toBeNull();
        expect($probe->variant('outline/a-b.svg', $map))->toBe('outline');
    });

    it('writes term attachments with the registered morph alias', function () {
        // SeedIconsJob::insertTermables() stores (new Icon)->getMorphClass();
        // every reader (relations, getMorphClass joins) queries the same value.
        expect((new Icon)->getMorphClass())->toBe('icon');
    });
});
