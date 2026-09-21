<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Ichava\Actions\CountSvgFiles;
use Simtabi\Laranail\Ichava\Actions\BuildIconUsageSyntax;
use Simtabi\Laranail\Ichava\Actions\DiscoverInstalledPackages;

/*
|--------------------------------------------------------------------------
| The three actions extracted from IconDiscoveryService
|--------------------------------------------------------------------------
|
| These are Unit tests, and that is the point of the refactor. Every behaviour
| below used to live in a 1,000-line service between a composer.lock parser, a
| database query and a cache wrapper, and could only be reached by standing all
| of that up. Each one is now a constructor call and an assertion.
|
| The cost of the old arrangement is on the record: `getIconSyntax()` read a key
| that was never written and emitted `Undefined array key` on every single call
| for the whole life of the method (F-4.2), and both SVG counters wrapped their
| iterator in a `catch` for an exception it cannot throw.
|
*/

function tempTree(): string
{
    $dir = sys_get_temp_dir() . '/ichava-actions-' . bin2hex(random_bytes(4));
    mkdir($dir . '/nested/deeper', 0755, true);

    return $dir;
}

// ---------------------------------------------------------------- syntax ---

it('builds all three usage forms for a pack with a component', function () {
    $syntax = (new BuildIconUsageSyntax)('ichava/tabler-icons', [
        'prefix'          => 'ti',
        'blade_component' => 'tabler-icons-icon',
    ], 'home');

    expect($syntax)->toBe([
        'helper'    => "ichava('ti:home')",
        'directive' => "@ichava('ti:home')",
        'component' => '<x-tabler-icons-icon name="home" />',
    ]);
});

it('omits the component form without warning when the pack registered none', function () {
    // This is F-4.2 pinned at the unit it belongs to. The old code read
    // `$packageData['blade_component']` with no null-coalesce, so an absent key
    // emitted a warning on every call and produced a null hint anyway.
    $warnings = [];
    set_error_handler(function (int $no, string $msg) use (&$warnings): bool {
        $warnings[] = $msg;

        return true;
    }, E_WARNING);

    try {
        $syntax = (new BuildIconUsageSyntax)('vendor/pack', ['prefix' => 'vp'], 'home');
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toBe([])
        ->and($syntax['component'])->toBeNull()
        ->and($syntax['helper'])->toBe("ichava('vp:home')");
});

it('falls back to the package name when a pack declares no prefix', function () {
    $syntax = (new BuildIconUsageSyntax)('vendor/pack', [], 'home');

    expect($syntax['helper'])->toBe("ichava('vendor/pack:home')");
});

it('appends the variant when one is given', function () {
    $syntax = (new BuildIconUsageSyntax)('p', ['prefix' => 'ti'], 'home', 'outline');

    expect($syntax['helper'])->toBe("ichava('ti:home:outline')");
})->with([['outline'], ['filled']]);

// ------------------------------------------------------------- composer ---

it('reads only ichava packages out of a composer.lock', function () {
    $dir = tempTree();
    $lock = $dir . '/composer.lock';

    file_put_contents($lock, json_encode(['packages' => [
        ['name' => 'ichava/core', 'version' => '0.3.2', 'description' => 'Engine'],
        ['name' => 'laravel/framework', 'version' => '13.0.0'],
        ['name' => 'ichava/icon-sets-flag', 'version' => '0.3.0'],
    ]]));

    $found = (new DiscoverInstalledPackages(new Filesystem))($lock);

    expect(array_column($found, 'name'))->toBe(['ichava/core', 'ichava/icon-sets-flag'])
        ->and($found[0]['version'])->toBe('0.3.2')
        ->and($found[0]['type'])->toBe('library');   // defaulted

    (new Filesystem)->deleteDirectory($dir);
});

it('returns nothing rather than throwing for a missing or malformed lock', function (string $contents) {
    $dir = tempTree();
    $lock = $dir . '/composer.lock';

    if ($contents !== '<<missing>>') {
        file_put_contents($lock, $contents);
    }

    expect((new DiscoverInstalledPackages(new Filesystem))($lock))->toBe([]);

    (new Filesystem)->deleteDirectory($dir);
})->with([
    'missing file'        => ['<<missing>>'],
    'not json'            => ['this is not json'],
    'no packages key'     => ['{"content-hash":"abc"}'],
    'packages not a list' => ['{"packages":"nope"}'],
]);

// --------------------------------------------------------------- counting ---

it('counts svg files at any depth', function () {
    $dir = tempTree();
    touch($dir . '/a.svg');
    touch($dir . '/nested/b.svg');
    touch($dir . '/nested/deeper/c.svg');
    touch($dir . '/nested/not-an-icon.txt');

    expect((new CountSvgFiles(new Filesystem))->recursively($dir))->toBe(3);

    (new Filesystem)->deleteDirectory($dir);
});

it('counts only the top level when asked directly, and caps it', function () {
    $dir = tempTree();
    touch($dir . '/a.svg');
    touch($dir . '/nested/b.svg');   // deeper: must not be counted

    $counter = new CountSvgFiles(new Filesystem);

    expect($counter->directly($dir))->toBe(1);

    for ($i = 0; $i < CountSvgFiles::SHALLOW_CAP + 10; $i++) {
        touch($dir . "/icon-{$i}.svg");
    }

    // A pack with 121,314 icons must not be fully walked to render one row.
    expect($counter->directly($dir))->toBe(CountSvgFiles::SHALLOW_CAP);

    (new Filesystem)->deleteDirectory($dir);
});

it('answers zero for a path that is not a directory', function () {
    $counter = new CountSvgFiles(new Filesystem);

    expect($counter->recursively('/no/such/path'))->toBe(0)
        ->and($counter->directly('/no/such/path'))->toBe(0);
});

it('answers zero rather than propagating when a directory cannot be read', function () {
    // The old code caught IchavaException here, which the iterator never
    // throws -- it throws UnexpectedValueException, a sibling of it, not a
    // subclass. The handler could not fire and the failure escaped a method
    // whose contract is to return 0.
    $dir = tempTree();
    touch($dir . '/a.svg');
    chmod($dir . '/nested', 0000);

    try {
        expect((new CountSvgFiles(new Filesystem))->recursively($dir))->toBe(0);
    } finally {
        chmod($dir . '/nested', 0755);
        (new Filesystem)->deleteDirectory($dir);
    }
})->skip(fn () => posix_geteuid() === 0, 'root reads unreadable directories anyway');
