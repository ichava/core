<?php

declare(strict_types=1);

// Moved from tests/Unit/HelpersTest.php with Helpers::sanitizePath(). Not run.

use Simtabi\Laranail\Ichava\Support\Helpers;

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
