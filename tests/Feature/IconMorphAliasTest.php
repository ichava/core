<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Illuminate\Database\Eloquent\Relations\Relation;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Tests\Support\ClaimsIchavaMorphAliasProvider;

/*
 * C-02. The morph map is a flat, host-owned registry: a second claim on a key
 * silently replaces the first. Core's alias for Icon carries the vendor, a
 * migration moves stored rows to it and back, and boot refuses a map where the
 * alias resolves to another model.
 */

afterEach(function (): void {
    Relation::morphMap([Icon::MORPH_ALIAS => Icon::class]);
});

it('registers a vendor-scoped morph alias for Icon', function (): void {
    expect(Icon::MORPH_ALIAS)->toBe('ichava-icon')
        ->and((new Icon)->getMorphClass())->toBe('ichava-icon')
        ->and(Relation::getMorphedModel('ichava-icon'))->toBe(Icon::class)
        ->and(Relation::getMorphedModel('icon'))->toBeNull();
});

it('moves stored termable rows to the scoped alias and back', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_05_000001_namespace_icon_morph_alias.php';

    $terms = collect(['first', 'second'])->map(fn (string $slug): int => IconTerm::query()->create([
        'type' => IconTerm::TYPE_CATEGORY, 'package' => 'ichava/test-icons', 'name' => $slug, 'slug' => $slug,
    ])->id)->all();

    DB::table('ichava_icon_termables')->insert([
        ['term_id' => $terms[0], 'termable_id' => 10, 'termable_type' => 'icon'],
        ['term_id' => $terms[1], 'termable_id' => 11, 'termable_type' => 'App\\Models\\Post'],
    ]);

    $migration->up();
    expect(DB::table('ichava_icon_termables')->orderBy('term_id')->pluck('termable_type')->all())
        ->toBe(['ichava-icon', 'App\\Models\\Post']);

    $migration->down();
    expect(DB::table('ichava_icon_termables')->orderBy('term_id')->pluck('termable_type')->all())
        ->toBe(['icon', 'App\\Models\\Post']);
});

it('refuses to boot when another provider maps the alias to a different model', function (): void {
    $this->extraProviders = [ClaimsIchavaMorphAliasProvider::class];

    expect(fn () => $this->refreshApplication())
        ->toThrow(IchavaException::class, 'ichava-icon');
});
