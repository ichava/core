<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Services\IconSetBuilder;
use Simtabi\Laranail\Ichava\Services\IconCacheService;

function makeCacheTestSet(string $dir): IconSetBuilder
{
    return IconSetBuilder::make('test-cache-set')
        ->setBasePath($dir)
        ->prefix('tc');
}

function makeCacheTestDir(): string
{
    $dir = sys_get_temp_dir() . '/ichava-cache-test-' . uniqid();
    File::makeDirectory($dir . '/outline', 0755, true);
    File::put($dir . '/outline/check.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>');

    return $dir;
}

function useHardenedFileCache(): void
{
    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.path', sys_get_temp_dir() . '/ichava-cache-test-store-' . uniqid());
    config()->set('cache.serializable_classes', false);
    app()->forgetInstance(IconCacheService::class);
}

describe('IconData array round-trip', function () {
    it('round-trips through toArray and fromArray', function () {
        $original = new IconData(
            name: 'check',
            path: '/icons/outline/check.svg',
            variant: 'outline',
            category: null,
            set: 'test-cache-set',
        );

        $restored = IconData::fromArray($original->toArray());

        expect($restored)->toBeInstanceOf(IconData::class);
        expect($restored->toArray())->toBe($original->toArray());
    });
});

describe('IconSetBuilder hardened cache', function () {
    it('returns the same icon on repeated gets under serializable_classes=false', function () {
        useHardenedFileCache();
        $dir = makeCacheTestDir();

        try {
            $set = makeCacheTestSet($dir);

            $first = $set->get('check', 'outline');
            $second = $set->get('check', 'outline');

            expect($first)->toBeInstanceOf(IconData::class);
            expect($second)->toBeInstanceOf(IconData::class);
            expect($second->toArray())->toBe($first->toArray());
        } finally {
            File::deleteDirectory($dir);
        }
    });

    it('never stores objects in the cache', function () {
        useHardenedFileCache();
        $dir = makeCacheTestDir();

        try {
            $set = makeCacheTestSet($dir);
            $set->get('check', 'outline');

            $foundObject = false;
            foreach (Cache::store('file')->getFilesystem()->files(config('cache.stores.file.path')) as $file) {
                $raw = file_get_contents($file->getPathname());
                if (str_contains($raw, 'IconData')) {
                    $foundObject = true;
                }
            }

            expect($foundObject)->toBeFalse();
        } finally {
            File::deleteDirectory($dir);
        }
    });

    it('recovers from a legacy object payload instead of throwing a TypeError', function () {
        useHardenedFileCache();
        $dir = makeCacheTestDir();

        try {
            $set = makeCacheTestSet($dir);

            $rawKey = (fn () => $this->getCacheKey('check', 'outline', null))->call($set);
            $fullKey = (fn () => $this->buildKey($rawKey))->call(app(IconCacheService::class));

            Cache::store('file')->put($fullKey, new IconData(
                name: 'check',
                path: $dir . '/outline/check.svg',
                variant: 'outline',
                category: null,
                set: 'test-cache-set',
            ), 3600);

            $icon = $set->get('check', 'outline');

            expect($icon)->toBeInstanceOf(IconData::class);
            expect($icon->name)->toBe('check');
        } finally {
            File::deleteDirectory($dir);
        }
    });

    it('returns all icons on repeated calls under serializable_classes=false', function () {
        useHardenedFileCache();
        $dir = makeCacheTestDir();

        try {
            $set = makeCacheTestSet($dir);

            $first = $set->all('outline');
            $second = $set->all('outline');

            expect($first)->toHaveKey('check');
            expect($first['check'])->toBeInstanceOf(IconData::class);
            expect($second['check'])->toBeInstanceOf(IconData::class);
            expect($second['check']->toArray())->toBe($first['check']->toArray());
        } finally {
            File::deleteDirectory($dir);
        }
    });
});
