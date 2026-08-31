<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

describe('Helpers::sanitizePath', function () {
    it('strips leading and trailing slashes', function () {
        expect(Helpers::sanitizePath('/foo/bar/'))->toBe('foo/bar');
        expect(Helpers::sanitizePath('//x//'))->toBe('x');
        expect(Helpers::sanitizePath('foo/bar'))->toBe('foo/bar');
    });

    it('handles empty input', function () {
        expect(Helpers::sanitizePath(''))->toBe('');
        expect(Helpers::sanitizePath('//'))->toBe('');
    });

    it('strips backslashes too', function () {
        expect(Helpers::sanitizePath('\\foo\\'))->toBe('foo');
    });
});

describe('Helpers vendor / package extractors', function () {
    it('splits vendor/package on slash', function () {
        expect(Helpers::getVendorFromPackage('myorg/icons'))->toBe('myorg');
        expect(Helpers::getPackageFromIdentifier('myorg/icons'))->toBe('icons');
    });

    it('returns whole string when no slash present', function () {
        // Str::before returns the input when separator isn't found.
        expect(Helpers::getVendorFromPackage('orphan'))->toBe('orphan');
    });
});

describe('Helpers::loadConfigJson', function () {
    it('parses a valid config.json', function () {
        $dir = sys_get_temp_dir() . '/ichava-helpers-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/config.json', json_encode(['name' => 'x', 'foo' => 1]));

        $result = Helpers::loadConfigJson($dir);
        expect($result)->toBe(['name' => 'x', 'foo' => 1]);

        @unlink($dir . '/config.json');
        @rmdir($dir);
    });

    it('throws IchavaException on missing file when throwOnMissing is true', function () {
        Helpers::loadConfigJson('/tmp/definitely-does-not-exist-' . bin2hex(random_bytes(4)));
    })->throws(IchavaException::class);

    it('returns empty array on missing file when throwOnMissing is false', function () {
        $result = Helpers::loadConfigJson(
            '/tmp/definitely-does-not-exist-' . bin2hex(random_bytes(4)),
            false,
        );
        expect($result)->toBe([]);
    });

    it('throws on invalid JSON', function () {
        $dir = sys_get_temp_dir() . '/ichava-helpers-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/config.json', '{not json}');

        try {
            Helpers::loadConfigJson($dir);
            $threw = false;
        } catch (IchavaException) {
            $threw = true;
        } finally {
            @unlink($dir . '/config.json');
            @rmdir($dir);
        }
        expect($threw)->toBeTrue();
    });

    it('throws when JSON is valid but not an object', function () {
        $dir = sys_get_temp_dir() . '/ichava-helpers-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/config.json', '"a string, not an object"');

        try {
            Helpers::loadConfigJson($dir);
            $threw = false;
        } catch (IchavaException) {
            $threw = true;
        } finally {
            @unlink($dir . '/config.json');
            @rmdir($dir);
        }
        expect($threw)->toBeTrue();
    });
});

describe('Helpers::logPath', function () {
    it('returns the directory when called without filename', function () {
        $dir = Helpers::logPath();
        expect($dir)->toBeString()->not->toBe('');
    });

    it('appends a filename to the directory', function () {
        $dir = Helpers::logPath();
        $path = Helpers::logPath('my-channel.log');
        expect($path)->toBe($dir . DIRECTORY_SEPARATOR . 'my-channel.log');
    });

    it('strips a leading slash from the filename', function () {
        $dir = Helpers::logPath();
        expect(Helpers::logPath('/foo.log'))->toBe($dir . DIRECTORY_SEPARATOR . 'foo.log');
    });
});

describe('Helpers::assetVersion', function () {
    it('returns the configured version when one is set', function () {
        config(['ichava.core.version' => 'v1.2.3']);

        expect(Helpers::assetVersion('anything.css'))->toBe('v1.2.3');
    });

    it("returns 'dev' for an asset that doesn't exist and no configured version", function () {
        // The configured version short-circuits before the filesystem is
        // consulted, so it has to be cleared to reach the fallback at all.
        // This test passed for the wrong reason until 2026-08-31: the config
        // file was merged at a key nothing read, so the version was always
        // empty and this branch was the only one reachable.
        config(['ichava.core.version' => null]);

        expect(Helpers::assetVersion('definitely-missing-' . bin2hex(random_bytes(4)) . '.css'))
            ->toBe('dev');
    });
});
