<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Support\FtsLanguageHelper;

/**
 * The JSON columns are `json`, so the SQL must use the `json_*` function family.
 *
 * `$table->json()` emits `json` on PostgreSQL, not `jsonb` -- and PostgreSQL registers no
 * implicit cast between the two, so `jsonb_array_elements_text(keywords)` resolves to no
 * function at all and the query errors. The whole PostgreSQL search path was written that
 * way while the migration's own trigger did it correctly, and nothing noticed because the
 * suite only ever ran SQLite, where none of this SQL is reached.
 *
 * This runs on every driver because it inspects the generated SQL rather than executing
 * it -- the mismatch is a property of the string, and catching it should not require a
 * PostgreSQL server to be up.
 */
it('never applies a jsonb function to a json column', function (string $sql): void {
    expect($sql)->not->toContain('jsonb_array_elements_text');

    preg_match_all('/json_array_elements_text\(([^)]*)\)/', $sql, $m);

    foreach ($m[1] as $argument) {
        expect($argument)->toContain('::json');
    }
})->with([
    'comprehensive'   => fn () => FtsLanguageHelper::buildComprehensiveSearchQuery('Simtabi\Laranail\Ichava\Models\Icon'),
    'single language' => fn () => FtsLanguageHelper::buildSingleLanguageQuery('arrow', 'english'),
]);

it('expands keywords and tags when those scopes are enabled', function (): void {
    // Guards the test above against passing vacuously: with the scopes off the query
    // would contain no element expansion at all and every assertion would hold trivially.
    $sql = FtsLanguageHelper::buildComprehensiveSearchQuery('Simtabi\Laranail\Ichava\Models\Icon');

    expect(FtsLanguageHelper::isScopeEnabled('keywords'))->toBeTrue()
        ->and(FtsLanguageHelper::isScopeEnabled('tags'))->toBeTrue()
        ->and(substr_count($sql, 'json_array_elements_text'))->toBeGreaterThanOrEqual(2);
});

it('qualifies every column with a table that exists in the query', function () {
    // The clause referenced a bare `i` alias that nothing provided:
    // `Icon::query()` emits `from "ichava_icons"`, so this was invalid SQL on
    // the one driver it was written for. It survived because scopeSearch() had
    // no caller in src/ -- the discovery service hand-wrote its own query --
    // so the branch was never reached until that duplication was removed.
    //
    // Asserted on the generated SQL, which needs no PostgreSQL to check.
    $sql = FtsLanguageHelper::buildComprehensiveSearchQuery(
        'Simtabi\Laranail\Ichava\Models\Icon',
        'ichava_icons',
    );

    expect($sql)->toContain('ichava_icons.name')
        ->and($sql)->not->toMatch('/(?<![a-z_.])i\.(name|id|package|keywords|tags|metadata)\b/');
});

it('honours a non-default table name', function () {
    $sql = FtsLanguageHelper::buildComprehensiveSearchQuery(
        'Simtabi\Laranail\Ichava\Models\Icon',
        'prefixed_icons',
    );

    expect($sql)->toContain('prefixed_icons.name')
        ->and($sql)->not->toContain('ichava_icons.name');
});
