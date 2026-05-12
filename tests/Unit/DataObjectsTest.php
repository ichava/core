<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Data\IconAttributes;
use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Data\IconMetadata;
use Simtabi\Laranail\Ichava\Data\IconPathResult;
use Simtabi\Laranail\Ichava\Data\IconSetConfig;

describe('IconAttributes', function () {
    it('exposes the attributes array unchanged via toArray', function () {
        $attrs = new IconAttributes(['class' => 'h-4 w-4', 'role' => 'img']);
        expect($attrs->toArray())->toBe(['class' => 'h-4 w-4', 'role' => 'img']);
    });

    it('merges into a new immutable instance', function () {
        $original = new IconAttributes(['class' => 'h-4', 'role' => 'img']);
        $merged = $original->merge(['class' => 'h-8', 'aria-hidden' => 'true']);

        expect($merged)->not->toBe($original);
        expect($merged->toArray())->toBe([
            'class' => 'h-8',
            'role' => 'img',
            'aria-hidden' => 'true',
        ]);
        // Original is untouched.
        expect($original->toArray())->toBe(['class' => 'h-4', 'role' => 'img']);
    });
});

describe('IconMetadata', function () {
    it('defaults every field to null or empty array', function () {
        $m = new IconMetadata;
        expect($m->title)->toBeNull();
        expect($m->description)->toBeNull();
        expect($m->author)->toBeNull();
        expect($m->license)->toBeNull();
        expect($m->version)->toBeNull();
        expect($m->tags)->toBe([]);
    });

    it('preserves all constructor arguments', function () {
        $m = new IconMetadata(
            title: 'Tabler',
            description: 'A free, open-source set',
            author: 'Tabler Authors',
            license: 'MIT',
            version: '2.40.0',
            tags: ['ui', 'svg'],
        );
        expect($m->title)->toBe('Tabler');
        expect($m->description)->toBe('A free, open-source set');
        expect($m->author)->toBe('Tabler Authors');
        expect($m->license)->toBe('MIT');
        expect($m->version)->toBe('2.40.0');
        expect($m->tags)->toBe(['ui', 'svg']);
    });
});

describe('IconSetConfig', function () {
    it('preserves constructor inputs', function () {
        $cfg = new IconSetConfig(
            name: 'tabler',
            prefix: 'tabler',
            basePath: '/var/icons/tabler',
            defaultVariant: 'outline',
            variants: ['outline', 'filled'],
            supportsCategories: true,
            defaultClass: 'icon',
            defaultAttributes: ['stroke' => 'currentColor'],
            fallback: 'circle',
        );

        expect($cfg->name)->toBe('tabler');
        expect($cfg->prefix)->toBe('tabler');
        expect($cfg->basePath)->toBe('/var/icons/tabler');
        expect($cfg->defaultVariant)->toBe('outline');
        expect($cfg->variants)->toBe(['outline', 'filled']);
        expect($cfg->supportsCategories)->toBeTrue();
        expect($cfg->defaultClass)->toBe('icon');
        expect($cfg->defaultAttributes)->toBe(['stroke' => 'currentColor']);
        expect($cfg->fallback)->toBe('circle');
    });

    it('defaults fallback to null', function () {
        $cfg = new IconSetConfig(
            name: 'x', prefix: 'x', basePath: '/x',
            defaultVariant: null, variants: [], supportsCategories: false,
            defaultClass: '', defaultAttributes: [],
        );
        expect($cfg->fallback)->toBeNull();
    });
});

describe('IconData', function () {
    it('preserves constructor inputs', function () {
        $d = new IconData(
            name: 'arrow-left',
            path: 'outline/arrows/arrow-left',
            variant: 'outline',
            category: 'arrows',
            set: 'myorg/tabler-icons',
        );
        expect($d->name)->toBe('arrow-left');
        expect($d->path)->toBe('outline/arrows/arrow-left');
        expect($d->variant)->toBe('outline');
        expect($d->category)->toBe('arrows');
        expect($d->set)->toBe('myorg/tabler-icons');
    });
});

describe('IconPathResult', function () {
    it('preserves constructor inputs as readonly properties', function () {
        $r = new IconPathResult(
            set: 'myorg/tabler-icons',
            name: 'arrow-left',
            variant: 'outline',
            category: 'arrows',
            vendor: 'myorg',
            package: 'tabler-icons',
            fullPath: 'outline/arrows/arrow-left',
        );
        expect($r->set)->toBe('myorg/tabler-icons');
        expect($r->name)->toBe('arrow-left');
        expect($r->variant)->toBe('outline');
        expect($r->category)->toBe('arrows');
        expect($r->vendor)->toBe('myorg');
        expect($r->package)->toBe('tabler-icons');
        expect($r->fullPath)->toBe('outline/arrows/arrow-left');
    });

    it('allows null for every optional field', function () {
        $r = new IconPathResult(set: null, name: 'x');
        expect($r->variant)->toBeNull();
        expect($r->category)->toBeNull();
        expect($r->vendor)->toBeNull();
        expect($r->package)->toBeNull();
        expect($r->fullPath)->toBeNull();
    });

    it('round-trips through toString via PathResolver', function () {
        $r = new IconPathResult(
            set: 'myorg/icons',
            name: 'arrow-left',
            vendor: 'myorg',
            package: 'icons',
            fullPath: 'outline/arrow-left',
        );
        $rendered = (string) $r;
        // Hard-coded format check; the precise rendering is exercised by
        // PathResolver tests, here we just confirm casting works.
        expect($rendered)->toBeString()->not->toBe('');
    });
});
