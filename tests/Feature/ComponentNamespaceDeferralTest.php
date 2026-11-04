<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * The bare `ichava` Blade component namespace is deferred, deliberately.
 *
 * This is the enforceable half of the DEFERRED note in
 * `IchavaServiceProvider::bootingPackage()`. The note explains why the bare
 * slug stays; this pins that it is still there, so the decision cannot be
 * reversed by accident inside unrelated work -- a comment is deleted with the
 * code it sits next to, and prose does not fail a build.
 *
 * **If you are reading this because the test failed, that is the design.** You
 * are taking Decision B. It is a breaking change to the ecosystem's documented
 * public API and it needs its own release, a migration note, and the ~112
 * `<x-ichava::…>` call sites across the estate updated. Update this test and
 * the note together, or put the registration back.
 *
 * Asserted against the live compiler rather than the provider source: grepping
 * a registration proves how it was written, not what Blade ended up holding.
 */
it('still registers the deferred bare component namespace', function (): void {
    expect(Blade::getClassComponentNamespaces())
        ->toHaveKey('ichava', 'Simtabi\\Laranail\\Ichava\\View\\Components');
});

it('still registers the deferred bare alias for the headline component', function (): void {
    // `<x-ichava::icon>` is the first thing the README teaches and the single
    // most-referenced name in the ecosystem. Core covered it only by rendering
    // the tag (IconComponentAttributesTest); nothing asserted the alias key,
    // so a rename would have surfaced as a render failure elsewhere rather
    // than as a decision being taken here.
    expect(Blade::getClassComponentAliases())->toHaveKey('ichava::icon');
});

/*
 * The vendor-scoped prefix, `ichava-core`, sits beside the bare one (V-B11).
 *
 * Additive only: both prefixes resolve the same classes, and nothing about the
 * bare prefix changes. Read from Blade's live maps, then proven by rendering
 * the same icon through both tags.
 */
it('registers the vendor-scoped component namespace over the same classes', function (): void {
    $namespaces = Blade::getClassComponentNamespaces();

    expect($namespaces)->toHaveKey('ichava-core')
        ->and($namespaces['ichava-core'])->toBe($namespaces['ichava']);
});

it('registers the vendor-scoped alias for the headline component', function (): void {
    $aliases = Blade::getClassComponentAliases();

    expect($aliases)->toHaveKey('ichava-core::icon')
        ->and($aliases['ichava-core::icon'])->toBe($aliases['ichava::icon']);
});

it('adds no bare alias beyond the deferred one', function (): void {
    // The scoped prefix must not bring a new generic key with it: every alias
    // core registers is either the deferred `ichava::icon` or vendor-scoped.
    $core = array_filter(
        Blade::getClassComponentAliases(),
        static fn (string $class): bool => str_starts_with($class, 'Simtabi\\Laranail\\Ichava\\View\\Components\\'),
    );

    expect(array_keys($core))->not->toBeEmpty();

    foreach (array_keys($core) as $alias) {
        expect($alias === 'ichava::icon' || str_starts_with($alias, 'ichava-core::'))->toBeTrue("unscoped alias {$alias}");
    }
});

it('renders the same bytes through the bare and the scoped tag', function (): void {
    app(Simtabi\Laranail\Ichava\Services\IconRegistry::class)->fromDirectory(dirname(__DIR__) . '/fixtures/KindPack');

    $bare = Blade::render('<x-ichava::icon name="ichava-fixtures/kind-pack::glyphs/star" class="w-4" />');
    $scoped = Blade::render('<x-ichava-core::icon name="ichava-fixtures/kind-pack::glyphs/star" class="w-4" />');

    expect($bare)->toContain('<svg')
        ->and($scoped)->toBe($bare);
});
