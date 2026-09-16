<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\IconSetCatalogService;

beforeEach(function () {
    $this->service = new IconSetCatalogService(
        new Filesystem,
        app(IconRegistry::class),
    );
});

describe('IconSetCatalogService::load', function () {
    it('loads the synced snapshot for every set', function () {
        $sets = $this->service->load();

        expect($sets)->toHaveCount(2);

        $tabler = collect($sets)->firstWhere('key', 'tabler');
        $flags = collect($sets)->firstWhere('key', 'flags');

        expect($tabler)->toMatchArray([
            'package'        => 'ichava/tabler-icons',
            'repository'     => 'ichava/tabler-icons',
            'title'          => 'Tabler Icons',
            'icon_count'     => 6146,
            'variants'       => ['outline', 'filled'],
            'latest_version' => '0.1.0',
        ])->and($flags)->toMatchArray([
            'package'        => 'ichava/flag-icons',
            'repository'     => 'ichava/flag-icons',
            'title'          => 'Flag Icons',
            'icon_count'     => 542,
            'variants'       => ['4x3', '1x1'],
            'latest_version' => '0.1.0',
        ]);
    });

    it('throws when the catalog is missing', function () {
        $service = new IconSetCatalogService(
            new Filesystem,
            app(IconRegistry::class),
            sys_get_temp_dir() . '/ichava-no-such-catalog.json',
        );

        expect(fn () => $service->load())->toThrow(IchavaException::class);
    });

    it('throws when a set is missing required fields', function () {
        $path = sys_get_temp_dir() . '/ichava-catalog-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode(['sets' => [['key' => 'broken']]]), LOCK_EX);

        $service = new IconSetCatalogService(new Filesystem, app(IconRegistry::class), $path);

        try {
            expect(fn () => $service->load())->toThrow(IchavaException::class);
        } finally {
            @unlink($path);
        }
    });
});

describe('IconSetCatalogService::find', function () {
    it('finds a set by key or package name', function () {
        expect($this->service->find('tabler')['package'])->toBe('ichava/tabler-icons')
            ->and($this->service->find('ichava/flag-icons')['key'])->toBe('flags')
            ->and($this->service->find('no-such-set'))->toBeNull();
    });
});

describe('IconSetCatalogService::latestTag', function () {
    it('returns the synced release version', function () {
        expect($this->service->latestTag('ichava/tabler-icons'))->toBe('0.1.0')
            ->and($this->service->latestTag('ichava/flag-icons'))->toBe('0.1.0')
            ->and($this->service->requireTarget('ichava/tabler-icons'))->toBe('ichava/tabler-icons:^0.1.0');
    });

    it('returns null and an unconstrained target for unknown packages', function () {
        expect($this->service->latestTag('ichava/nope'))->toBeNull()
            ->and($this->service->requireTarget('ichava/nope'))->toBe('ichava/nope');
    });
});

describe('IconSetCatalogService::all', function () {
    it('reports seeded=false when tables exist but hold no rows for the set', function () {
        $tabler = $this->service->find('tabler');

        expect($tabler['seeded'])->toBeFalse()
            ->and($tabler['seeded_count'])->toBe(0)
            ->and($tabler)->toHaveKeys(['installed', 'installed_version', 'seeded', 'seeded_count']);
    });

    it('reports seeded=true once rows exist for the package', function () {
        Icon::create([
            'package' => 'ichava/tabler-icons',
            'name'    => 'home',
            'path'    => 'outline/home.svg',
        ]);

        $tabler = $this->service->find('tabler');

        expect($tabler['seeded'])->toBeTrue()
            ->and($tabler['seeded_count'])->toBe(1);
    });
});
