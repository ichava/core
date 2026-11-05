<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Data\VariantData;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/*
 * Proposal 2: one pack model. Each builder method writes a key a reader
 * already consumes, so each test reads the consumer, not the metadata.
 */

function builderFixture(string $name): string
{
    return dirname(__DIR__) . '/fixtures/' . $name;
}

function plantSet(): string
{
    $dir = sys_get_temp_dir() . '/ichava-builder-' . bin2hex(random_bytes(4));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>';

    foreach (['shapes/circle', 'shapes/dot', 'illustrations/office', 'illustrations/scene', 'illustrations/scene-dark'] as $icon) {
        File::ensureDirectoryExists(dirname("{$dir}/files/{$icon}.svg"));
        File::put("{$dir}/files/{$icon}.svg", $svg);
    }

    return $dir;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/ichava-builder-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('registers a set with no config.json, from code alone', function (): void {
    $registry = app(IconRegistry::class);

    $registry->register(
        PackBuilder::make('acme/social', plantSet() . '/files')
            ->prefix('social')
            ->about('title', 'Acme social')
            ->variant('outline', ['fill' => 'none'], 'Stroked')
            ->build(),
    );

    $meta = $registry->get('acme/social');

    expect($meta['prefix'])->toBe('social')
        ->and($meta['name'])->toBe('Acme social')
        ->and($meta['total'])->toBe(5)
        ->and($meta['vendor'])->toBe('acme')
        ->and(VariantData::fromMetadata('outline', $meta['variant_metadata']['outline'])->attributes)->toBe(['fill' => 'none'])
        ->and($registry->has('acme/social::shapes/circle'))->toBeTrue();
});

it('resolves a kind from a fluent rule, tried before config.json rules', function (): void {
    $registry = app(IconRegistry::class);
    $registry->register(PackBuilder::make('acme/kinds', plantSet() . '/files')
        ->kindRule('illustrations/**', IconKind::Illustration)
        ->build());

    expect($registry->kindOf('acme/kinds::illustrations/office'))->toBe(IconKind::Illustration)
        ->and($registry->kindOf('acme/kinds::shapes/circle'))->toBe(IconKind::Icon);
});

it('resolves a kind per item, per category and for the pack', function (): void {
    $registry = app(IconRegistry::class);
    $registry->register(PackBuilder::make('acme/rows', plantSet() . '/files')
        ->kind(IconKind::Illustration)
        ->categoryKind('shapes', IconKind::Icon)
        ->itemKind('shapes/dot', IconKind::Illustration)
        ->build());

    expect($registry->kindOf('acme/rows::shapes/circle'))->toBe(IconKind::Icon)
        ->and($registry->kindOf('acme/rows::shapes/dot'))->toBe(IconKind::Illustration)
        ->and($registry->kindOf('acme/rows::illustrations/office'))->toBe(IconKind::Illustration);
});

it('records a theme twin the registry resolves', function (): void {
    $registry = app(IconRegistry::class);
    $registry->register(PackBuilder::make('acme/twins', plantSet() . '/files')
        ->themeTwin('illustrations', 'dark', '-dark')
        ->build());

    expect($registry->themeTwin('acme/twins::illustrations/scene'))->toBe('acme/twins::illustrations/scene-dark');
});

it('registers aliases the renderer follows', function (): void {
    $registry = app(IconRegistry::class);
    $registry->register(PackBuilder::make('acme/aliased', plantSet() . '/files')
        ->alias('shapes/ring', 'shapes/dot')
        ->build());

    expect($registry->aliases('acme/aliased'))->toBe(['shapes/ring' => 'shapes/dot'])
        ->and($registry->render('acme/aliased::shapes/ring'))->toContain('<path');
});

it('applies the merge contract over config.json', function (string $method, array $args, Closure $read): void {
    $definition = PackBuilder::fromDirectory(builderFixture('VariantPack'))->{$method}(...$args)->build();

    expect($read($definition))->toBeTrue();
})->with([
    'a scalar override replaces the file value' => ['prefix', ['vp'], fn ($d): bool => $d->prefix === 'vp'],
    'a map entry merges, the file keeps its other keys' => ['variant', ['outline', ['stroke' => 'red']], fn ($d): bool => $d->config['metadata']['data']['variants']['outline']['attributes'] === ['stroke_width' => 2, 'fill' => 'none', 'stroke' => 'red']
        && isset($d->config['metadata']['data']['variants']['filled'])],
    'a fluent rule comes before the file rules' => ['kindRule', ['outline/**', IconKind::Illustration], fn ($d): bool => $d->config['metadata']['data']['kinds']['rules'][0]['match'] === 'outline/**'],
]);

it('prepends fluent rules to the rules config.json declares', function (): void {
    $definition = PackBuilder::fromDirectory(builderFixture('MixedKindPack'))
        ->kindRule('**', IconKind::Icon)
        ->build();
    $rules = $definition->config['metadata']['data']['kinds']['rules'];

    expect($rules[0])->toBe(['match' => '**', 'kind' => 'icon'])
        ->and(array_column(array_slice($rules, 1), 'match'))->toBe(['spot/tiny-*', 'spot/**']);
});

it('refuses what it cannot register', function (Closure $build, string $message): void {
    expect($build)->toThrow(IchavaException::class, $message);
})->with([
    'a name that is not vendor/package' => [fn () => PackBuilder::make('social', '/tmp/x')->build(), 'vendor/package'],
    'an empty prefix' => [fn () => PackBuilder::make('acme/x', '/tmp/x')->prefix('')->build(), 'non-empty prefix'],
    'an invalid glob' => [fn () => PackBuilder::make('acme/x', '/tmp/x')->kindRule('a/**b', IconKind::Icon), 'not a valid glob'],
    'an unknown about key' => [fn () => PackBuilder::make('acme/x', '/tmp/x')->about('colour', 'red'), 'unknown pack field'],
]);

it('gives a set registered through package() the full metadata fromDirectory() gives (C-03)', function (): void {
    $registry = app(IconRegistry::class);
    $dir = builderFixture('MixedKindPack');
    $fromDirectory = PackBuilder::fromDirectory($dir)->build()->toMetadata(0);

    $registry->package('ichava-fixtures/via-package')
        ->fromConfigFile($dir . '/config.json')
        ->register();

    expect(array_keys($registry->get('ichava-fixtures/via-package')))->toContain(...array_keys($fromDirectory))
        ->and($registry->get('ichava-fixtures/via-package')['kind_rules'])->toBe($fromDirectory['kind_rules']);
});

it('runs a conditional chain only when its condition holds', function (): void {
    $definition = PackBuilder::make('acme/when', '/tmp/x/files')
        ->when(false, fn (PackBuilder $b) => $b->prefix('no'))
        ->when(true, fn (PackBuilder $b) => $b->prefix('yes'))
        ->build();

    expect($definition->prefix)->toBe('yes')
        ->and($definition->basePath)->toBe('/tmp/x');
});
