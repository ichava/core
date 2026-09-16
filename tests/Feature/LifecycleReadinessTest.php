<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Ichava\Services\IchavaLifecycleManager;

/**
 * Readiness must be decided against the schema that exists.
 *
 * `hasMigrations()` required `category` and `svg_content` on `ichava_icons`.
 * Neither has ever been a column -- a category is a row in `ichava_icon_terms`
 * reached through the polymorphic pivot, and `svg_content` is an accessor that
 * reads the file from disk. So it returned false on every install ever made:
 * `info status` reported UNINITIALIZED against a fully migrated, seeded, working
 * database, and both auto-seed listeners, which gate on it, never fired.
 *
 * The suite missed it because nothing asserted the check against the migration.
 * It surfaced in a real application, where the tables held 138,480 icons and the
 * status command still said NOT READY.
 */
it('reports a migrated database as migrated', function (): void {
    expect(Schema::hasTable('ichava_icons'))->toBeTrue();

    expect(app(IchavaLifecycleManager::class)->hasMigrations())->toBeTrue();
});

it('requires only columns the migration creates', function (): void {
    // Pins the two halves against each other: whatever the check asks for must
    // exist on the table the migration builds.
    $required = (function (): array {
        $m = new ReflectionMethod(IchavaLifecycleManager::class, 'hasMigrations');
        $src = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
        preg_match('/\$requiredColumns\s*=\s*\[([^\]]*)\]/', $src, $mm);

        return array_map(
            static fn (string $c): string => trim($c, " '\"\n\t"),
            array_filter(explode(',', $mm[1] ?? '')),
        );
    })();

    expect($required)->not->toBeEmpty();

    foreach ($required as $column) {
        expect(Schema::hasColumn('ichava_icons', $column))
            ->toBeTrue("hasMigrations() requires '{$column}', which ichava_icons does not have");
    }
});
