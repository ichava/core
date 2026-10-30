<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Jobs\SeedIconsJob;
use Simtabi\Laranail\Ichava\Support\PathResolver;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Enums\OptimizationLevel;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;
use Simtabi\Laranail\Ichava\View\Components\IconComponent;
use Simtabi\Laranail\Ichava\Providers\IchavaServiceProvider;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;

/*
 * Config keys that were shipped and read by nothing, now read where they
 * claim to act. Each case fails against the code before the wire-in.
 */

function coreProvider(): IchavaServiceProvider
{
    $provider = app()->getProvider(IchavaServiceProvider::class);
    expect($provider)->toBeInstanceOf(IchavaServiceProvider::class);

    return $provider;
}

function callProvider(string $method): mixed
{
    return (new ReflectionMethod(IchavaServiceProvider::class, $method))->invoke(coreProvider());
}

it('registers log channels from config, defaulting the main channel to warning', function (): void {
    expect(config('logging.channels.ichava.level'))->toBe('warning')
        ->and(config('logging.channels.ichava-audit.permission'))->toBe(0640);

    // What a host's published config (and so `config:cache`) carries.
    config()->set('ichava.ichava-core.logging.channels.ichava.level', 'error');
    config()->set('ichava.ichava-core.logging.channels.ichava-icons.days', 30);
    callProvider('registerLogChannels');

    expect(config('logging.channels.ichava.level'))->toBe('error')
        ->and(config('logging.channels.ichava-icons.days'))->toBe(30);
});

it('builds the Horizon supervisor from config, with three processes by default', function (): void {
    expect(callProvider('horizonSupervisorConfig'))->toMatchArray(['maxProcesses' => 3, 'queue' => ['ichava-icons']]);

    config()->set('ichava.ichava-core.queue.horizon.supervisor.maxProcesses', 8);
    config()->set('ichava.ichava-core.queue.name', 'icons-high');

    expect(callProvider('horizonSupervisorConfig'))->toMatchArray(['maxProcesses' => 8, 'queue' => ['icons-high']]);
});

it('puts seeding jobs on the configured queue connection', function (): void {
    expect((new SeedIconsJob('p', [], 1, 1))->connection)->toBeNull();

    config()->set('ichava.ichava-core.queue.connection', 'redis');

    expect((new SeedIconsJob('p', [], 1, 1))->connection)->toBe('redis');
});

it('builds the SVG optimiser from optimization.*, and keys the render on it', function (): void {
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><!-- c --><title>Home</title><metadata>x</metadata>  <path d="M0 0h2v2z"/></svg>';

    $basic = SvgProcessingService::fromConfig();
    expect($basic->getOptimizationLevel())->toBe(OptimizationLevel::BASIC);

    config()->set('ichava.ichava-core.optimization.level', 'aggressive');
    config()->set('ichava.ichava-core.optimization.minify', false);
    $tuned = SvgProcessingService::fromConfig();
    $out = $tuned->optimize($svg);

    expect($tuned->getOptimizationLevel())->toBe(OptimizationLevel::AGGRESSIVE)
        ->and($out)->not->toContain('<metadata')
        ->and($out)->toContain('<title>Home</title>')   // the accessible name survives
        ->and($out)->toContain('>  <path')              // minify overridden off
        ->and($tuned->renderFingerprint())->not->toBe($basic->renderFingerprint())
        ->and(app(SvgProcessingService::class)->getOptimizationLevel())->toBe(OptimizationLevel::BASIC);
});

it('registers the test set only when test.enabled, from test.path', function (): void {
    $registry = app(IconRegistry::class);
    expect($registry->hasSet('ichava/test-icons'))->toBeTrue();

    app()->forgetInstance(IconRegistry::class);
    config()->set('ichava.ichava-core.test.enabled', false);
    callProvider('registerTestIconSet');
    expect(app(IconRegistry::class)->hasSet('ichava/test-icons'))->toBeFalse();

    app()->forgetInstance(IconRegistry::class);
    config()->set('ichava.ichava-core.test.enabled', true);
    config()->set('ichava.ichava-core.test.path', dirname(__DIR__) . '/fixtures/KindPack');
    callProvider('registerTestIconSet');
    expect(app(IconRegistry::class)->hasSet('ichava-fixtures/kind-pack'))->toBeTrue()
        ->and(app(IconRegistry::class)->hasSet('ichava/test-icons'))->toBeFalse();
});

it('uses separators.category and refuses one equal to the package separator', function (): void {
    config()->set('ichava.ichava-core.separators.category', '~');
    $resolver = new PathResolver;

    expect($resolver->buildIconPath('home', 'v/p', null, 'ui'))->toBe('v/p::ui~home')
        ->and($resolver->parseIconPath('v/p::ui~home')->fullPath)->toBe('ui/home');

    config()->set('ichava.ichava-core.separators.category', '::');
    expect(fn () => new PathResolver)->toThrow(IchavaException::class);
});

it('narrows icon paths with the validation patterns and never widens them', function (): void {
    $resolver = new PathResolver;
    expect($resolver->parseIconPath('ichava/icon-sets-tabler::outline/arrow_up')->name)->toBe('arrow_up')
        ->and($resolver->parseIconPath('ichava/icon-sets-tabler::outline.home')->name)->toBe('home');

    config()->set('ichava.ichava-core.validation.icon_name_pattern', '/^[a-z]+$/');
    expect(fn () => (new PathResolver)->parseIconPath('v/p::ui/arrow-up'))->toThrow(IchavaException::class);

    config()->set('ichava.ichava-core.validation.icon_path_pattern', '/.*/');
    config()->set('ichava.ichava-core.validation.icon_name_pattern', '/.*/');
    expect(fn () => (new PathResolver)->parseIconPath('v/p::../../etc/passwd'))->toThrow(IchavaException::class);
});

it('applies defaults.class only when no class and no size is passed', function (): void {
    $render = function (array $bag = [], ?string $size = null): string {
        $component = new IconComponent(
            name: 'ichava/test-icons::test-icons/check',
            size: $size,
            iconRegistry: app(IconRegistry::class),
            svgProcessor: app(SvgProcessingService::class),
        );
        $component->withAttributes($bag);

        return $component->render()->toHtml();
    };

    config()->set('ichava.ichava-core.defaults.class', 'ix-default');
    config()->set('ichava.ichava-core.defaults.attributes', ['aria-hidden' => 'true']);

    expect($render())->toContain('ix-default')->toContain('aria-hidden="true"')
        ->and($render(['class' => 'mine']))->not->toContain('ix-default')
        ->and($render([], 'lg'))->not->toContain('ix-default')
        // A size preset's class joins the caller's class instead of replacing it.
        ->and($render(['class' => 'mine'], 'lg'))->toContain('mine')->toContain('icon-lg');
})->skip(fn () => ! app(IconRegistry::class)->has('ichava/test-icons::test-icons/check'), 'test set has no check icon');

it('seeds a pack under the smart-queue threshold synchronously', function (): void {
    Bus::fake();

    test()->artisan('ichava::ichava-core.database', ['action' => 'seed'])->run();

    Bus::assertNothingBatched();
    expect(Icon::count())->toBeGreaterThan(0);

    config()->set('ichava.ichava-core.database.smart_queue_threshold', '1');
    expect(app(DatabaseOperationsService::class)->shouldUseQueue(2))->toBeTrue()
        ->and(app(DatabaseOperationsService::class)->shouldUseQueue(2, forceSync: true))->toBeFalse();
});

it('logs a failed seeding job through the logger job path', function (): void {
    $log = Log::spy();
    $log->shouldReceive('channel')->andReturn($log);

    expect(fn () => (new SeedIconsJob('not/registered', [], 1, 1))->handle(app(IchavaLogger::class)))->toThrow(RuntimeException::class);

    $log->shouldHaveReceived('channel')->with('ichava-icons');
    $log->shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'Job failed: ' . SeedIconsJob::class);
});

it('throws only named exceptions from src', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS));
    $seen = 0;
    $generic = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $seen++;

        if (preg_match('/new\s+IchavaException\s*\(/', (string) file_get_contents($file->getPathname()))) {
            $generic[] = $file->getFilename();
        }
    }

    expect($seen)->toBeGreaterThan(80)->and($generic)->toBe([]);
});

it('reports a missing directory with directoryNotFound', function (): void {
    expect(fn () => (new PathResolver)->ensureDirectory(__FILE__))
        ->toThrow(IchavaException::class, 'Directory not found');
});
