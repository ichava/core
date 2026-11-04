<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Models\IconTerm;

/*
 * `getTotalIconCount()` filtered termables on the Icon class name, but the
 * rows carry the morph alias the provider registers, so it returned 0 on every
 * driver whatever was attached.
 */
it('counts the icons attached to a term and its descendants', function (): void {
    $package = 'ichava/test-icons';

    $parent = IconTerm::query()->create(['type' => IconTerm::TYPE_CATEGORY, 'package' => $package, 'name' => 'Weather', 'slug' => 'weather']);
    $child = IconTerm::query()->create(['type' => IconTerm::TYPE_CATEGORY, 'package' => $package, 'name' => 'Clouds', 'slug' => 'clouds', 'parent_id' => $parent->id]);

    foreach (['sun' => $parent, 'cloud' => $child, 'fog' => $child] as $name => $term) {
        $icon = Icon::query()->create([
            'package'   => $package,
            'name'      => $name,
            'path'      => "misc/{$name}.svg",
            'file_hash' => md5($name),
        ]);

        $icon->terms()->attach($term->id);
    }

    expect($child->getTotalIconCount())->toBe(2)
        ->and($parent->getTotalIconCount())->toBe(3);
});
