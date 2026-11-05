<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\ConfigurationService;

/**
 * Pins where IconRegistry::fromDirectory() reads a pack's variants and
 * categories from.
 *
 * Every shipped pack, and the scaffolder's config.json stub, keeps them under
 * `metadata.data`. The registry used to look only at the top level, so no pack
 * ever got `supportsCategories`, and IconSetBuilder::buildIconPath() dropped the
 * middle segment of a three-segment path: `glyphs/round/dot` was looked up as
 * `files/glyphs/dot.svg`. That made every icon one directory deeper than the
 * first unreachable (15,393 in icon-sets-bundled, and all 10,567 in the
 * emoji pack before OpenMoji moved out of it).
 *
 * The top-level shape is not read at all: the estate is forward-only, and the
 * fallback that kept a pack written against the old lookup working was
 * removed (no pack ships it).
 */
function packShapeFixture(string $shape): string
{
    return dirname(__DIR__) . '/fixtures/PackConfigShapes/' . $shape;
}

beforeEach(function (): void {
    $this->registry = app(IconRegistry::class);
    $this->registry->fromDirectory(packShapeFixture('nested'));
    $this->registry->fromDirectory(packShapeFixture('top-level'));
});

it('reads variants and categories from metadata.data, the shape every pack ships', function (): void {
    $config = $this->registry->set('ichava-fixtures/nested-shape')->config();

    expect($config->supportsCategories)->toBeTrue()
        ->and($config->variants)->toBe(['default']);
});

it('ignores variants and categories at the top level', function (): void {
    $config = $this->registry->set('ichava-fixtures/top-level-shape')->config();

    expect($config->supportsCategories)->toBeFalse()
        ->and($config->variants)->toBe([]);
});

it('resolves a three-segment path in a metadata.data pack', function (string $set): void {
    $path = "{$set}::glyphs/round/dot";

    expect($this->registry->has($path))->toBeTrue()
        ->and($this->registry->render($path))->toContain('<circle');
})->with([
    'metadata.data shape' => 'ichava-fixtures/nested-shape',
]);

it('reads metadata.data only, whatever the top level holds', function (): void {
    $service = app(ConfigurationService::class);
    $config = [
        'variants'   => ['old' => []],
        'categories' => ['old' => []],
        'metadata'   => ['data' => ['variants' => ['current' => []], 'categories' => ['ui' => []]]],
    ];

    expect(array_keys($service->getVariants($config)))->toBe(['current'])
        ->and($service->hasVariants($config))->toBeTrue()
        ->and($service->hasCategories($config))->toBeTrue()
        ->and($service->hasVariants(['variants' => ['a' => []]]))->toBeFalse()
        ->and($service->hasCategories(['categories' => ['a' => []]]))->toBeFalse();
});

it('reports no variants or categories when metadata.data holds empty maps', function (): void {
    $service = app(ConfigurationService::class);
    $config = ['metadata' => ['data' => ['variants' => [], 'categories' => []]]];

    expect($service->hasVariants($config))->toBeFalse()
        ->and($service->hasCategories($config))->toBeFalse()
        ->and($service->getVariants($config))->toBe([]);
});

it('renders icons named 0 and 00', function (string $path, string $marker): void {
    expect($this->registry->has($path))->toBeTrue()
        ->and($this->registry->render($path))->toContain($marker);
})->with([
    'name 0'            => ['ichava-fixtures/nested-shape::glyphs/round/0', '<ellipse'],
    'name 00'           => ['ichava-fixtures/nested-shape::glyphs/round/00', 'cx="11.5"'],
    'segments 0 and 00' => ['ichava-fixtures/nested-shape::0/00/0', '<ellipse'],
    'dot form with 0'   => ['ichava-fixtures/nested-shape::glyphs.round.0', '<ellipse'],
]);

it('records no set moves or deprecated categories: the estate is forward-only', function (): void {
    $metadata = $this->registry->get('ichava-fixtures/nested-shape');

    expect($metadata)->toHaveKey('kind_rules')
        ->and($metadata)->not->toHaveKey('replaces')
        ->and($metadata)->not->toHaveKey('deprecated_categories')
        ->and(class_exists('Simtabi\Laranail\Ichava\Support\PackAliases'))->toBeFalse()
        ->and(method_exists($this->registry, 'canonicalId'))->toBeFalse();
});
