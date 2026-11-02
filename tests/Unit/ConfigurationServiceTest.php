<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\ConfigurationService;

beforeEach(function () {
    $this->service = app(ConfigurationService::class);
    $this->dir = sys_get_temp_dir() . '/ichava-config-' . bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    if (is_dir($this->dir)) {
        @unlink($this->dir . '/config.json');
        @rmdir($this->dir);
    }
});

function _writeConfig(string $dir, array $data): void
{
    file_put_contents($dir . '/config.json', json_encode($data));
}

describe('ConfigurationService::loadPackageConfig', function () {
    it('loads a valid config.json', function () {
        _writeConfig($this->dir, [
            'package'  => ['name' => 'myorg/icons'],
            'config'   => ['icon_prefix' => 'ic'],
            'variants' => ['outline', 'filled'],
        ]);
        $cfg = $this->service->loadPackageConfig($this->dir);
        expect($cfg['package']['name'])->toBe('myorg/icons');
        expect($cfg['variants'])->toBe(['outline', 'filled']);
    });

    it('throws on missing config.json', function () {
        $this->service->loadPackageConfig($this->dir);
    })->throws(IchavaException::class);

    it('throws on invalid JSON', function () {
        file_put_contents($this->dir . '/config.json', '{not json');
        $this->service->loadPackageConfig($this->dir);
    })->throws(IchavaException::class);

    it('throws on missing package.name', function () {
        _writeConfig($this->dir, ['config' => ['icon_prefix' => 'ic']]);
        $this->service->loadPackageConfig($this->dir);
    })->throws(IchavaException::class);

    it('throws on malformed package.name', function () {
        _writeConfig($this->dir, [
            'package' => ['name' => 'no-slash-here'],
            'config'  => ['icon_prefix' => 'ic'],
        ]);
        $this->service->loadPackageConfig($this->dir);
    })->throws(IchavaException::class);

    it('throws on missing config.icon_prefix', function () {
        _writeConfig($this->dir, ['package' => ['name' => 'myorg/icons']]);
        $this->service->loadPackageConfig($this->dir);
    })->throws(IchavaException::class);
});

describe('ConfigurationService accessor helpers', function () {
    it('detects variants when populated', function () {
        expect($this->service->hasVariants(['variants' => ['a', 'b']]))->toBeTrue();
        expect($this->service->hasVariants(['variants' => []]))->toBeFalse();
        expect($this->service->hasVariants([]))->toBeFalse();
    });

    it('returns variants array or empty', function () {
        expect($this->service->getVariants(['variants' => ['outline']]))->toBe(['outline']);
        expect($this->service->getVariants([]))->toBe([]);
    });

    it('detects categories when populated', function () {
        expect($this->service->hasCategories(['categories' => ['ui']]))->toBeTrue();
        expect($this->service->hasCategories(['categories' => []]))->toBeFalse();
        expect($this->service->hasCategories([]))->toBeFalse();
    });

    it('extracts vendor from package name', function () {
        expect($this->service->getVendor(['package' => ['name' => 'myorg/icons']]))->toBe('myorg');
        expect($this->service->getVendor([]))->toBe('');
    });
});
