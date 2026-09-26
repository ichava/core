<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Contracts\Console\Kernel;
use Simtabi\Laranail\Ichava\Commands\CacheCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Simtabi\Laranail\Ichava\Services\CacheOperationsService;
use Simtabi\Laranail\Ichava\Tests\Support\RunsCommandsForCharacterization;

/*
|--------------------------------------------------------------------------
| Characterization: `ichava::ichava-core.cache`
|--------------------------------------------------------------------------
|
| Pins what the command prints and asks TODAY, before the console refactor.
| Three of its six actions are broken on main -- pinned as they are, so the
| refactor fixes them visibly rather than by accident.
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
    $this->assertDisplayContains($display, ['✗ Failed to rebuild cache: store unreachable']);
    $this->assertDisplayLacks($display, ['#0 ', '✅ Cache rebuilt successfully']);
});

it('prints the full stack trace at -v when tryExecute catches', function (): void {
    // characterization: tryExecute prints the whole trace at -v, where the
    // laranail/console base holds traces back to -vvv; changes in the refactor.
    bindThrowingCacheOperations();

    [$exit, $display] = $this->runCommand(
        CACHE_COMMAND,
        ['action' => 'rebuild'],
        verbosity: OutputInterface::VERBOSITY_VERBOSE,
    );

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, ['✗ Failed to rebuild cache: store unreachable', '#0 ']);
});

it('fails clear on a method the cache service does not have', function (): void {
    // characterization: CacheOperationsService::clearAll() calls
    // IconCacheService::forgetPattern(), which does not exist. The Error is not
    // an Exception, so tryExecute's catch misses it and the laranail/console
    // base reports it instead ("Command failed:"), skipping the command's own
    // failure line. Changes in the refactor.
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'clear']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, [
        '🧹 Clearing icon caches',
        'Clearing all caches...',
        'Command failed: Call to undefined method Simtabi\Laranail\Ichava\Services\IconCacheService::forgetPattern()',
    ]);
    $this->assertDisplayLacks($display, ['✗ Failed to clear cache']);
});

it('fails clear --package the same way', function (): void {
    // characterization: same undefined forgetPattern(); changes in the refactor.
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'clear', '--package' => 'ichava/test-icons']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, [
        'Clearing cache for package: ichava/test-icons...',
        'Command failed: Call to undefined method',
    ]);
});

it('fails refresh the same way, because it clears first', function (): void {
    // characterization: refresh() calls clearAll(); changes in the refactor.
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'refresh']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, [
        '🔄 Refreshing icon caches',
        'Clearing and rebuilding caches...',
        'Command failed: Call to undefined method Simtabi\Laranail\Ichava\Services\IconCacheService::forgetPattern()',
    ]);
    $this->assertDisplayLacks($display, ['✅ Cache refreshed successfully']);
});

it('fails generate on a method the cache service does not have', function (): void {
    // characterization: generateProductionCache() is called on IconCacheService,
    // which does not declare it; the Error escapes tryExecute. Changes in the
    // refactor.
    [$exit, $display] = $this->runCommand(CACHE_COMMAND, ['action' => 'generate']);

    $this->assertSame(1, $exit);
    $this->assertDisplayContains($display, [
        '⚡ Generating production cache',
        'Command failed: Call to undefined method Simtabi\Laranail\Ichava\Services\IconCacheService::generateProductionCache()',
    ]);
    $this->assertDisplayLacks($display, ['✅ Production cache generated']);
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
