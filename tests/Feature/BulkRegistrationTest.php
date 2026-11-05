<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Ichava\Data\BulkResult;
use Simtabi\Laranail\Ichava\Data\BulkOptions;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\IchavaRegistrar;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Events\IconRegistrationEvent;

/*
 * Proposal 3. Bulk registration is registerMany() over the same PackDefinition
 * path, so a set registered in bulk is indistinguishable from one registered
 * alone, and the old registerBulkIconSets()/IchavaRegistrar helper runs on it.
 */

function bulkTree(array $sets, array $broken = [], array $bare = []): string
{
    $root = sys_get_temp_dir() . '/ichava-bulk-' . bin2hex(random_bytes(4));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>';

    foreach ($sets as $set) {
        File::ensureDirectoryExists("{$root}/{$set}/files");
        File::put("{$root}/{$set}/files/square.svg", $svg);
        File::put("{$root}/{$set}/config.json", json_encode(['package' => ['name' => "bulk/{$set}"], 'config' => ['icon_prefix' => $set]]));
    }

    foreach ($broken as $set) {
        File::ensureDirectoryExists("{$root}/{$set}");
        File::put("{$root}/{$set}/config.json", '{ not json');
    }

    foreach ($bare as $set) {
        File::ensureDirectoryExists("{$root}/{$set}/files");
    }

    return $root;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/ichava-bulk-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('discovers one builder per config-bearing sub-directory, in name order', function (): void {
    $root = bulkTree(['beta', 'alpha', 'gamma'], bare: ['no-config']);

    expect(array_map(fn (PackBuilder $b): string => basename($b->basePath()), PackBuilder::discover($root)))->toBe(['alpha', 'beta', 'gamma'])
        ->and(array_map(fn (PackBuilder $b): string => basename($b->basePath()), PackBuilder::discover($root, ['gamma', 'alpha'])))->toBe(['alpha', 'gamma']);
});

it('registers every discovered set exactly as registering it alone would', function (): void {
    $root = bulkTree(['alpha', 'beta', 'gamma']);

    $result = app(IconRegistry::class)->registerMany(PackBuilder::discover($root));

    expect($result)->toBeInstanceOf(BulkResult::class)
        ->and($result->registered)->toBe(['bulk/alpha', 'bulk/beta', 'bulk/gamma'])
        ->and($result->failed)->toBe([]);

    $bulk = app(IconRegistry::class)->get('bulk/beta');
    app()->forgetInstance(IconRegistry::class);
    app(IconRegistry::class)->fromDirectory($root . '/beta');
    $alone = app(IconRegistry::class)->get('bulk/beta');

    unset($bulk['registered_at'], $alone['registered_at']);
    expect($bulk)->toBe($alone);
});

it('reports a broken set and registers the rest, or throws with failFast', function (): void {
    $root = bulkTree(['alpha', 'gamma'], broken: ['beta']);

    $result = app(IconRegistry::class)->registerMany(PackBuilder::discover($root));

    expect($result->registered)->toBe(['bulk/alpha', 'bulk/gamma'])
        ->and(array_keys($result->failed))->toBe([$root . '/beta']);

    app()->forgetInstance(IconRegistry::class);
    expect(fn () => app(IconRegistry::class)->registerMany(PackBuilder::discover($root), new BulkOptions(failFast: true)))
        ->toThrow(IchavaException::class);
});

it('announces one run: started, each registered, then completed, under one registrar id', function (): void {
    Event::fake([IconRegistrationEvent::class]);
    $root = bulkTree(['alpha', 'beta', 'gamma']);

    app(IconRegistry::class)->registerMany(PackBuilder::discover($root), new BulkOptions(name: 'fixture-bulk'));

    $events = Event::dispatched(IconRegistrationEvent::class)->map(fn (array $call) => $call[0]);
    $actions = $events->map(fn (IconRegistrationEvent $e): string => $e->action)->all();

    expect($actions[0])->toBe(IconRegistrationEvent::ACTION_STARTED)
        ->and(end($actions))->toBe(IconRegistrationEvent::ACTION_COMPLETED)
        ->and(array_count_values($actions)[IconRegistrationEvent::ACTION_REGISTERED])->toBe(3)
        ->and(array_count_values($actions)[IconRegistrationEvent::ACTION_COMPLETED])->toBe(1)
        ->and($events->map(fn (IconRegistrationEvent $e): string => $e->registrarId)->unique()->count())->toBe(1)
        ->and($events->filter(fn (IconRegistrationEvent $e): bool => $e->isRegistered())->count())->toBe(3)
        ->and($events->last()->metadata['statistics'])->toBe(['registered' => 3, 'skipped' => 0, 'failed' => 0]);
});

it('applies the per-set values registerBulkIconSets() is given', function (): void {
    $root = bulkTree(['alpha', 'beta']);

    $registrar = IchavaRegistrar::register('fixture/bulk')
        ->basePath($root)
        ->trackStatistics()
        ->configure(['alpha' => ['prefix' => 'a1'], 'beta' => []]);

    expect(app(IconRegistry::class)->get('bulk/alpha')['prefix'])->toBe('a1')
        ->and(app(IconRegistry::class)->get('bulk/beta')['prefix'])->toBe('beta')
        ->and($registrar->statistics()?->registered)->toBe(['bulk/alpha', 'bulk/beta']);
});

it('discovers every set when registerBulkIconSets() is given none', function (): void {
    $root = bulkTree(['alpha', 'beta']);

    IchavaRegistrar::register('fixture/bulk')->basePath($root)->configure([]);

    expect(app(IconRegistry::class)->isRegistered('bulk/alpha'))->toBeTrue()
        ->and(app(IconRegistry::class)->isRegistered('bulk/beta'))->toBeTrue();
});

it('logs a run whether logging is enabled before or after configure()', function (): void {
    $root = bulkTree(['alpha']);
    config()->set('ichava.ichava-core.logging.enabled', true);
    app()->forgetInstance(Simtabi\Laranail\Ichava\Services\IchavaLogger::class);
    $log = Illuminate\Support\Facades\Log::spy();
    $log->shouldReceive('channel')->andReturn($log);

    IchavaRegistrar::register('fixture/before')->basePath($root)->enableLogging()->configure(['alpha' => []]);
    app()->forgetInstance(IconRegistry::class);
    IchavaRegistrar::register('fixture/after')->basePath($root)->configure(['alpha' => []])->enableLogging();

    $log->shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_contains($message, 'fixture/before'))->once();
    $log->shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_contains($message, 'fixture/after'))->once();
});

it('registers core test icons through the bulk path on every boot', function (): void {
    expect(app(IconRegistry::class)->isRegistered('ichava/test-icons'))->toBeTrue();
});
