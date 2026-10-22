<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconWatcherService;

/**
 * `scanDiskIcons()` caught only `IchavaException`, but `File::allFiles()` on a
 * directory that does not exist throws Symfony's `DirectoryNotFoundException`.
 * A pack whose asset directory had gone missing therefore crashed the watcher
 * instead of being reported. It now yields no icons and logs a warning.
 *
 * Driven against a real missing path, not a mocked filesystem, so the test
 * fails if the exception `allFiles()` actually throws is not the one caught.
 */
beforeEach(function (): void {
    $this->warnings = [];

    Log::shouldReceive('channel')->andReturnUsing(function () {
        $channel = Mockery::mock();
        $channel->shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []): void {
            $this->warnings[] = ['message' => $message, 'context' => $context];
        });
        $channel->shouldIgnoreMissing();

        return $channel;
    });
});

it('returns no icons and warns when the pack directory does not exist', function (): void {
    $missing = sys_get_temp_dir() . '/ichava-missing-' . bin2hex(random_bytes(6));
    expect(is_dir($missing))->toBeFalse();

    $watcher = new IconWatcherService(app(IconRegistry::class), app(IchavaLogger::class));
    $scan = new ReflectionMethod($watcher, 'scanDiskIcons');

    expect($scan->invoke($watcher, $missing, 'tests/pack'))->toBe([]);

    $entry = collect($this->warnings)->firstWhere('message', 'Icon directory not found');
    expect($entry)->not->toBeNull();
    expect($entry['context'])->toMatchArray(['package' => 'tests/pack', 'path' => $missing]);
});
