<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\IconTerm;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Seeder\IconTermsSeeder;

/*
 * C-04. The registry already carries each pack's `variant_metadata` so no
 * second reader of config.json is needed. The terms seeder was that second
 * reader; it now seeds variants from the registry, so a variant the registry
 * holds is seeded whether or not a config.json still says so.
 */

it('seeds variant terms from the registry, not from a second read of config.json', function (): void {
    $dir = sys_get_temp_dir() . '/ichava-terms-' . bin2hex(random_bytes(4));
    File::copyDirectory(dirname(__DIR__) . '/fixtures/VariantPack', $dir);

    $registry = app(IconRegistry::class);
    $registry->fromDirectory($dir);
    File::delete($dir . '/config.json');

    $package = 'ichava-fixtures/variant-pack';
    app(IconTermsSeeder::class)->seedSinglePackage($package, $registry->all()[$package]);

    expect(IconTerm::query()->where('package', $package)->where('type', IconTerm::TYPE_VARIANT)->orderBy('slug')->pluck('slug')->all())
        ->toBe(['filled', 'outline']);

    File::deleteDirectory($dir);
});
