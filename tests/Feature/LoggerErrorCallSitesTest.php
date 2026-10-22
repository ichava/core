<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Events\IconCacheEvent;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\IconCacheService;
use Simtabi\Laranail\Ichava\Listeners\InvalidateIconCache;
use Simtabi\Laranail\Ichava\Services\IconWatcherService;
use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;
use Simtabi\Laranail\Ichava\Services\IchavaLifecycleManager;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;

/**
 * `IchavaLogger::error(string $message, ?Throwable $exception = null, array $context = [])`
 * takes the exception second. Three call sites passed their context array
 * there instead, so each error path threw a TypeError at the moment it tried
 * to report the error it had caught -- replacing a logged, handled failure
 * with an unhandled one.
 *
 * Each case drives the real error path and asserts it completes and that the
 * context reaches the log, rather than grepping the call site.
 */
beforeEach(function (): void {
    $this->logged = [];

    Log::shouldReceive('channel')->andReturnUsing(function () {
        $channel = Mockery::mock();
        $channel->shouldReceive('error')->andReturnUsing(function (string $message, array $context = []): void {
            $this->logged[] = ['message' => $message, 'context' => $context];
        });
        $channel->shouldIgnoreMissing();

        return $channel;
    });
});

it('logs a missing migration directory instead of throwing', function (): void {
    $service = new class(app(IchavaLogger::class), app(IconRegistry::class)) extends DatabaseOperationsService
    {
        protected function migrationPath(): ?string
        {
            return null;
        }
    };

    expect($service->runMigrations())->toBe(1);

    $entry = collect($this->logged)->firstWhere('message', '🗄️ Ichava migration directory is missing');
    expect($entry)->not->toBeNull();
    expect($entry['context'])->toHaveKey('looked_in');
});

it('logs a directory the watcher cannot scan instead of throwing', function (): void {
    File::shouldReceive('allFiles')->andThrow(IchavaException::invalidConfiguration('unreadable'));

    $watcher = new IconWatcherService(app(IconRegistry::class), app(IchavaLogger::class));
    $scan = new ReflectionMethod($watcher, 'scanDiskIcons');

    expect($scan->invoke($watcher, '/nowhere', 'tests/pack'))->toBe([]);

    $entry = collect($this->logged)->firstWhere('message', 'Failed to scan directory');
    expect($entry)->not->toBeNull();
    expect($entry['context'])->toMatchArray(['package' => 'tests/pack', 'path' => '/nowhere']);
    expect($entry['context'])->toHaveKey('exception');
});

it('logs a failed cache invalidation instead of throwing', function (): void {
    $discovery = Mockery::mock(IconDiscoveryService::class);
    $discovery->shouldReceive('clearCache')->andThrow(new RuntimeException('store down'));

    $lifecycle = Mockery::mock(IchavaLifecycleManager::class);
    $lifecycle->shouldReceive('hasCache')->andReturnTrue();

    $listener = new InvalidateIconCache(
        app(IconCacheService::class),
        $discovery,
        $lifecycle,
        app(IchavaLogger::class),
    );

    $listener->handle(IconCacheEvent::changed('tests/pack', 'test'));

    $entry = collect($this->logged)->firstWhere('message', 'Failed to invalidate icon cache');
    expect($entry)->not->toBeNull();
    expect($entry['context'])->toMatchArray(['package' => 'tests/pack', 'reason' => 'test']);
    expect($entry['context'])->toHaveKey('exception');
});
