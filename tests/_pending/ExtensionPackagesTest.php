<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Simtabi\Laranail\Ichava\Services\IconRegistry;

/**
 * Cross-package integration coverage. PENDING -- these tests document the
 * intended public contract of the icon ecosystem (browser API + IconRegistry
 * lookups) but require a host project with at least one icon pack installed
 * and seeded. Each test now skips with a clear reason when its prerequisite
 * is missing instead of silently passing via expect(true)->toBeTrue().
 *
 * To revive: composer require an icon pack + run `php artisan ichava:database
 * seed`, then move this file to tests/Feature/Integration/ and remove the
 * skip guards on each test.
 */
uses(RefreshDatabase::class);

describe('Extension Packages -- Icons Bundle Integration', function () {
    it('loads icons bundle package if installed', function () {
        $registry = app(IconRegistry::class);
        $bundleKeys = array_filter(array_keys($registry->all()), fn ($k) => str_starts_with($k, 'ichava/icons-bundle'));

        if (empty($bundleKeys)) {
            test()->markTestSkipped('ichava/icons-bundle (or descendant) not registered in this composer install');
        }

        $packageData = $registry->get(reset($bundleKeys));
        expect($packageData)->toBeArray()->toHaveKey('base_path');
    });

    it('can retrieve icons from bundle package via API if installed', function () {
        $bundleIcons = Icon::where('package', 'LIKE', '%icons-bundle%')->count();
        if ($bundleIcons === 0) {
            test()->markTestSkipped('No bundle icons seeded in this composer install');
        }

        test()->getJson(route('ichava.api.icons.index', ['packages' => ['ichava/icons-bundle']]))
            ->assertOk();
    });

    it('includes bundle categories if icons are seeded', function () {
        $bundleCategories = IconTerm::where('package', 'LIKE', '%icons-bundle%')->where('type', 'category')->count();
        if ($bundleCategories === 0) {
            test()->markTestSkipped('No bundle categories seeded');
        }

        test()->getJson(route('ichava.api.terms.categories'))->assertOk();
    });
});

describe('Extension Packages -- Tabler Icons Integration', function () {
    it('loads tabler icons package if installed', function () {
        $registry = app(IconRegistry::class);
        if (! isset($registry->all()['ichava/tabler-icons'])) {
            test()->markTestSkipped('ichava/tabler-icons not registered');
        }

        expect($registry->get('ichava/tabler-icons'))
            ->toBeArray()
            ->toHaveKeys(['base_path', 'browser_metadata']);
    });

    it('can retrieve tabler icons via API if seeded', function () {
        if (Icon::where('package', 'ichava/tabler-icons')->count() === 0) {
            test()->markTestSkipped('Tabler icons not seeded');
        }

        $icons = test()->getJson(route('ichava.api.icons.index', [
            'packages' => ['ichava/tabler-icons'],
            'per_page' => 20,
        ]))->assertOk()->json('data');

        expect($icons)->not->toBeEmpty();
        foreach ($icons as $icon) {
            expect($icon['package'])->toBe('ichava/tabler-icons');
        }
    });

    it('filters tabler icons by variant if available', function () {
        $variants = IconTerm::where('package', 'ichava/tabler-icons')->where('type', 'variant')->pluck('slug')->toArray();
        if (empty($variants)) {
            test()->markTestSkipped('No tabler variants seeded');
        }

        $variant = $variants[0];
        $icons = test()->getJson(route('ichava.api.icons.index', [
            'packages' => ['ichava/tabler-icons'],
            'variants' => [$variant],
            'per_page' => 10,
        ]))->assertOk()->json('data');

        foreach ($icons as $icon) {
            expect($icon['variant'])->toBe($variant);
        }
    });

    it('can fetch tabler package details if installed', function () {
        if (app(IconRegistry::class)->get('ichava/tabler-icons') === null) {
            test()->markTestSkipped('ichava/tabler-icons not registered');
        }

        test()->getJson(route('ichava.api.packages.show', ['package' => 'ichava/tabler-icons']))
            ->assertOk()
            ->assertJsonStructure(['data' => ['name', 'label', 'icon_count', 'categories', 'variants']])
            ->assertJsonPath('data.name', 'ichava/tabler-icons');
    });
});

describe('Extension Packages -- Metronic Icons Integration', function () {
    it('loads metronic icons package if installed', function () {
        $registry = app(IconRegistry::class);
        if (! isset($registry->all()['ichava/metronic-icons'])) {
            test()->markTestSkipped('ichava/metronic-icons not registered');
        }

        expect($registry->get('ichava/metronic-icons'))
            ->toBeArray()
            ->toHaveKeys(['base_path', 'browser_metadata']);
    });

    it('can retrieve metronic icons via API if seeded', function () {
        if (Icon::where('package', 'ichava/metronic-icons')->count() === 0) {
            test()->markTestSkipped('Metronic icons not seeded');
        }

        $icons = test()->getJson(route('ichava.api.icons.index', [
            'packages' => ['ichava/metronic-icons'],
            'per_page' => 20,
        ]))->assertOk()->json('data');

        expect($icons)->not->toBeEmpty();
        foreach ($icons as $icon) {
            expect($icon['package'])->toBe('ichava/metronic-icons');
        }
    });

    it('filters metronic icons by category if available', function () {
        $categories = IconTerm::where('package', 'ichava/metronic-icons')->where('type', 'category')->pluck('slug')->toArray();
        if (empty($categories)) {
            test()->markTestSkipped('No metronic categories seeded');
        }

        $category = $categories[0];
        $icons = test()->getJson(route('ichava.api.icons.index', [
            'packages'   => ['ichava/metronic-icons'],
            'categories' => [$category],
            'per_page'   => 10,
        ]))->assertOk()->json('data');

        foreach ($icons as $icon) {
            expect($icon['category'])->toBe($category);
        }
    });

    it('can fetch metronic package details if installed', function () {
        if (app(IconRegistry::class)->get('ichava/metronic-icons') === null) {
            test()->markTestSkipped('ichava/metronic-icons not registered');
        }

        test()->getJson(route('ichava.api.packages.show', ['package' => 'ichava/metronic-icons']))
            ->assertOk()
            ->assertJsonStructure(['data' => ['name', 'label', 'icon_count', 'categories', 'variants']])
            ->assertJsonPath('data.name', 'ichava/metronic-icons');
    });
});

describe('Cross-Package Filtering', function () {
    it('can search across all installed packages', function () {
        if (Icon::count() === 0) {
            test()->markTestSkipped('No icons seeded');
        }

        $icons = test()->getJson(route('ichava.api.icons.index', ['per_page' => 30]))
            ->assertOk()
            ->json('data');

        expect($icons)->toBeArray();
    });

    it('can filter by multiple packages simultaneously', function () {
        $packages = Icon::distinct('package')->pluck('package')->take(2)->toArray();
        if (count($packages) < 2) {
            test()->markTestSkipped('Need at least 2 distinct packages seeded');
        }

        $icons = test()->getJson(route('ichava.api.icons.index', [
            'packages' => $packages,
            'per_page' => 30,
        ]))->assertOk()->json('data');

        foreach ($icons as $icon) {
            expect($packages)->toContain($icon['package']);
        }
    });

    it('returns accurate icon counts per package', function () {
        $packages = test()->getJson(route('ichava.api.packages.index'))
            ->assertOk()
            ->json('data');

        if (empty($packages)) {
            test()->markTestSkipped('No packages registered');
        }

        foreach ($packages as $package) {
            expect($package)->toHaveKey('count');
            expect($package['count'])->toBeInt()->toBeGreaterThanOrEqual(0);
            expect($package['count'])->toBe(Icon::where('package', $package['name'])->count());
        }
    });
});

describe('Package Registration', function () {
    it('has at least one package registered', function () {
        $packages = app(IconRegistry::class)->all();
        if (empty($packages)) {
            test()->markTestSkipped('No icon packs registered in this composer install');
        }

        expect($packages)->toBeArray()->not->toBeEmpty();
    });

    it('every registered package exposes a base_path', function () {
        $packages = app(IconRegistry::class)->all();
        if (empty($packages)) {
            test()->markTestSkipped('No icon packs registered');
        }

        foreach ($packages as $packageData) {
            expect($packageData)->toBeArray()->toHaveKey('base_path');
        }
    });
});
