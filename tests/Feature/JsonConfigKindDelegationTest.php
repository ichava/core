<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Enums\KindSource;
use Simtabi\Laranail\Ichava\Support\KindResolver;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Constants\JsonConfigConstants;

/*
 * A pack's constants class answers `getCategoryKind()` and `getPackageKind()`
 * through KindResolver's own category, pack and default rows, so it cannot
 * disagree with what the seeder, the watcher and the renderer resolve.
 *
 * Each case writes a pack to a temporary directory, registers it, and compares
 * the constants class with the live resolver for an item in every category and
 * one at the pack root. The packs carry no host override, item or rule, so the
 * resolver's answer is decided by exactly the rows the constants class reads.
 */

/**
 * @param array<string, mixed> $package
 * @param array<string, array<string, mixed>> $categories
 *
 * @return class-string<JsonConfigConstants>
 */
function kindConstantsFor(string $dir, array $package, array $categories): string
{
    File::ensureDirectoryExists($dir);
    File::put($dir . '/config.json', (string) json_encode([
        'schema_version' => '1.0',
        'package'        => ['version' => '1.0.0', 'type' => 'single', 'license' => 'MIT', 'title' => 'Fixture', ...$package],
        'metadata'       => ['data' => ['variants' => [], 'categories' => $categories]],
        'config'         => ['icon_prefix' => 'kd' . bin2hex(random_bytes(3)), 'defaults' => ['class' => '', 'attributes' => []]],
    ]));

    foreach ([...array_keys($categories), ''] as $category) {
        $folder = $category === '' ? $dir . '/files' : $dir . '/files/' . $category;
        File::ensureDirectoryExists($folder);
        File::put($folder . '/item.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>');
    }

    $stub = new class($dir) extends JsonConfigConstants
    {
        public static string $dir = '';

        public function __construct(string $dir)
        {
            self::$dir = $dir;
        }

        protected static function getConfigPath(): string
        {
            return self::$dir;
        }
    };

    // The per-class config cache is keyed by class, and every case shares this
    // one anonymous class, so the previous case's config must go first.
    $stub::clearCache();

    return $stub::class;
}

dataset('kind packs', [
    'icon pack, one illustration category' => [
        ['kind' => 'icon'],
        ['glyphs' => ['name' => 'Glyphs'], 'scenes' => ['name' => 'Scenes', 'kind' => 'illustration']],
    ],
    'illustration pack, one icon category' => [
        ['kind' => 'illustration'],
        ['spot' => ['name' => 'Spot'], 'ui' => ['name' => 'UI', 'kind' => 'icon']],
    ],
    'pack that declares no kind' => [
        [],
        ['plain' => ['name' => 'Plain'], 'scenes' => ['name' => 'Scenes', 'kind' => 'illustration']],
    ],
    'category with an unknown kind' => [
        ['kind' => 'illustration'],
        ['odd' => ['name' => 'Odd', 'kind' => 'sticker']],
    ],
]);

it('agrees with KindResolver for every category and the pack root', function (array $package, array $categories): void {
    $name = 'ichava-fixtures/kd-' . bin2hex(random_bytes(4));
    $dir = sys_get_temp_dir() . '/ichava-kind-delegation-' . bin2hex(random_bytes(4));

    try {
        $constants = kindConstantsFor($dir, ['name' => $name, ...$package], $categories);
        app(IconRegistry::class)->fromDirectory($dir);
        $resolver = new KindResolver(app(IconRegistry::class));

        foreach (array_keys($categories) as $category) {
            expect($constants::getCategoryKind($category))
                ->toBe($resolver->resolve($name, $category . '/item')->kind, "category {$category}");
        }

        expect($constants::getPackageKind())->toBe($resolver->resolve($name, 'item')->kind)
            // A category the pack does not declare inherits the pack, as the
            // resolver does for an item filed under it.
            ->and($constants::getCategoryKind('undeclared'))->toBe($resolver->resolve($name, 'undeclared/item')->kind);
    } finally {
        File::deleteDirectory($dir);
    }
})->with('kind packs');

it('exposes the category, pack and default rows as one resolver entry point', function (): void {
    $declared = ['declared_kind' => 'illustration', 'category_kinds' => ['ui' => 'icon']];

    expect(KindResolver::declaredResolution($declared, 'ui'))->toEqual(new Simtabi\Laranail\Ichava\Data\KindResolution(IconKind::Icon, KindSource::Category, 'ui'))
        ->and(KindResolver::declaredResolution($declared, 'spot')->source)->toBe(KindSource::Pack)
        ->and(KindResolver::declaredResolution($declared, null)->kind)->toBe(IconKind::Illustration)
        ->and(KindResolver::declaredResolution([], 'spot'))->toEqual(new Simtabi\Laranail\Ichava\Data\KindResolution(IconKind::Icon, KindSource::Default));
});
