<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Actions\UpdatePreferences;
use Simtabi\Laranail\Ichava\Services\IconPreferenceService;
use Simtabi\Laranail\Ichava\Tests\Support\InMemoryOwnerPreferenceStore;

/*
 * One action behind every preferences write, so REST and Inertia agree.
 * Runs against a store that actually stores: the default harness store
 * no-ops, and a test against it would pass on an empty array.
 */

beforeEach(function (): void {
    InMemoryOwnerPreferenceStore::$rows = [];
    test()->artisan('ichava::ichava-core.database', ['action' => 'seed', '--sync' => true])->run();

    $this->service = new IconPreferenceService(app(IchavaLogger::class), new InMemoryOwnerPreferenceStore(tier: 'session'));
    $this->action = new UpdatePreferences($this->service);
});

it('routes each section through its setter', function (): void {
    $this->action->handle([
        'packages'    => ['ichava/test-icons', 7],
        'sorting'     => ['sort_by' => 'created_at', 'sort_direction' => 'DESC'],
        'preferences' => ['view_mode' => 'list'],
        'tree'        => ['expanded_nodes' => ['ichava/test-icons'], 'checked_nodes' => []],
    ]);

    expect($this->service->getPackages())->toBe(['ichava/test-icons'])
        ->and($this->service->getSorting())->toBe(['sort_by' => 'created_at', 'sort_direction' => 'desc'])
        ->and($this->service->getPreferences())->toMatchArray(['view_mode' => 'list', 'icon_size' => 48])
        ->and($this->service->getTreeState()['expanded_nodes'])->toBe(['ichava/test-icons']);
});

it('refuses an unknown sort direction and ignores unknown sections', function (): void {
    $stored = $this->action->handle([
        'sorting'   => ['sort_by' => 'name', 'sort_direction' => 'sideways'],
        'favorites' => [1, 2, 3],
        'anything'  => 'goes',
    ]);

    expect($this->service->getSorting()['sort_direction'])->toBe('asc')
        ->and($this->service->getFavorites())->toBe([])
        ->and($stored)->not->toHaveKey('anything');
});

it('drops packages that are not seeded through validate()', function (): void {
    $stored = $this->action->handle(['packages' => ['ichava/test-icons', 'gone/pack']]);

    expect($stored['filters']['packages'] ?? null)->toBe(['ichava/test-icons']);
});
