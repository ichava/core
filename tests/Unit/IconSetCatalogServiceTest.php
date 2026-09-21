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

        $tabler = collect($sets)->firstWhere('key', 'tabler');
        $flags = collect($sets)->firstWhere('key', 'flags');

        expect($tabler)->not->toBeNull()
            ->and($flags)->not->toBeNull()
            ->and($tabler)->toMatchArray([
                'package'    => 'ichava/icon-sets-tabler',
                'repository' => 'ichava/icon-sets-tabler',
            ])->and($flags)->toMatchArray([
                'package'    => 'ichava/icon-sets-flag',
                'repository' => 'ichava/icon-sets-flag',
            ]);

        foreach ([$tabler, $flags] as $set) {
            expect($set['title'])->toBeString()->not->toBe('')
                ->and($set['icon_count'])->toBeInt()->toBeGreaterThan(0)
                ->and($set['variants'])->toBeArray()->not->toBeEmpty()
                ->and($set['latest_version'])->toBeString()->toMatch('/^\d+\.\d+\.\d+/');
        }
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
        expect($this->service->find('tabler')['package'])->toBe('ichava/icon-sets-tabler')
            ->and($this->service->find('ichava/icon-sets-flag')['key'])->toBe('flags')
            ->and($this->service->find('no-such-set'))->toBeNull();
    });
});

describe('IconSetCatalogService::latestTag', function () {
    it('returns the synced release version', function () {
        $sets = collect($this->service->load());

        $tablerVersion = ltrim((string) $sets->firstWhere('key', 'tabler')['latest_version'], 'vV');
        $flagsVersion = ltrim((string) $sets->firstWhere('key', 'flags')['latest_version'], 'vV');

        expect($tablerVersion)->not->toBe('')
            ->and($flagsVersion)->not->toBe('')
            ->and($this->service->latestTag('ichava/icon-sets-tabler'))->toBe($tablerVersion)
            ->and($this->service->latestTag('ichava/icon-sets-flag'))->toBe($flagsVersion)
            ->and($this->service->requireTarget('ichava/icon-sets-tabler'))->toBe("ichava/icon-sets-tabler:^{$tablerVersion}");
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
            'package' => 'ichava/icon-sets-tabler',
            'name'    => 'home',
            'path'    => 'outline/home.svg',
        ]);

        $tabler = $this->service->find('tabler');

        expect($tabler['seeded'])->toBeTrue()
            ->and($tabler['seeded_count'])->toBe(1);
    });
});
