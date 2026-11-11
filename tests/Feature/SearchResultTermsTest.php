<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconDiscoveryService;

/*
 * Ichava::search() and the discovery service read categories and variants from the term
 * relations. They used to read `category` and `variant` off the icons table, which has no
 * such columns: every result carried null for both, and a category filter was an SQL error
 * on every driver.
 */
beforeEach(function (): void {
    test()->artisan('ichava::ichava-core.database', ['action' => 'seed', '--sync' => true])->run();
});

it('reports each result\'s category from its terms', function (): void {
    $results = app(IconDiscoveryService::class)->searchIcons('circle', ['ichava/test-icons']);

    expect($results['items'] ?? [])->not->toBeEmpty();
    foreach ($results['items'] as $item) {
        $this->assertSame('test-icons', $item['category'], "{$item['name']} lost its category");
    }
});

it('filters by category without a database error', function (): void {
    $service = app(IconDiscoveryService::class);

    $matching = $service->searchIcons('', ['ichava/test-icons'], ['test-icons']);
    $none = $service->searchIcons('', ['ichava/test-icons'], ['no-such-category']);

    expect($matching['total'])->toBeGreaterThan(0)
        ->and($none['total'])->toBe(0);
});
