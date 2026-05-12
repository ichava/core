<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\IconPathStructureDetector;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/ichava-pathstruct-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0755, true);
});

afterEach(function () {
    $delete = function ($path) use (&$delete) {
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $delete("$path/$entry");
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $delete($this->root);
});

describe('IconPathStructureDetector::detect', function () {
    it('returns SINGLE_SET when the base directory does not exist', function () {
        $result = IconPathStructureDetector::detect($this->root.'/missing');
        expect($result)->toBe(IconPathStructureDetector::SINGLE_SET);
    });

    it('returns SINGLE_SET when only files/ is present at the base', function () {
        mkdir("{$this->root}/files");
        $result = IconPathStructureDetector::detect($this->root);
        expect($result)->toBe(IconPathStructureDetector::SINGLE_SET);
    });

    it('returns MULTI_SET when multiple sub-directories carry files/', function () {
        mkdir("{$this->root}/set-a/files", 0755, true);
        mkdir("{$this->root}/set-b/files", 0755, true);
        $result = IconPathStructureDetector::detect($this->root);
        expect($result)->toBe(IconPathStructureDetector::MULTI_SET);
    });

    it('returns MULTI_SET when even one sub-directory has files/', function () {
        // Strategy 3 triggers as soon as any sub-directory has files/.
        mkdir("{$this->root}/only-set/files", 0755, true);
        $result = IconPathStructureDetector::detect($this->root);
        expect($result)->toBe(IconPathStructureDetector::MULTI_SET);
    });
});

describe('IconPathStructureDetector::findSetDirectories', function () {
    it('returns an empty list for a missing base', function () {
        expect(IconPathStructureDetector::findSetDirectories("{$this->root}/missing"))->toBe([]);
    });

    it('returns directories that contain files/', function () {
        mkdir("{$this->root}/set-a/files", 0755, true);
        mkdir("{$this->root}/set-b/files", 0755, true);
        mkdir("{$this->root}/no-files-here", 0755, true);
        $sets = IconPathStructureDetector::findSetDirectories($this->root);
        sort($sets);
        expect($sets)->toBe(['set-a', 'set-b']);
    });

    it('skips well-known non-set directories', function () {
        foreach (['vendor', 'node_modules', '.git', 'config'] as $skip) {
            mkdir("{$this->root}/{$skip}/files", 0755, true);
        }
        mkdir("{$this->root}/real-set/files", 0755, true);
        $sets = IconPathStructureDetector::findSetDirectories($this->root);
        expect($sets)->toBe(['real-set']);
    });
});

describe('IconPathStructureDetector::getScanPaths', function () {
    it('returns the single-set scan path when files/ lives at the base', function () {
        mkdir("{$this->root}/files");
        $paths = IconPathStructureDetector::getScanPaths($this->root);
        expect($paths)->toContain($this->root.'/files');
    });

    it('returns one scan path per set when in multi-set layout', function () {
        mkdir("{$this->root}/set-a/files", 0755, true);
        mkdir("{$this->root}/set-b/files", 0755, true);
        $paths = IconPathStructureDetector::getScanPaths($this->root);
        sort($paths);
        expect($paths)->toBe([
            $this->root.'/set-a/files',
            $this->root.'/set-b/files',
        ]);
    });
});
