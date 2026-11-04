<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Log\Events\MessageLogged;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Facades\IchavaFacade;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;

/*
 * A set moving between packs (D9, contract §8).
 *
 * The new pack declares `package.replaces`; the old pack marks the moved
 * categories `deprecated`. With the replacement installed, the old id resolves
 * in the new pack and the old categories are not seeded. Without it, nothing
 * changes at all.
 */
const OLD_PACK = 'ichava-fixtures/emoji-old';
const NEW_PACK = 'ichava-fixtures/openmoji-new';
const OLD_ID = OLD_PACK . '::openmoji-color/1f600';

function packAliasFixture(string $name): string
{
    return dirname(__DIR__) . '/fixtures/' . $name;
}

/** @return array{id: string, status: string, count: int, detail: list<mixed>} */
function packAliasDoctorCheck(string $id): array
{
    Artisan::call('ichava::ichava-core.info', ['type' => 'doctor', '--format' => 'json']);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return collect($report['checks'])->firstWhere('id', $id);
}

beforeEach(function (): void {
    app(IconRegistry::class)->fromDirectory(packAliasFixture('AliasOldPack'));
});

it('resolves the old id in the old pack when the replacement is not installed', function (): void {
    $registry = app(IconRegistry::class);

    expect($registry->canonicalId(OLD_ID))->toBe(OLD_ID)
        ->and($registry->render(OLD_ID))->toContain('#fcea2b')
        ->and($registry->has(OLD_ID))->toBeTrue();

    app(IchavaSeeder::class)->seedPackage(OLD_PACK, packAliasFixture('AliasOldPack'), useQueue: false);

    expect(Icon::where('package', OLD_PACK)->pluck('name')->sort()->values()->all())->toBe(['1f600', 'grin']);
});

it('rewrites the old id to the replacement wherever an id is parsed', function (): void {
    $registry = app(IconRegistry::class);
    $registry->fromDirectory(packAliasFixture('AliasNewPack'));

    expect($registry->canonicalId(OLD_ID))->toBe(NEW_PACK . '::openmoji-color/1f600')
        ->and(IchavaFacade::canonicalId(OLD_ID))->toBe(NEW_PACK . '::openmoji-color/1f600')
        // A path outside the moved set stays where it was.
        ->and($registry->canonicalId(OLD_PACK . '::smileys/grin'))->toBe(OLD_PACK . '::smileys/grin')
        ->and($registry->render(OLD_ID))->toContain('#00ff00')
        ->and($registry->has(OLD_ID))->toBeTrue()
        ->and($registry->kindOf(OLD_ID))->toBe(IconKind::Icon)
        ->and(IchavaFacade::kindResolution(OLD_ID)->source->value)->toBe('pack')
        ->and((string) ichava(OLD_ID))->toContain('#00ff00');
});

it('logs each rewritten id once per process', function (): void {
    $registry = app(IconRegistry::class);
    $registry->fromDirectory(packAliasFixture('AliasNewPack'));
    config()->set('ichava.ichava-core.logging.enabled', true);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        if (str_contains($event->message, OLD_ID)) {
            $logged[] = $event->message;
        }
    });

    $registry->canonicalId(OLD_ID);
    $registry->canonicalId(OLD_ID);
    $registry->render(OLD_ID);

    expect($logged)->toHaveCount(1)
        ->and($logged[0])->toContain('[DEPRECATED]')
        ->and($logged[0])->toContain(NEW_PACK);
});

it('does not seed the old pack\'s moved categories once the replacement is installed', function (): void {
    app(IconRegistry::class)->fromDirectory(packAliasFixture('AliasNewPack'));

    // Rows from before the replacement arrived are re-filed away.
    app(IchavaSeeder::class)->seedPackage(OLD_PACK, packAliasFixture('AliasOldPack'), useQueue: false);
    Icon::create(['package' => OLD_PACK, 'name' => '1f600', 'path' => 'files/openmoji-color/1f600.svg', 'file_hash' => 'stale']);

    app(IchavaSeeder::class)->seedPackage(OLD_PACK, packAliasFixture('AliasOldPack'), useQueue: false);
    app(IchavaSeeder::class)->seedPackage(NEW_PACK, packAliasFixture('AliasNewPack'), useQueue: false);

    expect(Icon::where('package', OLD_PACK)->pluck('name')->all())->toBe(['grin'])
        ->and(Icon::where('package', NEW_PACK)->pluck('name')->all())->toBe(['1f600']);
});

it('warns when a deprecated set is installed and its replacement is not', function (): void {
    $missing = packAliasDoctorCheck('alias.replacement_missing');

    expect($missing['status'])->toBe('warn')
        ->and($missing['count'])->toBe(1)
        ->and($missing['detail'][0])->toMatchArray(['pack' => OLD_PACK, 'category' => 'openmoji-color', 'replacement' => NEW_PACK]);

    app(IconRegistry::class)->fromDirectory(packAliasFixture('AliasNewPack'));

    expect(packAliasDoctorCheck('alias.replacement_missing')['count'])->toBe(0);
});

it('finds a planted use of a deprecated id, and none in a clean tree', function (): void {
    $this->app->useConfigPath(packAliasFixture('AliasScanClean'));

    $this->app->useAppPath(packAliasFixture('AliasScan/app'));
    $planted = packAliasDoctorCheck('alias.deprecated_ids');

    expect($planted['status'])->toBe('warn')
        ->and($planted['count'])->toBe(1)
        ->and($planted['detail'][0]['location'])->toEndWith('AliasScan/app/IconUses.php:7')
        ->and($planted['detail'][0]['replacement_installed'])->toBeFalse();

    $this->app->useAppPath(packAliasFixture('AliasScanClean/app'));
    $clean = packAliasDoctorCheck('alias.deprecated_ids');

    expect($clean['status'])->toBe('ok')
        ->and($clean['count'])->toBe(0);
});
