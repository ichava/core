<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Contracts\Console\Kernel;
use Simtabi\Laranail\Ichava\Commands\CacheCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Simtabi\Laranail\Ichava\Actions\ClearDiscoveryCaches;
use Simtabi\Laranail\Ichava\Services\CacheOperationsService;
use Simtabi\Laranail\Ichava\Tests\Support\RunsCommandsForCharacterization;

/*
|--------------------------------------------------------------------------
| Characterization: `ichava::ichava-core.cache`
|--------------------------------------------------------------------------
|
| Pins what the command prints and asks. Three of its six actions -- clear,
| refresh and generate -- called cache methods that never existed; they
| were pinned broken before the console refactor and fixed in it.
|
*/

uses(RunsCommandsForCharacterization::class);

const CACHE_COMMAND = 'ichava::ichava-core.cache';

beforeEach(function (): void {
    $this->manifestPath = sys_get_temp_dir() . '/ichava-cache-characterization-' . uniqid() . '.json';
});

afterEach(function (): void {
    File::delete($this->manifestPath);
});

/**
 * Bind a CacheOperationsService whose rebuild() throws, and register a fresh
 * command so it receives it -- Artisan built the original during setUp().
 */
function bindThrowingCacheOperations(): void
{
    $service = Mockery::mock(CacheOperationsService::class)->makePartial();
    $service->shouldReceive('rebuild')->andThrow(new RuntimeException('store unreachable'));
    app()->instance(CacheOperationsService::class, $service);

    app(Kernel::class)->registerCommand(app(CacheCommand::class));
}

it('prompts for an action when none is given, defaulting to stats', function (): void {
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, [], ['stats']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        'What cache operation would you like to perform? [Stats - Show cache statistics]',
        'Clear - Remove all cached data',
        'Manifest - Generate icon manifest file',
        '📊 Ichava Cache Statistics',
    ]);
});

it('prints the cache statistics table for stats', function (): void {
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'stats']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '📊 Ichava Cache Statistics',
        'Metric',
        'Value',
        'Cache Driver',
        'array',
        'Cached Packages',
        'Cached Categories',
        'Total Cache Keys',
        'Manifest Exists',
        '✗ No',
        'Manifest Stale',
        '⚠ Yes',
    ]);
});

it('reports an invalid action and returns INVALID when the offered select is cancelled', function (): void {
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'bogus'], ['cancel']);

    $this->assertSame(2, $exit);
    $this->assertDisplayContains($display, [
        'Invalid action: bogus',
        'Valid actions: clear, rebuild, refresh, generate, manifest, stats',
        'Would you like to select a valid action? [Cancel operation]',
    ]);
});

it('rebuilds and prints the rebuild table', function (): void {
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'rebuild']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🔨 Rebuilding icon caches',
        'Categories',
        'Packages',
        'Total Icons',
        'Build Time',
        '✅ Cache rebuilt successfully',
    ]);
});

it('reports the tryExecute failure message when rebuild throws', function (): void {
    bindThrowingCacheOperations();

    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'rebuild']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['Failed to rebuild cache: store unreachable']);
    $this->assertDisplayLacks($display, ['File: ', '#0 ', '✅ Cache rebuilt successfully']);
});

it('adds the file and line at -v, but no stack trace', function (): void {
    // Traces can carry call arguments, so tryExecute holds them back to -vvv,
    // the policy laranail/console's ExceptionRenderer applies everywhere. It
    // used to print the whole trace at -v.
    bindThrowingCacheOperations();

    [$exit, $display] = $this->runCommand(
        CACHE_COMMAND,
        ['action' => 'rebuild'],
        verbosity: OutputInterface::VERBOSITY_VERBOSE,
    );

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['Failed to rebuild cache: store unreachable', 'File: ']);
    $this->assertDisplayLacks($display, ['#0 ']);
});

it('prints the stack trace at -vvv', function (): void {
    bindThrowingCacheOperations();

    [$exit, $display] = $this->runCommand(
        CACHE_COMMAND,
        ['action' => 'rebuild'],
        verbosity: OutputInterface::VERBOSITY_DEBUG,
    );

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['Failed to rebuild cache: store unreachable', 'File: ', 'Trace: ', '#0 ']);
});

it('clears the discovery caches, each pack count cache and the watcher fingerprints', function (): void {
    // clear used to call IconCacheService::forgetPattern(), which never
    // existed, so it failed on every run.
    $generation = ClearDiscoveryCaches::generation();

    [$exit, $display] = $this->runCommand(
        CACHE_COMMAND,
        ['action' => 'clear'],
        verbosity: OutputInterface::VERBOSITY_VERBOSE,
    );

    $this->assertSame(0, $exit);
    $this->assertSame($generation + 1, ClearDiscoveryCaches::generation(), 'The discovery caches were not retired.');
    $this->assertDisplayContains($display, [
        '🧹 Clearing icon caches',
        'Cleared 3 cache group(s)',
        'Cleared Caches',
        'ichava.discovery.*',
        'ichava.discovery.manifest.',
        'ichava.directory.fingerprints',
        '⏱️  Completed in',
    ]);
    $this->assertDisplayLacks($display, ['Call to undefined method']);
});

it('clears one pack with --package', function (): void {
    $generation = ClearDiscoveryCaches::generation();

    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'clear', '--package' => 'ichava/test-icons']);

    $this->assertSame(0, $exit);
    $this->assertSame($generation + 1, ClearDiscoveryCaches::generation());
    $this->assertDisplayContains($display, [
        'Clearing cache for package: ichava/test-icons...',
        'Cleared 2 cache group(s)',
    ]);
});

it('fails clear --package for a pack that is not registered', function (): void {
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'clear', '--package' => 'ichava/nope']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['Failed to clear cache: ', 'ichava/nope']);
});

it('refreshes by clearing and rebuilding', function (): void {
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'refresh']);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, [
        '🔄 Refreshing icon caches',
        'Clearing and rebuilding caches...',
        'Keys Cleared',
        'Total Icons',
        '✅ Cache refreshed successfully',
    ]);
});

it('generates production caches: warms discovery and writes the manifest', function (): void {
    // generate used to call IconCacheService::generateProductionCache(),
    // which never existed. It now does what a deployment needs: rebuild
    // plus manifest.
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'generate', '--path' => $this->manifestPath]);

    $this->assertSame(0, $exit);
    $this->assertFileExists($this->manifestPath);
    $this->assertDisplayContains($display, [
        '⚡ Generating production cache',
        'Cache Driver',
        '✅ Production cache generated',
    ]);
});

it('generates a manifest at --path and prints where it went', function (): void {
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'manifest', '--path' => $this->manifestPath]);

    $this->assertSame(0, $exit);
    $this->assertFileExists($this->manifestPath);
    $this->assertDisplayContains($display, [
        '🎨 Generating Ichava icon manifest',
        'Generating manifest file...',
        'Packages',
        'Total Icons',
        'File Size',
        'Build Time',
        "📁 Manifest saved to: {$this->manifestPath}",
        '💡 Add this command to your deployment process: php artisan ichava::ichava-core.cache manifest --force',
        '✅ Manifest generation complete!',
    ]);
});

it('skips a fresh manifest without --force', function (): void {
    $this->runCommand(CACHE_COMMAND, ['action' => 'manifest', '--path' => $this->manifestPath]);

    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'manifest', '--path' => $this->manifestPath]);

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['🟢 Manifest is fresh; skipping. Use --force to rebuild.']);
    $this->assertDisplayLacks($display, ['📁 Manifest saved to:']);
});

it('rebuilds a fresh manifest under --force without asking', function (): void {
    $this->runCommand(CACHE_COMMAND, ['action' => 'manifest', '--path' => $this->manifestPath]);

    [$exit, $display] = $this->runCommand(
        CACHE_COMMAND,
        ['action' => 'manifest', '--path' => $this->manifestPath, '--force' => true],
    );

    $this->assertSame(0, $exit);
    $this->assertDisplayContains($display, ['✅ Manifest generation complete!']);
    $this->assertDisplayLacks($display, ['Manifest exists and is stale. Overwrite?']);
});

it('asks before overwriting a stale manifest, and fails when declined', function (): void {
    $this->runCommand(CACHE_COMMAND, ['action' => 'manifest', '--path' => $this->manifestPath]);
    touch($this->manifestPath, time() - 7200);

    [$exit, $display] = $this->runCommand(
        CACHE_COMMAND,
        ['action' => 'manifest', '--path' => $this->manifestPath],
        ['no'],
    );

    // Declining returns FAILURE, unlike every other cancellation in the
    // commands, which return SUCCESS.
    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, [
        'Manifest exists and is stale. Overwrite? (yes/no) [yes]',
        'Manifest generation cancelled.',
    ]);
});
