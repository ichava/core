<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Support\IconAliasMap;
use Simtabi\Laranail\Ichava\Support\IconRenderer;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * Old icon names keep resolving after a pack merges duplicates.
 *
 * The fixture pack ships one file, `shapes/dot.svg`, and an `aliases.json`
 * pointing the names it dropped (`shapes/circle`, and `shapes.ring` in dot
 * form) at it. Every assertion is made on both the old and the kept name, so
 * an alias that resolved to the wrong icon, or stopped the kept name working,
 * fails here.
 */
function aliasFixture(): string
{
    return dirname(__DIR__) . '/fixtures/IconAliases';
}

beforeEach(function (): void {
    $this->registry = app(IconRegistry::class);
    $this->registry->fromDirectory(aliasFixture());
});

it('loads aliases.json beside config.json, normalising dot form', function (): void {
    expect($this->registry->aliases('ichava-fixtures/aliased'))->toBe([
        'shapes/circle' => 'shapes/dot',
        'shapes/ring'   => 'shapes/dot',
    ]);
});

it('renders an old name exactly as the icon it points at', function (string $old): void {
    $kept = 'ichava-fixtures/aliased::shapes/dot';

    expect($this->registry->has($old))->toBeTrue()
        ->and($this->registry->has($kept))->toBeTrue()
        ->and($this->registry->render($kept))->toContain('<circle')
        ->and($this->registry->render($old))->toBe($this->registry->render($kept));
})->with([
    'slash form'                => 'ichava-fixtures/aliased::shapes/circle',
    'dot form'                  => 'ichava-fixtures/aliased::shapes.circle',
    'alias written in dot form' => 'ichava-fixtures/aliased::shapes/ring',
]);

it('resolves an old name through the renderer the Blade component and helper use', function (): void {
    $old = IconRenderer::make('ichava-fixtures/aliased::shapes/circle')->render();
    $kept = IconRenderer::make('ichava-fixtures/aliased::shapes/dot')->render();

    expect($kept)->toContain('<circle')
        ->and($old)->toBe($kept);
});

it('never lets an alias shadow an icon that exists under its name', function (): void {
    $this->registry->alias('ichava-fixtures/aliased', ['shapes/square' => 'shapes/dot']);

    expect($this->registry->render('ichava-fixtures/aliased::shapes/square'))
        ->toContain('<rect')
        ->not->toContain('<circle');
});

it('still misses a name that is neither an icon nor an alias', function (): void {
    expect($this->registry->has('ichava-fixtures/aliased::shapes/triangle'))->toBeFalse();

    $this->registry->render('ichava-fixtures/aliased::shapes/triangle');
})->throws(IchavaException::class);

it('misses an alias whose kept icon does not exist', function (): void {
    $this->registry->alias('ichava-fixtures/aliased', ['shapes/star' => 'shapes/missing']);

    expect($this->registry->has('ichava-fixtures/aliased::shapes/star'))->toBeFalse();
});

it('forgets a set\'s aliases when the set is unregistered', function (): void {
    $this->registry->unregister('ichava-fixtures/aliased');

    expect($this->registry->aliases('ichava-fixtures/aliased'))->toBe([]);
});

it('refuses an aliases.json that is not a JSON object', function (): void {
    $dir = sys_get_temp_dir() . '/ichava-alias-' . bin2hex(random_bytes(4));
    File::copyDirectory(aliasFixture(), $dir);
    File::put($dir . '/config.json', str_replace('ichava-fixtures/aliased', 'ichava-fixtures/broken-aliases', File::get($dir . '/config.json')));
    File::put($dir . '/aliases.json', '"shapes/circle"');

    try {
        $this->registry->fromDirectory($dir);
    } finally {
        File::deleteDirectory($dir);
    }
})->throws(IchavaException::class, 'aliases.json must be a JSON object');

it('refuses an alias that points at itself', function (): void {
    (new IconAliasMap)->register('vendor/pack', ['shapes/dot' => 'shapes.dot']);
})->throws(IchavaException::class, 'points at itself');

it('refuses an alias that chains to another alias', function (): void {
    $map = new IconAliasMap;
    $map->register('vendor/pack', ['shapes/circle' => 'shapes/dot']);
    $map->register('vendor/pack', ['shapes/ring' => 'shapes/circle']);
})->throws(IchavaException::class, 'which is itself an alias');

it('refuses an alias entry that is not a pair of paths', function (array $aliases): void {
    (new IconAliasMap)->register('vendor/pack', $aliases);
})->throws(IchavaException::class)->with([
    'list, not a map' => [['shapes/dot']],
    'empty target'    => [['shapes/circle' => '']],
    'non-string'      => [['shapes/circle' => ['shapes/dot']]],
]);

it('keeps aliases per set', function (): void {
    $map = new IconAliasMap;
    $map->register('vendor/one', ['a/old' => 'a/new']);

    expect($map->resolve('vendor/one', 'a.old'))->toBe('a/new')
        ->and($map->resolve('vendor/two', 'a/old'))->toBeNull();
});
