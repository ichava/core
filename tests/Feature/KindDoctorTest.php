<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Enums\KindSource;
use Simtabi\Laranail\Ichava\Data\KindResolution;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\KindHeuristic;
use Simtabi\Laranail\Ichava\Support\Seeder\IchavaSeeder;

/*
 * Doctor's kind checks (contract §7) and the row 7 heuristic (§3).
 *
 * The broken pack carries one of every mistake doctor reports. The heuristic
 * pack holds a flag -- non-square, multicolour, a large canvas, and small --
 * which must NOT be suggested, and a scene, which must.
 */
const BROKEN_PACK = 'ichava-fixtures/broken-kind-pack';
const HEURISTIC_PACK = 'ichava-fixtures/heuristic-pack';

function doctorFixture(string $name): string
{
    return dirname(__DIR__) . '/fixtures/' . $name;
}

/** @return array{passed: bool, checks: list<array{id: string, status: string, count: int, detail: list<mixed>}>} */
function doctorJson(array $options = []): array
{
    Artisan::call('ichava::ichava-core.info', ['type' => 'doctor', '--format' => 'json', ...$options]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array{id: string, status: string, count: int, detail: list<mixed>} */
function doctorCheck(array $report, string $id): array
{
    $check = collect($report['checks'])->firstWhere('id', $id);

    expect($check)->not->toBeNull("no `{$id}` check in the doctor report");

    return $check;
}

beforeEach(function (): void {
    config()->set('ichava.ichava-core.kinds.overrides', []);
});

it('reports every check with the same shape under --format=json', function (): void {
    $report = doctorJson();

    expect($report)->toHaveKeys(['passed', 'checks'])
        ->and(count($report['checks']))->toBeGreaterThanOrEqual(10);

    foreach ($report['checks'] as $check) {
        expect(array_keys($check))->toBe(['id', 'status', 'count', 'detail'])
            ->and($check['status'])->toBeIn(['ok', 'warn', 'fail']);
    }

    foreach (['kind.invalid_value', 'kind.invalid_glob', 'kind.override_invalid', 'kind.rule_unused', 'kind.item_missing', 'kind.drift', 'kind.non_default', 'alias.deprecated_ids', 'alias.replacement_missing'] as $id) {
        expect(doctorCheck($report, $id)['status'])->toBe('ok', $id);
    }

    // Off by default, because it reads every SVG.
    expect(collect($report['checks'])->firstWhere('id', 'kind.suggestions'))->toBeNull();
});

it('reports every kind mistake a pack and the host config can make', function (): void {
    app(IconRegistry::class)->fromDirectory(doctorFixture('BrokenKindPack'));
    app(IchavaSeeder::class)->seedPackage(BROKEN_PACK, doctorFixture('BrokenKindPack'), useQueue: false);
    config()->set('ichava.ichava-core.kinds.overrides', [
        'no-separator'                => 'icon',
        'x/y::[a]'                    => 'icon',
        BROKEN_PACK . '::nothing/**'  => 'icon',
        BROKEN_PACK . '::glyphs/star' => 'banana',
    ]);

    $report = doctorJson();

    $invalid = doctorCheck($report, 'kind.invalid_value');
    expect($invalid['status'])->toBe('warn')
        ->and($invalid['count'])->toBe(5)
        ->and(array_column($invalid['detail'], 'key'))->toEqualCanonicalizing([
            'package.kind',
            'metadata.data.categories.glyphs.kind',
            'metadata.data.kinds.rules.1.kind',
            'metadata.data.items.glyphs/star.kind',
            'ichava.ichava-core.kinds.overrides.' . BROKEN_PACK . '::glyphs/star',
        ]);

    $glob = doctorCheck($report, 'kind.invalid_glob');
    expect($glob['count'])->toBe(1)
        ->and($glob['detail'][0])->toMatchArray(['pack' => BROKEN_PACK, 'index' => 0, 'pattern' => 'a**']);

    $override = doctorCheck($report, 'kind.override_invalid');
    expect($override['count'])->toBe(2)
        ->and(array_column($override['detail'], 'key'))->toBe(['no-separator', 'x/y::[a]']);

    // `scenes/park-dark` matches only a theme twin, which is not an item.
    $unused = doctorCheck($report, 'kind.rule_unused');
    expect($unused['count'])->toBe(3)
        ->and(array_column($unused['detail'], 'pattern'))->toEqualCanonicalizing(['unused/**', 'scenes/park-dark', BROKEN_PACK . '::nothing/**']);

    $missing = doctorCheck($report, 'kind.item_missing');
    expect($missing['count'])->toBe(1)
        ->and($missing['detail'][0])->toMatchArray(['pack' => BROKEN_PACK, 'item' => 'glyphs/ghost']);

    $nonDefault = doctorCheck($report, 'kind.non_default');
    expect($nonDefault['status'])->toBe('ok')
        ->and($nonDefault['count'])->toBe(1)
        ->and($nonDefault['detail'][0])->toMatchArray(['package' => BROKEN_PACK, 'path' => 'scenes/park', 'kind' => 'illustration', 'source' => 'rule', 'rule' => 'scenes/*']);

    // Warnings never fail doctor.
    expect(doctorCheck($report, 'kind.drift')['status'])->toBe('ok');
});

it('fails the kind checks it should, proven against a clean pack', function (): void {
    app(IconRegistry::class)->fromDirectory(doctorFixture('MixedKindPack'));
    app(IchavaSeeder::class)->seedPackage('ichava-fixtures/mixed-pack', doctorFixture('MixedKindPack'), useQueue: false);

    $report = doctorJson();

    foreach (['kind.invalid_value', 'kind.invalid_glob', 'kind.rule_unused', 'kind.item_missing', 'kind.drift'] as $id) {
        expect(doctorCheck($report, $id)['count'])->toBe(0, $id);
    }

    expect(doctorCheck($report, 'kind.non_default')['count'])->toBe(3);
});

it('suggests the scene and not the flag', function (): void {
    app(IconRegistry::class)->fromDirectory(doctorFixture('HeuristicPack'));

    $suggestions = doctorCheck(doctorJson(['--suggest-kinds' => null]), 'kind.suggestions');

    expect($suggestions['status'])->toBe('ok')
        ->and($suggestions['count'])->toBe(1);

    $record = $suggestions['detail'][0];

    expect($record['package'])->toBe(HEURISTIC_PACK)
        ->and($record['path'])->toBe('scenes/meadow')
        ->and($record['current'])->toBe(['kind' => 'icon', 'source' => 'default'])
        ->and($record['suggested'])->toBe('illustration')
        ->and(array_column($record['signals'], 'id'))->toContain('bytes', 'elements', 'aspect', 'palette', 'canvas')
        ->and($record['accept'])->toBe(['items' => ['scenes/meadow' => ['kind' => 'illustration']]])
        ->and(array_keys($record))->toBe(['package', 'path', 'current', 'suggested', 'signals', 'accept']);
});

it('never second-guesses an explicit kind, and includes the pack row only with =all', function (): void {
    app(IconRegistry::class)->fromDirectory(doctorFixture('HeuristicPack'));
    config()->set('ichava.ichava-core.kinds.overrides', [HEURISTIC_PACK . '::scenes/meadow' => 'icon']);

    expect(doctorCheck(doctorJson(['--suggest-kinds' => 'all']), 'kind.suggestions')['count'])->toBe(0);

    $heuristic = new KindHeuristic;
    $scene = file_get_contents(doctorFixture('HeuristicPack/files/scenes/meadow.svg'));
    $signals = $heuristic->signals($scene);
    $pack = new KindResolution(IconKind::Icon, KindSource::Pack, null);

    expect($heuristic->suggests($signals, $pack, includePack: false))->toBeFalse()
        ->and($heuristic->suggests($signals, $pack, includePack: true))->toBeTrue()
        ->and($heuristic->suggests($signals, new KindResolution(IconKind::Icon, KindSource::Category, 'scenes'), includePack: true))->toBeFalse();
});

it('reads the five signals from an SVG', function (): void {
    $heuristic = new KindHeuristic;

    $flag = $heuristic->signals(file_get_contents(doctorFixture('HeuristicPack/files/flags/ke.svg')));
    $dot = $heuristic->signals('<svg viewBox="0 0 24 24"><path fill="currentColor" stroke="none" d="M0"/><stop stop-color="inherit"/></svg>');

    expect(array_column($flag, 'id'))->toBe(['aspect', 'palette', 'canvas'])
        ->and(collect($flag)->firstWhere('id', 'palette')['value'])->toBe(4)
        ->and($dot)->toBe([]);
});

it('groups a folder of suggestions into one rule', function (): void {
    $heuristic = new KindHeuristic;
    $scene = file_get_contents(doctorFixture('HeuristicPack/files/scenes/meadow.svg'));
    $icon = '<svg viewBox="0 0 24 24"><path d="M0"/></svg>';
    $default = new KindResolution(IconKind::Icon, KindSource::Default, null);

    $items = [];
    foreach (['a', 'b', 'c', 'd'] as $name) {
        $items["scenes/{$name}"] = ['resolution' => $default, 'svg' => $scene];
    }
    $items['scenes/e'] = ['resolution' => $default, 'svg' => $icon];
    $items['lone/x'] = ['resolution' => $default, 'svg' => $scene];

    $records = $heuristic->suggestions('acme/scenes', $items, includePack: false);

    expect(array_column($records, 'path'))->toBe(['lone/x', 'scenes'])
        ->and($records[1]['accept'])->toBe(['kinds' => ['rules' => [['match' => 'scenes/**', 'kind' => 'illustration']]]])
        ->and($records[0]['accept'])->toBe(['items' => ['lone/x' => ['kind' => 'illustration']]]);

    // Three of five is under 80%: per-item records instead.
    $items['scenes/d']['svg'] = $icon;

    expect(array_column($heuristic->suggestions('acme/scenes', $items, includePack: false), 'path'))
        ->toBe(['lone/x', 'scenes/a', 'scenes/b', 'scenes/c']);
});

it('reports the kind facts in artisan about', function (): void {
    config()->set('ichava.ichava-core.kinds.overrides', ['a/b::c' => 'icon', 'a/b::d/**' => 'illustration']);
    config()->set('ichava.ichava-core.kinds.default', 'illustration');

    Artisan::call('about');
    $about = Artisan::output();

    expect($about)->toMatch('/Icons by kind\s*\.*\s*icon 0 · illustration 0/')
        ->and($about)->toMatch('/Default kind\s*\.*\s*illustration/')
        ->and($about)->toMatch('/Kind sources\s*\.*\s*none/')
        ->and($about)->toMatch('/Kind overrides\s*\.*\s*2/');

    config()->set('ichava.ichava-core.database.enabled', false);
    Artisan::call('about');

    expect(Artisan::output())->toMatch('/Icons by kind\s*\.*\s*n\/a \(database disabled\)/');
});
