<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Ichava\Events\IconRegistrationEvent;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Services\IconRegistry;

/**
 * End-to-end coverage for the public IconRegistry tracking and lookup APIs.
 *
 * The icon ecosystem is built around the assumption that every child pack
 * registers itself with IconRegistry and that the host application can then
 * iterate, count, and resolve those packs at runtime. This test pins that
 * contract so a future refactor cannot silently regress pack discovery.
 */
beforeEach(function (): void {
    $this->fixtureRoot = sys_get_temp_dir().'/ichava-registry-'.bin2hex(random_bytes(4));
    $this->packA = $this->fixtureRoot.'/pack-a';
    $this->packB = $this->fixtureRoot.'/pack-b';

    $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/></svg>';

    foreach ([
        ['dir' => $this->packA, 'name' => 'tests/pack-a', 'prefix' => 'pa'],
        ['dir' => $this->packB, 'name' => 'tests/pack-b', 'prefix' => 'pb'],
    ] as $pack) {
        mkdir($pack['dir'].'/files/'.basename($pack['dir']), 0700, true);
        file_put_contents($pack['dir'].'/config.json', json_encode([
            'schema_version' => '1.0',
            'package' => [
                'name' => $pack['name'],
                'title' => 'Fixture pack',
                'version' => '1.0.0',
                'type' => 'collection',
                'license' => 'MIT',
            ],
            'metadata' => [
                'data' => ['variants' => [], 'categories' => []],
            ],
            'config' => [
                'icon_prefix' => $pack['prefix'],
            ],
        ]));
    }

    file_put_contents($this->packA.'/files/'.basename($this->packA).'/star.svg', $svg);
    file_put_contents($this->packA.'/files/'.basename($this->packA).'/heart.svg', $svg);
    file_put_contents($this->packB.'/files/'.basename($this->packB).'/cube.svg', $svg);
});

afterEach(function (): void {
    if (! empty($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
        (new Filesystem)->deleteDirectory($this->fixtureRoot);
    }
});

it('registers a single icon directory and reports it via all/count/isRegistered', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);
    $startCount = $registry->count();

    $registry->fromDirectory($this->packA, self::class);

    expect($registry->count())->toBeGreaterThan($startCount);
    expect($registry->isRegistered('tests/pack-a'))->toBeTrue();
    expect($registry->get('tests/pack-a')['base_path'])->toBe($this->packA);
});

it('tracks two independent packs without collision', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);

    $registry->fromDirectory($this->packA, self::class);
    $registry->fromDirectory($this->packB, self::class);

    expect($registry->isRegistered('tests/pack-a'))->toBeTrue();
    expect($registry->isRegistered('tests/pack-b'))->toBeTrue();
    expect($registry->get('tests/pack-a')['base_path'])->toBe($this->packA);
    expect($registry->get('tests/pack-b')['base_path'])->toBe($this->packB);
});

it('returns the correct icon-count for a registered directory', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);

    expect($registry->countIconsInDirectory($this->packA.'/files'))->toBe(2);
    expect($registry->countIconsInDirectory($this->packB.'/files'))->toBe(1);
});

it('throws when asking for a package that was never registered', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);

    expect(fn () => $registry->get('vendor/nonexistent'))
        ->toThrow(IchavaException::class);
});

it('unregisters a previously registered package', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);

    $registry->fromDirectory($this->packA, self::class);
    expect($registry->isRegistered('tests/pack-a'))->toBeTrue();

    $removed = $registry->unregister('tests/pack-a');

    expect($removed)->toBeTrue();
    expect($registry->isRegistered('tests/pack-a'))->toBeFalse();
});

it('treats unregister of an unknown package as a no-op', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);

    expect($registry->unregister('vendor/never-registered'))->toBeFalse();
});

it('clears pending and conflict state on unregister', function (): void {
    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);

    $registry->fromDirectory($this->packA, self::class);

    // Inject synthetic pending + conflict markers as if the same package had
    // tripped a prior conflict and queued itself for late registration.
    $reflection = new ReflectionClass($registry);
    $pendingProp = $reflection->getProperty('pending');
    $pendingProp->setAccessible(true);
    $pendingProp->setValue($registry, ['tests/pack-a' => ['stub' => true]]);

    $conflictsProp = $reflection->getProperty('conflicts');
    $conflictsProp->setAccessible(true);
    $conflictsProp->setValue($registry, [
        'icon_set_name' => ['tests/pack-a' => ['where' => 'fixture']],
        'prefix' => ['unrelated/pack' => ['where' => 'fixture']],
    ]);

    $registry->unregister('tests/pack-a');

    expect($pendingProp->getValue($registry))->toBe([]);
    $remaining = $conflictsProp->getValue($registry);
    expect($remaining)->not->toHaveKey('icon_set_name');
    expect($remaining['prefix'] ?? null)->toBe(['unrelated/pack' => ['where' => 'fixture']]);
});

it('dispatches an unregistered event when removing a package', function (): void {
    Event::fake([
        IconRegistrationEvent::class,
    ]);

    /** @var IconRegistry $registry */
    $registry = $this->app->make(IconRegistry::class);
    $registry->fromDirectory($this->packA, self::class);
    $registry->unregister('tests/pack-a');

    Event::assertDispatched(
        IconRegistrationEvent::class,
        fn ($event) => $event->name === 'tests/pack-a' && $event->action === 'unregistered',
    );
});
