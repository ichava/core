<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;

/*
|--------------------------------------------------------------------------
| Core ships its own translations, and every key it references resolves
|--------------------------------------------------------------------------
|
| Core extends PackageServiceProvider directly, so it does NOT inherit the
| default-on registration that Support\ServiceProvider gives the packs. It has
| to call hasTranslations() itself -- an asymmetry that has already cost one
| defect class in this ecosystem.
|
| The second test is the one that matters. A missing translation key does not
| throw in Laravel; it renders as the key. That is the estate's signature
| failure -- shipped, never executed, silently wrong -- and a sweep is the only
| thing that catches it.
|
*/

it('registers the core translation namespace in the live loader', function () {
    expect(Lang::getLoader()->namespaces())->toHaveKey(coreTransNamespace());
});

it('resolves every translation key core references', function () {
    $ns = coreTransNamespace();
    $src = dirname(__DIR__, 2) . '/src';

    $keys = [];
    $rx = '/__\(\s*[\'"]' . preg_quote($ns, '/') . '::([A-Za-z0-9_.\-]+)[\'"]/';

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        preg_match_all($rx, (string) file_get_contents($file->getPathname()), $m);
        foreach ($m[1] as $key) {
            $keys[$key] = $file->getPathname();
        }
    }

    expect($keys)->not->toBeEmpty('no core translation keys found -- the sweep matched nothing, which passes vacuously');

    $unresolved = [];
    foreach ($keys as $key => $where) {
        $full = "{$ns}::{$key}";
        if (__($full) === $full) {
            $unresolved[] = $key . '  (' . basename($where) . ')';
        }
    }

    expect($unresolved)->toBe([], "unresolved translation keys:\n  " . implode("\n  ", $unresolved));
});

it('has no hardcoded user-facing literal left in the migrated command', function () {
    $file = dirname(__DIR__, 2) . '/src/Commands/CleanupIchavaLogsCommand.php';
    $body = (string) file_get_contents($file);

    // Every way this command reaches the user. `success`, `failure` and `tip`
    // are BaseCommand's own helpers -- an earlier version of this test listed
    // only Laravel's `line`/`info`/`error` and so matched nothing at all,
    // passing before the migration had happened.
    $helpers = 'success|failure|tip|line|info|error|warn|comment|question';

    // Non-vacuous guard: the command must actually call some of them.
    preg_match_all('/\$this->(' . $helpers . ')\(/', $body, $all);
    expect($all[0])->not->toBeEmpty('the helper list matches nothing in ' . basename($file));

    // ...and none may be handed a bare string literal.
    preg_match_all('/\$this->(' . $helpers . ')\(\s*[\'"]/', $body, $bare);
    expect($bare[0])->toBe([], 'bare string passed to an output helper in ' . basename($file));
});

it('ships no views, and registers no view namespace', function () {
    // Measured 2026-09-21: core has zero *.blade.php. Nothing renders from a
    // core view, so creating `resources/views` would register a namespace that
    // resolves nothing -- the same defect the packs' empty `views/components`
    // directory would cause. Recorded as an assertion rather than a comment so
    // it is not re-opened by someone adding the directory "for symmetry".
    expect(is_dir(dirname(__DIR__, 2) . '/resources/views'))->toBeFalse()
        ->and(array_keys(Illuminate\Support\Facades\View::getFinder()->getHints()))
        ->not->toContain(coreTransNamespace());
});

function coreTransNamespace(): string
{
    $provider = app()->getProvider(Simtabi\Laranail\Ichava\Providers\IchavaServiceProvider::class);
    $package = (new ReflectionProperty($provider, 'package'))->getValue($provider);

    return $package->translationNamespace();
}
