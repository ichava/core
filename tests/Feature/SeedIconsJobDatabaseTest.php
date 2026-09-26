<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Jobs\SeedIconsJob;

/*
 * What a seed leaves in the database, rather than what it prints.
 *
 * On PostgreSQL the job suspends the per-row search trigger around its bulk
 * write and rebuilds search_text itself. Both halves are checked: the trigger
 * is live again afterwards, and every icon has search text. Suspension used to
 * happen INSIDE the write transaction, where a failed ALTER TABLE aborts the
 * transaction and takes the whole chunk with it.
 */

function seedTestIconsSynchronously(): void
{
    test()->artisan('ichava::ichava-core.database', ['action' => 'seed', '--sync' => true])->run();
}

it('seeds icons and attaches their categories', function (): void {
    seedTestIconsSynchronously();

    $this->assertGreaterThan(0, Icon::count());
    $this->assertGreaterThan(0, DB::table('ichava_icon_termables')->count());
});

it('does not duplicate category attachments when seeded twice', function (): void {
    seedTestIconsSynchronously();
    $first = DB::table('ichava_icon_termables')->count();

    test()->artisan('ichava::ichava-core.database', ['action' => 'seed:icons', '--sync' => true, '--update' => true])->run();

    $this->assertSame($first, DB::table('ichava_icon_termables')->count());
});

it('leaves the search trigger enabled and search text populated on PostgreSQL', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Only PostgreSQL has the search trigger.');
    }

    seedTestIconsSynchronously();

    $state = DB::selectOne(
        "SELECT tgenabled FROM pg_trigger WHERE tgname = ? AND tgrelid = 'ichava_icons'::regclass",
        [SeedIconsJob::SEARCH_TRIGGER],
    );

    $this->assertNotNull($state, 'The search trigger exists');
    $this->assertSame('O', $state->tgenabled, 'The search trigger is enabled again after seeding');
    $this->assertSame(0, Icon::query()->whereNull('search_text')->count(), 'Every seeded icon has search text');
});
