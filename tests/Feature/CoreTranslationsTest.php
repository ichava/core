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

    // All four call forms, not just __(). Today core uses only __() with this
    // namespace -- measured -- so widening changes nothing now. That is the
    // point: the sweep is this phase's central gate, and it would go silent the
    // first time somebody reached for trans() or Lang::get() instead.
    // trans_choice() is included for the same reason, though core has none yet.
    $call = '(?:__|trans|trans_choice|Lang::get|Lang::choice)';
    $rx = '/' . $call . '\(\s*[\'"]' . preg_quote($ns, '/') . '::([A-Za-z0-9_.\-]+)[\'"]/';

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
    //
    // `table`, `confirm`, `ask` and `choice` are here because they are also
    // user-facing English -- a table's headers and a confirmation's prompt are
    // read by a person exactly like a `line()` is.
    $helpers = 'success|failure|tip|line|info|error|warn|comment|question'
        . '|table|confirm|ask|choice';

    // Non-vacuous guard: the command must actually call some of them.
    preg_match_all('/\$this->(' . $helpers . ')\(/', $body, $all);
    expect($all[0])->not->toBeEmpty('the helper list matches nothing in ' . basename($file));

    // ...and none may be handed a bare string literal.
    // `table()` takes its headers as an array, so the literal sits one bracket
    // in: table(['Name', ...]). Allow an optional opening bracket.
    preg_match_all('/\$this->(' . $helpers . ')\(\s*\[?\s*[\'"]/', $body, $bare);
    expect($bare[0])->toBe([], 'bare string passed to an output helper in ' . basename($file));

    // ---------------------------------------------------------------------
    // Laravel Prompts are free functions, not $this-> helpers, and they are
    // how this command does most of its talking: intro(), outro(), note(),
    // table(), warning(). A guard that only watches `$this->` sees none of
    // them and reports a file clean while its table headers, its intro and
    // its closing line are still English.
    //
    // The guarded list is read from the file's OWN `use function
    // Laravel\Prompts\x` imports rather than hardcoded, so importing a new
    // prompt brings it under the guard automatically instead of opening a
    // hole nobody notices.
    // ---------------------------------------------------------------------
    preg_match_all('/use function Laravel\\\\Prompts\\\\(\w+);/', $body, $imported);

    expect($imported[1])->not->toBeEmpty('no Laravel\Prompts imports found -- has the file changed shape?');

    $prompts = implode('|', $imported[1]);

    // Not preceded by -> or $ or a word character, so `$this->info(` and
    // `formatTable(` do not match; only the bare free-function call does.
    preg_match_all(
        '/(?<![>$\w])(' . $prompts . ')\(\s*(?:[a-z]+:\s*)?\[?\s*[\'"]/',
        $body,
        $barePrompts,
    );

    expect($barePrompts[0])->toBe(
        [],
        'bare string passed to a Laravel Prompts call in ' . basename($file) . ":\n  "
        . implode("\n  ", array_unique($barePrompts[0])),
    );
});

it('does not add hardcoded English to the commands still awaiting migration', function () {
    // A ratchet, in the same spirit as this repository's coverage floor. It
    // does not demand the migration finish; it pins the debt per file so it can
    // only shrink, and turns red the moment a new hardcoded string is added.
    //
    // It counts three surfaces, because each of them reaches the user and the
    // first version of this guard watched only one:
    //
    //   (a) `$this->helper('literal'` -- BaseCommand's and Laravel's output
    //       helpers, the only surface the guard originally watched;
    //   (b) Laravel Prompts free functions -- intro(), outro(), note(), table()
    //       and friends -- called with a literal first argument. The guarded
    //       list is each file's OWN `use function Laravel\Prompts\x` imports, so
    //       importing a new prompt brings it under the guard automatically;
    //   (c) the named arguments a prompt shows to a person: `label:`, `hint:`,
    //       `placeholder:`, `yes:` and `no:`.
    //
    // A literal matched by more than one pattern -- `select(label: '...')` is
    // both (b) and (c) -- is counted once, by the offset of its opening quote.
    //
    // Measured 2026-09-26 with `hardcodedLiteralOffsets()` below. The total
    // before this extension was 38 under (a) alone; (b) and (c) are what the
    // old guard could not see. CleanupIchavaLogsCommand, "the migrated one",
    // still carried a `hint:` literal nobody had noticed.
    //
    // src/Commands reached 0 in every file on 2026-09-26; a literal added
    // there now fails immediately. The seeder is the remaining debt.
    //
    // **Lower a number when you migrate a file. Never raise one.**
    $ceilings = [
        'src/Commands' => [
            'BaseCommand.php'              => 0,
            'CacheCommand.php'             => 0,
            'CheckIconUpdatesCommand.php'  => 0,
            'CleanupIchavaLogsCommand.php' => 0,
            'DatabaseCommand.php'          => 0,
            'InfoCommand.php'              => 0,
            'InstallCommand.php'           => 0,
            'JobStatusCommand.php'         => 0,
            'WatchIconFilesCommand.php'    => 0,
        ],
        // The seeder is a later phase; pinned separately so its debt is not
        // hidden inside the commands' number, or the commands' inside its.
        'src/Support/Seeder' => [
            'IchavaSeeder.php'      => 0,
            'IconSeederHelpers.php' => 0,
            'IconTermsSeeder.php'   => 0,
        ],
    ];

    $root = dirname(__DIR__, 2);
    $inspected = 0;
    $over = [];

    foreach ($ceilings as $dir => $files) {
        $found = glob("{$root}/{$dir}/*.php");

        // Non-vacuous: a glob that matches nothing would report a clean tree.
        expect($found)->not->toBeEmpty("the literal sweep found no files in {$dir}; it is broken, not done");

        foreach ($found as $file) {
            $name = basename($file);
            $count = count(hardcodedLiteralOffsets((string) file_get_contents($file)));
            $inspected++;

            // A new file starts at zero: it must be written translated.
            $ceiling = $files[$name] ?? 0;

            if ($count > $ceiling) {
                $over[] = "{$dir}/{$name}: {$count} (ceiling {$ceiling})";
            }
        }
    }

    // The ceilings above name 12 files; if the sweep saw fewer, it is not
    // looking where it thinks it is.
    expect($inspected)->toBeGreaterThanOrEqual(12, "the literal sweep inspected only {$inspected} files");

    expect($over)->toBe([], "hardcoded user-facing strings grew:\n  " . implode("\n  ", $over));
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

/**
 * Offsets of every hardcoded user-facing string literal in a PHP source body,
 * keyed by the offset of the literal's opening quote so a literal matched by
 * two patterns counts once.
 *
 * @return array<int, string> offset => which pattern found it
 */
function hardcodedLiteralOffsets(string $body): array
{
    $offsets = [];

    // (a) $this->helper('literal' -- `table()` takes its headers as an array,
    // so the literal may sit one bracket in.
    $helpers = 'success|failure|tip|line|info|error|warn|comment|question'
        . '|table|confirm|ask|choice';
    preg_match_all('/\$this->(?:' . $helpers . ')\(\s*\[?\s*([\'"])/', $body, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as [, $offset]) {
        $offsets[$offset] = 'helper';
    }

    // (b) Laravel Prompts free functions, read from the file's own imports.
    // Not preceded by -> or $ or a word character or a backslash, so
    // `$this->info(` and `formatTable(` do not match.
    preg_match_all('/use function Laravel\\\\Prompts\\\\(\w+);/', $body, $imported);
    if ($imported[1] !== []) {
        preg_match_all(
            '/(?<![>$\w\\\\])(?:' . implode('|', $imported[1]) . ')\(\s*(?:[a-z]+:\s*)?\[?\s*([\'"])/',
            $body,
            $m,
            PREG_OFFSET_CAPTURE,
        );
        foreach ($m[1] as [, $offset]) {
            $offsets[$offset] = 'prompt';
        }
    }

    // (c) the named arguments a prompt shows to a person.
    preg_match_all('/\b(?:label|hint|placeholder|yes|no):\s*([\'"])/', $body, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as [, $offset]) {
        $offsets[$offset] = 'named';
    }

    return $offsets;
}
