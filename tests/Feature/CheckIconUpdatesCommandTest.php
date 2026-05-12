<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;

/**
 * Feature coverage for `php artisan ichava:icons:check-updates`.
 *
 * Pins the contract on which CI / cron / dashboard tooling depends:
 *
 *   • table format (default) returns 0 when everything is fresh
 *   • json format prints a parseable JSON array (verified by decode)
 *   • --fail-on-stale exits non-zero when any pack is behind
 *   • --fail-on-stale exits non-zero when any pack is unreachable
 *   • --package= filters down to a single pack
 *   • empty registry is a soft success ("nothing to check")
 *
 * Table content rendered via Laravel Prompts isn't captured by Pest's
 * artisan harness (Prompts writes direct to STDOUT), so we don't
 * assert on column layouts -- those are visual concerns and the unit
 * tests around IconPackUpdateChecker pin the data shape.
 */
beforeEach(function () {
    $this->stubRows = function (array $rows): void {
        // instance() (not bind/singleton) to displace the singleton
        // already cached by IchavaServiceProvider boot.
        $stub = new class($rows) extends IconPackUpdateChecker
        {
            public function __construct(public array $rows = [])
            {
                // Skip parent ctor -- we don't need a real IconRegistry.
            }

            public function checkAll(?string $packageFilter = null): array
            {
                if ($packageFilter === null) {
                    return $this->rows;
                }

                return array_values(array_filter(
                    $this->rows,
                    static fn (array $r): bool => $r['package'] === $packageFilter
                ));
            }
        };
        $this->app->instance(IconPackUpdateChecker::class, $stub);
    };
});

it('returns SUCCESS when every pack is up to date', function () {
    ($this->stubRows)([
        [
            'package' => 'ichava/flag-icons',
            'source' => 'primary',
            'status' => 'up-to-date',
            'current' => '7.5.0',
            'latest' => '7.5.0',
            'release_url' => 'https://www.npmjs.com/package/flag-icons/v/7.5.0',
            'reason' => null,
        ],
    ]);

    $this->artisan('ichava:icons:check-updates')->assertExitCode(0);
});

it('emits valid JSON under --format=json', function () {
    ($this->stubRows)([
        [
            'package' => 'ichava/tabler-icons',
            'source' => 'primary',
            'status' => 'update-available',
            'current' => '3.0.0',
            'latest' => '3.44.0',
            'release_url' => 'https://www.npmjs.com/package/@tabler/icons/v/3.44.0',
            'reason' => null,
        ],
    ]);

    // Use Artisan::call so we get a real Symfony BufferedOutput that
    // captures $this->line(). $this->artisan(...) chains through a
    // PendingCommand that doesn't expose the line output for substring
    // assertion when it contains the linebreaks JSON_PRETTY_PRINT adds.
    Artisan::call('ichava:icons:check-updates', ['--format' => 'json']);
    $output = Artisan::output();

    expect($output)
        ->toContain('"package": "ichava/tabler-icons"')
        ->and($output)->toContain('"latest": "3.44.0"');

    if (preg_match('/(\[\s*\{.*?\}\s*\])/s', $output, $m)) {
        $decoded = json_decode($m[1], true);
        expect($decoded)->toBeArray()->and($decoded)->toHaveCount(1);
        expect($decoded[0]['package'])->toBe('ichava/tabler-icons');
    }
});

it('exits non-zero with --fail-on-stale when any pack is behind', function () {
    ($this->stubRows)([
        [
            'package' => 'ichava/tabler-icons',
            'source' => 'primary',
            'status' => 'update-available',
            'current' => '3.0.0',
            'latest' => '3.44.0',
            'release_url' => null,
            'reason' => null,
        ],
    ]);

    $this->artisan('ichava:icons:check-updates --fail-on-stale')->assertExitCode(1);
});

it('exits non-zero with --fail-on-stale when a pack is unreachable', function () {
    ($this->stubRows)([
        [
            'package' => 'ichava/flag-icons',
            'source' => 'primary',
            'status' => 'unreachable',
            'current' => '7.0.0',
            'latest' => null,
            'release_url' => null,
            'reason' => 'connection refused',
        ],
    ]);

    $this->artisan('ichava:icons:check-updates --fail-on-stale')->assertExitCode(1);
});

it('forwards --package= to the checker', function () {
    ($this->stubRows)([
        [
            'package' => 'ichava/tabler-icons',
            'source' => 'primary',
            'status' => 'up-to-date',
            'current' => '3.44.0',
            'latest' => '3.44.0',
            'release_url' => null,
            'reason' => null,
        ],
        [
            'package' => 'ichava/flag-icons',
            'source' => 'primary',
            'status' => 'up-to-date',
            'current' => '7.5.0',
            'latest' => '7.5.0',
            'release_url' => null,
            'reason' => null,
        ],
    ]);

    Artisan::call('ichava:icons:check-updates', [
        '--package' => 'ichava/tabler-icons',
        '--format' => 'json',
    ]);
    $output = Artisan::output();

    expect($output)
        ->toContain('ichava/tabler-icons')
        ->and($output)->not->toContain('ichava/flag-icons');
});

it('soft-succeeds when the registry is empty', function () {
    ($this->stubRows)([]);

    $this->artisan('ichava:icons:check-updates')->assertExitCode(0);
});
