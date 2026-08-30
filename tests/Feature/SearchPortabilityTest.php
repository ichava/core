<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Services\IconBrowserService;

/**
 * B1 and B2 — search must respect filters, and must run on the configured driver.
 *
 * B2: `scopeFuzzySearch` is documented as the "fallback for non-PostgreSQL" and is what
 * `scopeSearch` delegates to on every other driver, yet its body called
 * `jsonb_array_elements_text()`, which only PostgreSQL has. The `keywords` and `tags`
 * scopes default to enabled, so on SQLite or MySQL any search threw. These tests run on
 * SQLite, which is the point: the bug is invisible on the driver the author used.
 *
 * B1: filters lived in an `else` branch behind `if (search)`, under the comment "Apply
 * filters only when not searching". Selecting a package and then typing a query silently
 * returned results from every package -- the filter was not overridden by a better match,
 * it was discarded.
 */
beforeEach(function () {
    Icon::query()->delete();

    $this->makeIcon = function (string $package, string $name, array $keywords = [], array $tags = []) {
        return Icon::create([
            'package'    => $package,
            'name'       => $name,
            'path'       => "{$package}::misc/{$name}",
            'file_hash'  => md5($package . $name),
            'keywords'   => $keywords,
            'tags'       => $tags,
            'attributes' => [],
            'metadata'   => [],
        ]);
    };
});

it('runs a search on the configured driver and returns the match', function () {
    ($this->makeIcon)('ichava/tabler-icons', 'arrow-left', ['direction'], ['nav']);

    // Executes the query and checks the result rather than wrapping it in a
    // does-not-throw. The first version of this test asserted only that no exception
    // escaped and passed even against the broken code, while its three neighbours failed
    // on the same SQL -- a test that cannot fail for the reason it exists is worse than
    // no test, because it reads as coverage.
    $results = Icon::fuzzySearch('arrow')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('arrow-left');
});

it('matches on keywords and tags on a non-PostgreSQL driver', function () {
    ($this->makeIcon)('ichava/tabler-icons', 'chevron', ['navigation'], ['ui']);

    // Not just "does not throw" -- the scopes must actually search, or the portable path
    // could satisfy the previous test by quietly matching nothing.
    expect(Icon::fuzzySearch('navigation')->count())->toBe(1);
    expect(Icon::fuzzySearch('ui')->count())->toBe(1);
    expect(Icon::fuzzySearch('nothing-matches-this')->count())->toBe(0);
});

it('keeps the package filter applied while searching', function () {
    ($this->makeIcon)('ichava/tabler-icons', 'arrow-left');
    ($this->makeIcon)('ichava/metronic-icons', 'arrow-right');

    $service = app(IconBrowserService::class);

    // Without a search term the filter works, which is what made this easy to miss.
    $filteredOnly = $service->getIcons(['packages' => ['ichava/tabler-icons']]);
    expect($filteredOnly->total())->toBe(1);

    // With one, it must still apply. Previously this returned both icons.
    $searched = $service->getIcons([
        'packages' => ['ichava/tabler-icons'],
        'search'   => 'arrow',
    ]);
    expect($searched->total())->toBe(1);
    expect($searched->first()->package)->toBe('ichava/tabler-icons');
});

it('narrows rather than widens when a search is combined with a filter', function () {
    ($this->makeIcon)('ichava/tabler-icons', 'arrow-left');
    ($this->makeIcon)('ichava/tabler-icons', 'circle');
    ($this->makeIcon)('ichava/metronic-icons', 'arrow-right');

    $service = app(IconBrowserService::class);

    // Three icons; one matches both the package filter and the term.
    expect($service->getIcons(['packages' => ['ichava/tabler-icons'], 'search' => 'arrow'])->total())
        ->toBe(1);
});
