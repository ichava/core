<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\PathResolver;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

beforeEach(function () {
    $this->resolver = new PathResolver;
});

// --- parseIconPath ---------------------------------------------------------

describe('PathResolver::parseIconPath', function () {
    it('parses a slash-form path into components', function () {
        $r = $this->resolver->parseIconPath('myorg/icons::outline/arrows/arrow-left');
        expect($r->vendor)->toBe('myorg');
        expect($r->package)->toBe('icons');
        expect($r->set)->toBe('myorg/icons');
        expect($r->name)->toBe('arrow-left');
        expect($r->variant)->toBe('outline');
        expect($r->category)->toBe('arrows');
        expect($r->fullPath)->toBe('outline/arrows/arrow-left');
    });

    it('normalizes dot-form to slash form', function () {
        $r = $this->resolver->parseIconPath('myorg/icons::outline.arrow-left');
        expect($r->name)->toBe('arrow-left');
        expect($r->variant)->toBe('outline');
        expect($r->fullPath)->toBe('outline/arrow-left');
    });

    it('treats the last segment as the icon name regardless of depth', function () {
        $r = $this->resolver->parseIconPath('a/b::w/x/y/z/icon');
        expect($r->name)->toBe('icon');
        expect($r->variant)->toBe('w');
        expect($r->category)->toBe('x');
    });

    it('rejects paths missing the package separator', function () {
        $this->resolver->parseIconPath('just-an-icon');
    })->throws(IchavaException::class);

    it('rejects path-traversal attempts', function () {
        $this->resolver->parseIconPath('myorg/icons::../etc/passwd');
    })->throws(IchavaException::class);

    it('rejects invalid icon names', function () {
        $this->resolver->parseIconPath('myorg/icons::variant/BAD NAME');
    })->throws(IchavaException::class);

    it('rejects invalid vendor identifiers', function () {
        $this->resolver->parseIconPath('bad name/icons::v/x');
    })->throws(IchavaException::class);
});

// --- buildIconPath ---------------------------------------------------------

describe('PathResolver::buildIconPath', function () {
    it('reconstructs a full path from vendor/package + variant/category', function () {
        $built = $this->resolver->buildIconPath(
            name: 'arrow-left',
            variant: 'outline',
            category: 'arrows',
            vendor: 'myorg',
            package: 'icons',
        );
        expect($built)->toBe('myorg/icons::outline/arrows/arrow-left');
    });

    it('uses set when vendor/package are missing', function () {
        $built = $this->resolver->buildIconPath(
            name: 'star',
            set: 'myorg/icons',
        );
        expect($built)->toBe('myorg/icons::star');
    });

    it('omits variant/category when not provided', function () {
        $built = $this->resolver->buildIconPath(name: 'star', set: 'myorg/icons');
        expect($built)->toBe('myorg/icons::star');
    });

    it('round-trips through parseIconPath', function () {
        $original = 'myorg/icons::outline/arrow-left';
        $parsed = $this->resolver->parseIconPath($original);
        $rebuilt = $this->resolver->buildIconPath(
            name: $parsed->name,
            variant: $parsed->variant,
            vendor: $parsed->vendor,
            package: $parsed->package,
        );
        expect($rebuilt)->toBe($original);
    });
});

// --- normalize / isAbsolute / join -----------------------------------------

describe('PathResolver::normalize', function () {
    it('passes through unix-absolute paths', function () {
        expect($this->resolver->normalize('/var/icons'))->toBe('/var/icons');
    });

    it('passes through windows-absolute paths', function () {
        expect($this->resolver->normalize('C:\\icons'))->toBe('C:\\icons');
        expect($this->resolver->normalize('D:/icons'))->toBe('D:/icons');
    });

    it('makes relative paths absolute against base_path()', function () {
        $result = $this->resolver->normalize('icons/sub');
        expect($result)->toContain('icons');
        expect($result)->toContain('sub');
        // Result must be absolute now.
        expect($this->resolver->isAbsolute($result))->toBeTrue();
    });
});

describe('PathResolver::isAbsolute', function () {
    it('detects unix absolute paths', function () {
        expect($this->resolver->isAbsolute('/foo'))->toBeTrue();
    });

    it('detects windows absolute paths', function () {
        expect((bool) $this->resolver->isAbsolute('C:\\foo'))->toBeTrue();
        expect((bool) $this->resolver->isAbsolute('D:/foo'))->toBeTrue();
    });

    it('rejects relative paths', function () {
        expect((bool) $this->resolver->isAbsolute('foo/bar'))->toBeFalse();
        expect((bool) $this->resolver->isAbsolute('./foo'))->toBeFalse();
    });
});

describe('PathResolver::join', function () {
    it('joins relative segments with the directory separator', function () {
        $result = $this->resolver->join('a', 'b', 'c');
        expect($result)->toBe('a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'c');
    });

    it('preserves a leading slash on the first segment', function () {
        $result = $this->resolver->join('/var', 'icons');
        expect($result)->toStartWith('/var');
    });

    it('strips trailing slashes from segments', function () {
        $result = $this->resolver->join('a/', '/b/', '/c');
        expect($result)->toBe('a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'c');
    });

    it('returns empty string for no segments', function () {
        expect($this->resolver->join())->toBe('');
    });

    it('skips empty segments', function () {
        $result = $this->resolver->join('a', '', 'b');
        expect($result)->toBe('a' . DIRECTORY_SEPARATOR . 'b');
    });
});

// --- resolveRelativeTo / getRelativePath / ensureDirectoryExists ----------

describe('PathResolver::resolveRelativeTo', function () {
    it('returns absolute paths unchanged', function () {
        expect($this->resolver->resolveRelativeTo('/base', '/elsewhere'))->toBe('/elsewhere');
    });

    it('joins relative paths to the base', function () {
        $result = $this->resolver->resolveRelativeTo('/base', 'sub/deeper');
        expect($result)->toBe('/base' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'deeper');
    });
});

describe('PathResolver::getRelativePath', function () {
    it('walks down from a common base', function () {
        $rel = $this->resolver->getRelativePath('/base', '/base/sub/deeper');
        // realpath() can't resolve the synthetic /base; the implementation
        // falls back to the literal string and walks the parts. Confirm the
        // descent components made it through.
        expect($rel)->toContain('sub');
        expect($rel)->toContain('deeper');
    });

    it('walks up when the source is below the destination', function () {
        $rel = $this->resolver->getRelativePath('/base/sub/deep', '/base');
        expect($rel)->toContain('..');
    });
});
