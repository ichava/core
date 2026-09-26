<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Jobs\SeedIconsJob;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconSetBuilder;

/*
|--------------------------------------------------------------------------
| An icon's path is unique within its package, not across the table
|--------------------------------------------------------------------------
|
| `path` is stored relative to each pack's base directory, so every pack
| laid out as `files/<category>/<name>.svg` produces the same strings. The
| table carried a UNIQUE index on `path` alone and `SeedIconsJob` upserted
| on it, with `package` in the update list -- so seeding a second pack with
| a shared relative path did not insert a row, it rewrote the first pack's
| row to belong to the second. One icon disappeared from the first pack
| with nothing reported.
|
*/

function seedPack(string $package, string $base): void
{
    $files = collect(File::allFiles($base))
        ->map(fn ($file) => $file->getPathname())
        ->values()
        ->all();

    (new SeedIconsJob($package, $files, 1, 1))->handle(app(IchavaLogger::class));
}

beforeEach(function () {
    $this->packs = [];

    foreach (['alpha', 'beta'] as $slug) {
        $package = "test/{$slug}-icons";
        $base = sys_get_temp_dir() . "/ichava-unique-{$slug}-" . uniqid();

        File::makeDirectory($base . '/files/general', 0755, true);
        File::put(
            $base . '/files/general/home.svg',
            "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><title>{$slug}</title></svg>",
        );

        app(IconRegistry::class)->registerIconSet(
            $package,
            IconSetBuilder::make($package)->setBasePath($base),
            ['package_name' => $package, 'icon_set_name' => $package, 'base_path' => $base],
        );

        $this->packs[$package] = $base;
    }
});

afterEach(function () {
    foreach ($this->packs as $base) {
        File::deleteDirectory($base);
    }
});

it('keeps a row per package when two packs share a relative path', function () {
    foreach ($this->packs as $package => $base) {
        seedPack($package, $base);
    }

    $rows = Icon::query()->where('path', 'files/general/home.svg')->orderBy('package')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('package')->all())->toBe(['test/alpha-icons', 'test/beta-icons']);

    // Each row must still describe its own file, not the last one seeded.
    foreach ($rows as $row) {
        expect($row->file_hash)->toBe(md5_file($this->packs[$row->package] . '/files/general/home.svg'));
    }
});

it('updates the pack being re-seeded and leaves the other alone', function () {
    foreach ($this->packs as $package => $base) {
        seedPack($package, $base);
    }

    $alphaBase = $this->packs['test/alpha-icons'];
    File::put($alphaBase . '/files/general/home.svg', '<svg xmlns="http://www.w3.org/2000/svg"><title>alpha v2</title></svg>');
    seedPack('test/alpha-icons', $alphaBase);

    $alpha = Icon::query()->where('package', 'test/alpha-icons')->sole();
    $beta = Icon::query()->where('package', 'test/beta-icons')->sole();

    expect($alpha->file_hash)->toBe(md5_file($alphaBase . '/files/general/home.svg'))
        ->and($beta->file_hash)->toBe(md5_file($this->packs['test/beta-icons'] . '/files/general/home.svg'))
        ->and(Icon::query()->count())->toBe(2);
});
