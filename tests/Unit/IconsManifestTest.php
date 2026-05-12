<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Ichava\Services\IconsManifest;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ichava-manifest-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->path = $this->dir.'/manifest.php';
    $this->manifest = new IconsManifest(new Filesystem, $this->path);
});

afterEach(function () {
    @unlink($this->path);
    @rmdir($this->dir);
});

describe('IconsManifest::getPath', function () {
    it('returns the path supplied to the constructor', function () {
        expect($this->manifest->getPath())->toBe($this->path);
    });
});

describe('IconsManifest::exists / load / delete', function () {
    it('reports exists=false and load=null when no file is present', function () {
        expect($this->manifest->exists())->toBeFalse();
        expect($this->manifest->load())->toBeNull();
    });

    it('returns the file contents when present', function () {
        $payload = [
            '_stats' => ['total_icons' => 0, 'total_sets' => 0, 'built_at' => '2026-05-08T00:00:00+00:00', 'sets' => []],
            'set-a' => ['name' => 'set-a', 'icons' => []],
        ];
        file_put_contents(
            $this->path,
            "<?php\n\nreturn ".var_export($payload, true).";\n",
            LOCK_EX
        );
        expect($this->manifest->exists())->toBeTrue();
        $loaded = $this->manifest->load();
        expect($loaded['set-a']['name'])->toBe('set-a');
        expect($loaded['_stats']['total_icons'])->toBe(0);
    });

    it('caches the result of load() across repeated calls', function () {
        file_put_contents($this->path, "<?php\n\nreturn ['_stats' => []];\n", LOCK_EX);
        $first = $this->manifest->load();
        @unlink($this->path);
        // Even though the file is gone, load() should return the cached array.
        $second = $this->manifest->load();
        expect($second)->toBe($first);
    });

    it('returns true when delete is called on a missing file', function () {
        expect($this->manifest->delete())->toBeTrue();
    });

    it('removes the file when delete() is called and a file exists', function () {
        file_put_contents($this->path, "<?php\nreturn [];\n", LOCK_EX);
        expect($this->manifest->exists())->toBeTrue();
        $deleted = $this->manifest->delete();
        expect($deleted)->toBeTrue();
        expect(file_exists($this->path))->toBeFalse();
    });
});

describe('IconsManifest::getStats / getSet / hasSet / getIcon / hasIcon', function () {
    beforeEach(function () {
        $payload = [
            '_stats' => [
                'total_icons' => 2,
                'total_sets' => 1,
                'built_at' => '2026-05-08T00:00:00+00:00',
                'sets' => ['set-a' => ['count' => 2]],
            ],
            'set-a' => [
                'name' => 'set-a',
                'icons' => [
                    'star' => ['name' => 'star', 'variant' => null, 'category' => null],
                    'arrow:outline' => ['name' => 'arrow', 'variant' => 'outline', 'category' => null],
                ],
            ],
        ];
        file_put_contents(
            $this->path,
            "<?php\nreturn ".var_export($payload, true).";\n",
            LOCK_EX
        );
    });

    it('reports stats from the loaded manifest', function () {
        expect($this->manifest->getStats()['total_icons'])->toBe(2);
        expect($this->manifest->getStats()['total_sets'])->toBe(1);
    });

    it('returns the set payload by name', function () {
        $set = $this->manifest->getSet('set-a');
        expect($set['name'])->toBe('set-a');
        expect($set['icons'])->toHaveCount(2);
    });

    it('returns null for an unknown set', function () {
        expect($this->manifest->getSet('does-not-exist'))->toBeNull();
        expect($this->manifest->hasSet('does-not-exist'))->toBeFalse();
        expect($this->manifest->hasSet('set-a'))->toBeTrue();
    });

    it('looks up an icon by (set, name)', function () {
        $icon = $this->manifest->getIcon('set-a', 'star');
        expect($icon['name'])->toBe('star');
        expect($this->manifest->hasIcon('set-a', 'star'))->toBeTrue();
        expect($this->manifest->hasIcon('set-a', 'missing'))->toBeFalse();
    });
});

describe('IconsManifest::getSize / getAge / isStale', function () {
    it('returns 0 size and 0 age when no manifest is on disk', function () {
        expect($this->manifest->getSize())->toBe(0);
        expect($this->manifest->getAge())->toBe(0);
    });

    it('reports a non-zero size for an existing file', function () {
        file_put_contents($this->path, "<?php\nreturn [];\n", LOCK_EX);
        expect($this->manifest->getSize())->toBeGreaterThan(0);
    });

    it('considers a freshly written manifest as not stale', function () {
        file_put_contents($this->path, "<?php\nreturn [];\n", LOCK_EX);
        expect($this->manifest->isStale(maxAge: 3600))->toBeFalse();
    });

    it('considers a missing manifest as stale', function () {
        // No file written.
        expect($this->manifest->isStale())->toBeTrue();
    });
});
