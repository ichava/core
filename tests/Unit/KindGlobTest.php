<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\KindGlob;

/*
 * The glob syntax kind rules and host overrides use (contract §1.2).
 *
 * The vector table is the contract's, verbatim. icon-sets-sync's Go copy
 * (`internal/core/kinds/glob.go`) carries the same table, so a pattern means
 * the same thing to the tool that writes a pack and the package that reads it.
 */

it('matches the contract vector table', function (string $pattern, string $path, bool $expected): void {
    expect(KindGlob::isValid($pattern))->toBeTrue()
        ->and(KindGlob::matches($pattern, $path))->toBe($expected);
})->with([
    ['scenes/**', 'scenes/office', true],
    ['scenes/**', 'scenes/a/b', true],
    ['scenes/*', 'scenes/a/b', false],
    ['**/hero-*', 'hero-x', true],
    ['**/hero-*', 'a/b/hero-x', true],
    ['scenes/**/x', 'scenes/x', true],
    ['scenes/offic?', 'scenes/office', true],
    ['Scenes/**', 'scenes/a', false],
    ['spot/tiny-*', 'spot/tiny-cat', true],
]);

it('matches whole strings only, never across a slash for * and ?', function (): void {
    expect(KindGlob::matches('spot/tiny', 'spot/tiny-cat'))->toBeFalse()
        ->and(KindGlob::matches('tiny-*', 'spot/tiny-cat'))->toBeFalse()
        ->and(KindGlob::matches('spot?tiny', 'spot/tiny'))->toBeFalse()
        ->and(KindGlob::matches('a/**', 'a'))->toBeTrue()
        ->and(KindGlob::matches('a/**', 'ab'))->toBeFalse()
        ->and(KindGlob::matches('a.b', 'axb'))->toBeFalse()
        ->and(KindGlob::matches('**', 'any/depth/at/all'))->toBeTrue();
});

it('refuses every reserved or malformed pattern', function (string $pattern): void {
    expect(KindGlob::isValid($pattern))->toBeFalse()
        ->and(KindGlob::matches($pattern, 'a/b'))->toBeFalse();
})->with([
    'a** is not a whole segment' => ['a**'],
    'bracket is reserved'        => ['[ab]/x'],
    'brace is reserved'          => ['{a,b}/x'],
    'backslash is reserved'      => ['a\\b'],
    'closing bracket'            => ['a]'],
    'empty'                      => [''],
    'leading slash'              => ['/a'],
    'dot-dot segment'            => ['a/../b'],
    'empty segment'              => ['a//b'],
    'trailing **x'               => ['a/**x'],
]);
