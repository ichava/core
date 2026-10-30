<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;

/*
|--------------------------------------------------------------------------
| Category and variant names are searchable on PostgreSQL
|--------------------------------------------------------------------------
|
| Core registers `Relation::morphMap(['icon' => Icon::class])`, so every
| termables row the seeder writes carries `termable_type = 'icon'`. The
| PostgreSQL search clause and the search-text functions compared that column
| with the fully-qualified class name instead, so no term ever matched and a
| search for a category or variant name found nothing.
|
| The PostgreSQL tests run in the CI database matrix (`DB pgsql`); on any other
| driver they skip. The SQL-shape test runs everywhere, because building the
| query on a PostgreSQL connection needs no server.
|
*/

function iconWithCategoryAndVariant(): Icon
{
    $package = 'ichava/test-icons';

    $icon = Icon::query()->create([
        'package'   => $package,
        'name'      => 'cloud',
        'path'      => 'misc/cloud.svg',
        'file_hash' => md5('cloud'),
        'keywords'  => [],
        'tags'      => [],
    ]);

    $category = IconTerm::query()->create([
        'type'    => IconTerm::TYPE_CATEGORY,
        'package' => $package,
        'name'    => 'Zephyrweather',
        'slug'    => 'zephyrweather',
    ]);

    $variant = IconTerm::query()->create([
        'type'    => IconTerm::TYPE_VARIANT,
        'package' => $package,
        'name'    => 'Quasaroutline',
        'slug'    => 'quasaroutline',
    ]);

    $icon->terms()->attach([$category->id, $variant->id]);

    return $icon->refresh();
}

function skipUnlessPostgres(): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Only PostgreSQL searches term names through full-text SQL.');
    }
}

it('compares termable_type with the morph alias in the PostgreSQL search clause', function (): void {
    config()->set('database.connections.pgsql-shape', [
        'driver'   => 'pgsql',
        'host'     => '127.0.0.1',
        'database' => 'unused',
        'username' => 'unused',
        'password' => '',
    ]);

    $sql = Icon::on('pgsql-shape')->search('zephyrweather')->toSql();
    $alias = (new Icon)->getMorphClass();

    expect($alias)->toBe(Icon::MORPH_ALIAS)
        ->and($sql)->toContain("termable_type = '{$alias}'")
        ->and($sql)->not->toContain('Models\\');
});

it('finds an icon by the name of its category', function (): void {
    skipUnlessPostgres();

    $icon = iconWithCategoryAndVariant();

    expect(Icon::query()->search('zephyrweather')->pluck('id')->all())->toBe([$icon->id]);
});

it('finds an icon by the name of its variant', function (): void {
    skipUnlessPostgres();

    $icon = iconWithCategoryAndVariant();

    expect(Icon::query()->search('quasaroutline')->pluck('id')->all())->toBe([$icon->id]);
});

it('writes attached term names into search_text', function (): void {
    skipUnlessPostgres();

    $icon = iconWithCategoryAndVariant();

    expect((string) $icon->getAttribute('search_text_plain'))
        ->toContain('Zephyrweather')
        ->toContain('Quasaroutline');
});

it('installs search-text functions that compare the morph alias', function (string $function): void {
    skipUnlessPostgres();

    $definition = DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS def', [$function])->def;

    expect($definition)->toContain("termable_type = 'icon'")
        ->and($definition)->not->toContain('Models');
})->with([
    'refresh_ichava_icon_search_text(bigint)',
    'refresh_ichava_icons_search_text_for_term(bigint)',
    'trg_refresh_ichava_icon_search_text_from_termable()',
]);

it('repairs the search-text functions and stored search text of an installed database', function (): void {
    skipUnlessPostgres();

    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_30_000001_match_icon_morph_alias_in_search_text.php';

    // The state an install migrated before the fix is in.
    $migration->down();
    $icon = iconWithCategoryAndVariant();

    expect((string) $icon->getAttribute('search_text_plain'))->not->toContain('Zephyrweather');

    $migration->up();

    expect((string) $icon->refresh()->getAttribute('search_text_plain'))
        ->toContain('Zephyrweather')
        ->toContain('Quasaroutline');
});
