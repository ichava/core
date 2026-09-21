<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Constants\JsonConfigConstants;
use Simtabi\Laranail\Ichava\Events\IconPackUpdateAvailable;
use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;

/**
 * The checker is the load-bearing brain of the upstream-tracking
 * feature; these tests pin the parsers for each supported source type
 * (github / github-tag / npm / packagist / url) plus the stale-detection
 * logic and the event-dispatch contract.
 *
 * Pest.php auto-applies the project TestCase to everything under
 * tests/, so we don't need an explicit uses() here.
 */
beforeEach(function () {
    Cache::flush();
    Event::fake([IconPackUpdateAvailable::class]);
});

it('parses a github releases response and detects up-to-date packs', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v17.0.0', 'html_url' => 'https://github.com/x/y'], 200),
    ]);

    $checker = build_checker_for('vendor/pack-a', GithubUpToDateConstants::class);

    $result = $checker->checkOne('vendor/pack-a');

    expect($result['status'])->toBe('up-to-date');
    expect($result['latest'])->toBe('17.0.0');
    expect($result['current'])->toBe('17.0.0');
    Event::assertNotDispatched(IconPackUpdateAvailable::class);
});

it('detects a stale github pack and dispatches the event', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            'tag_name' => 'v17.1.0',
            'html_url' => 'https://github.com/x/y/releases/tag/v17.1.0',
            'body'     => 'new emojis',
        ], 200),
    ]);

    $checker = build_checker_for('vendor/pack-b', GithubStaleConstants::class);

    $result = $checker->checkOne('vendor/pack-b');

    expect($result['status'])->toBe('update-available');
    expect($result['current'])->toBe('17.0.0');
    expect($result['latest'])->toBe('17.1.0');

    Event::assertDispatched(IconPackUpdateAvailable::class, function (IconPackUpdateAvailable $event): bool {
        return $event->packageName === 'vendor/pack-b'
            && $event->currentVersion === '17.0.0'
            && $event->latestVersion === '17.1.0';
    });
});

it('parses an npm registry response', function () {
    Http::fake([
        'registry.npmjs.org/*' => Http::response(['version' => '5.4.2', 'name' => '@x/y'], 200),
    ]);

    $checker = build_checker_for('vendor/pack-npm', NpmConstants::class);

    $result = $checker->checkOne('vendor/pack-npm');
    expect($result['status'])->toBe('update-available');
    expect($result['latest'])->toBe('5.4.2');
    expect($result['release_url'])->toBe('https://www.npmjs.com/package/@x/y/v/5.4.2');
    Event::assertDispatched(IconPackUpdateAvailable::class, function (IconPackUpdateAvailable $e): bool {
        return $e->releaseUrl === 'https://www.npmjs.com/package/@x/y/v/5.4.2';
    });
});

it('parses a github-tag response (for projects without "releases")', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            ['name' => 'v7.4.0'],
            ['name' => 'v7.3.9'],
        ], 200),
    ]);

    $checker = build_checker_for('vendor/pack-tag', GithubTagConstants::class);

    $result = $checker->checkOne('vendor/pack-tag');
    expect($result['latest'])->toBe('7.4.0');
    expect($result['release_url'])->toBe('https://github.com/lipis/flag-icons/releases/tag/v7.4.0');
});

it('parses a packagist response and skips dev-* refs', function () {
    Http::fake([
        'repo.packagist.org/*' => Http::response([
            'packages' => [
                'foo/bar' => [
                    ['version' => 'dev-main'],
                    ['version' => 'v2.5.1'],
                    ['version' => 'v2.5.0'],
                ],
            ],
        ], 200),
    ]);

    $checker = build_checker_for('vendor/pack-cmps', PackagistConstants::class);

    $result = $checker->checkOne('vendor/pack-cmps');
    expect($result['latest'])->toBe('2.5.1');
    expect($result['release_url'])->toBe('https://packagist.org/packages/foo/bar#2.5.1');
});

it('synthesises a release URL from source.release_url_template for type=url', function () {
    Http::fake([
        'example.com/*' => Http::response(['version' => '9.9.9'], 200),
    ]);

    $checker = build_checker_for('vendor/pack-url', UrlSourceConstants::class);

    $result = $checker->checkOne('vendor/pack-url');
    expect($result['latest'])->toBe('9.9.9');
    expect($result['release_url'])->toBe('https://example.com/releases/9.9.9');
});

it('walks additional_sources and dispatches one event per stale tracker', function () {
    Http::fake([
        'registry.npmjs.org/*' => Http::response(['version' => '17.1.0'], 200),
        'api.github.com/*'     => Http::response([
            'tag_name' => 'v15.4.0',
            'html_url' => 'https://github.com/hfg-gmuend/openmoji/releases/tag/v15.4.0',
        ], 200),
    ]);

    $checker = build_checker_for('vendor/pack-multi', MultiSourceConstants::class);

    $rows = $checker->checkPackage('vendor/pack-multi');

    expect($rows)->toHaveCount(2);
    expect($rows[0]['source'])->toBe('primary');
    expect($rows[0]['latest'])->toBe('17.1.0');
    expect($rows[0]['release_url'])->toBe('https://www.npmjs.com/package/@twemoji/svg/v/17.1.0');
    expect($rows[1]['source'])->toBe('openmoji');
    expect($rows[1]['latest'])->toBe('15.4.0');

    Event::assertDispatchedTimes(IconPackUpdateAvailable::class, 2);
    Event::assertDispatched(IconPackUpdateAvailable::class, function (IconPackUpdateAvailable $e): bool {
        return $e->sourceName === 'openmoji' && $e->latestVersion === '15.4.0';
    });
});

it('reports no-upstream when the pack does not declare a block', function () {
    $checker = build_checker_for('vendor/pack-bare', BareConstants::class);

    $result = $checker->checkOne('vendor/pack-bare');
    expect($result['status'])->toBe('no-upstream');
});

it('reports unreachable when the http call fails', function () {
    Http::fake([
        'api.github.com/*' => Http::response('', 500),
    ]);

    $checker = build_checker_for('vendor/pack-c', GithubUpToDateConstants::class);

    $result = $checker->checkOne('vendor/pack-c');
    expect($result['status'])->toBe('unreachable');
});

it('blocks version check urls pointing at cloud metadata ips without sending', function () {
    Http::fake(['*' => Http::response(['version' => '9.9.9'], 200)]);

    $checker = build_checker_for('vendor/pack-evil-meta', MetadataIpConstants::class);

    $result = $checker->checkOne('vendor/pack-evil-meta');

    expect($result['status'])->toBe('error');
    Http::assertNothingSent();
});

it('blocks version check urls pointing at loopback and private networks', function () {
    Http::fake(['*' => Http::response(['version' => '9.9.9'], 200)]);

    foreach (
        [
            'vendor/pack-evil-loop' => LoopbackConstants::class,
            'vendor/pack-evil-priv' => PrivateNetConstants::class,
        ] as $package => $constants
    ) {
        $result = build_checker_for($package, $constants)->checkOne($package);

        expect($result['status'])->toBe('error');
    }

    Http::assertNothingSent();
});

it('blocks non-https version check urls', function () {
    Http::fake(['*' => Http::response(['version' => '9.9.9'], 200)]);

    foreach (
        [
            'vendor/pack-evil-http' => PlainHttpConstants::class,
            'vendor/pack-evil-file' => FileSchemeConstants::class,
        ] as $package => $constants
    ) {
        $result = build_checker_for($package, $constants)->checkOne($package);

        expect($result['status'])->toBe('error');
    }

    Http::assertNothingSent();
});

/*
 * The three below are why the resolver seam is safe to expose. Making the
 * guard testable must not make it bypassable, so each pins one way a name
 * could otherwise be used to reach somewhere it should not.
 */

it('blocks a host that resolves to a private address', function () {
    Http::fake(['*' => Http::response(['version' => '9.9.9'], 200)]);

    // internal.test resolves -- to 10.1.2.3. The injected resolver decides
    // what the name points at; it does not get to decide that is allowed.
    $result = build_checker_for('vendor/pack-evil-host', PrivateHostnameConstants::class)
        ->checkOne('vendor/pack-evil-host');

    expect($result['status'])->toBe('error');
    expect($result['reason'])->toContain('blocked');
    Http::assertNothingSent();
});

it('blocks a host that does not resolve', function () {
    Http::fake(['*' => Http::response(['version' => '9.9.9'], 200)]);

    // No records is the offline/NXDOMAIN case, and it must fail closed --
    // the same behaviour the real resolver gives a host that is not there.
    $result = build_checker_for('vendor/pack-unresolvable', UnresolvableHostConstants::class)
        ->checkOne('vendor/pack-unresolvable');

    expect($result['status'])->toBe('error');
    Http::assertNothingSent();
});

it('blocks a literal private ip even when the resolver would allow the name', function () {
    Http::fake(['*' => Http::response(['version' => '9.9.9'], 200)]);

    // A literal address short-circuits before the resolver is consulted, so
    // a resolver that answered everything with a public IP still could not
    // open up 127.0.0.1. Proved by handing it exactly that resolver.
    $registry = mock_registry_with_pack('vendor/pack-evil-loop', LoopbackConstants::class);
    $checker = new IconPackUpdateChecker($registry, cacheTtl: 0);
    $checker->setConstantsResolver(fn (string $n): ?string => LoopbackConstants::class);
    $checker->setHostResolver(static fn (string $host): array => ['140.82.121.4']);

    expect($checker->checkOne('vendor/pack-evil-loop')['status'])->toBe('error');
    Http::assertNothingSent();
});

/* -----------------------------------------------------------------------
 *  Fixtures
 * -----------------------------------------------------------------------
 *  Each one is a tiny IconsConstants subclass returning the upstream
 *  block we want to exercise. The mock registry below makes them
 *  discoverable from the checker.
 */

function mock_registry_with_pack(string $packageName, string $constantsClass): IconRegistry
{
    /** @var IconRegistry $registry */
    $registry = app(IconRegistry::class);

    // Stuff the pack into the registry so all() returns it. The real
    // registry populates this via service-provider boot; tests bypass
    // that machinery and write the entry directly.
    $reflection = new ReflectionClass($registry);
    if ($reflection->hasProperty('packages')) {
        $prop = $reflection->getProperty('packages');
        $prop->setAccessible(true);
        $current = $prop->getValue($registry);
        $current[$packageName] = ['package_name' => $packageName];
        $prop->setValue($registry, $current);
    }

    return $registry;
}

function build_checker_for(string $packageName, string $constantsClass): IconPackUpdateChecker
{
    $registry = mock_registry_with_pack($packageName, $constantsClass);
    $checker = new IconPackUpdateChecker($registry, cacheTtl: 0);
    $checker->setConstantsResolver(
        fn (string $name): ?string => $name === $packageName ? $constantsClass : null,
    );
    $checker->setHostResolver(fake_host_resolver());

    return $checker;
}

/**
 * Stands in for DNS so these unit tests resolve nothing. Http::fake()
 * intercepts the request but not the name lookup the SSRF guard does
 * first, so without this seam every test here needs a working resolver
 * and fails closed offline, in a sandbox, or behind a strict resolver.
 *
 * Reachable hosts map to real public addresses. They used to map into
 * 192.0.2.0/24 (TEST-NET-1), chosen because the guard treated documentation
 * space as routable while it could never be a real destination -- a neat
 * trick that depended on a gap in `isPublicIp()`. That gap is closed: the
 * predicate now rejects the whole IANA special-purpose registry, so no
 * address is both allowed and guaranteed-unroutable, and there is nothing
 * left to be clever with.
 *
 * Nothing is contacted regardless. This resolver is a stub and `Http::fake()`
 * intercepts the request, so the addresses below are inert because of the
 * test harness rather than because of the range they sit in.
 *
 * Two entries are the point of the fixture rather than scaffolding:
 * `internal.test` maps into a private range, and any host not listed
 * resolves to nothing. They pin that the seam substitutes what a name
 * resolves to, never whether an address is allowed.
 *
 * @return Closure(string):list<string>
 */
function fake_host_resolver(): Closure
{
    return static fn (string $host): array => match ($host) {
        'api.github.com'     => ['140.82.121.4'],
        'registry.npmjs.org' => ['104.16.24.35'],
        'repo.packagist.org' => ['104.26.14.72'],
        'example.com'        => ['93.184.216.34'],
        'internal.test'      => ['10.1.2.3'],
        default              => [],
    };
}

/**
 * Inject canned config data into JsonConfigConstants's private static
 * cache so the subclass's `config()` reads our test data instead of
 * trying to find a real config.json on disk.
 */
function inject_constants_config(string $constantsClass, array $data): void
{
    $reflection = new ReflectionClass(JsonConfigConstants::class);
    $prop = $reflection->getProperty('configs');
    $prop->setAccessible(true);
    $current = $prop->getValue();
    $current[$constantsClass] = $data;
    $prop->setValue(null, $current);
}

abstract class _FakeUpstreamConstants extends JsonConfigConstants
{
    protected static function getConfigPath(): string
    {
        return '';
    }
}

final class GithubUpToDateConstants extends _FakeUpstreamConstants {}
final class GithubStaleConstants extends _FakeUpstreamConstants {}
final class GithubTagConstants extends _FakeUpstreamConstants {}
final class NpmConstants extends _FakeUpstreamConstants {}
final class PackagistConstants extends _FakeUpstreamConstants {}
final class BareConstants extends _FakeUpstreamConstants {}
final class UrlSourceConstants extends _FakeUpstreamConstants {}
final class MultiSourceConstants extends _FakeUpstreamConstants {}
final class MetadataIpConstants extends _FakeUpstreamConstants {}
final class LoopbackConstants extends _FakeUpstreamConstants {}
final class PrivateNetConstants extends _FakeUpstreamConstants {}
final class PlainHttpConstants extends _FakeUpstreamConstants {}
final class FileSchemeConstants extends _FakeUpstreamConstants {}
final class PrivateHostnameConstants extends _FakeUpstreamConstants {}
final class UnresolvableHostConstants extends _FakeUpstreamConstants {}

beforeEach(function () {
    inject_constants_config(GithubUpToDateConstants::class, [
        'package'  => ['name' => 'vendor/pack-a'],
        'upstream' => [
            'source'            => ['type' => 'github', 'owner' => 'x', 'repo' => 'y'],
            'current_version'   => '17.0.0',
            'version_check_url' => 'https://api.github.com/repos/x/y/releases/latest',
        ],
    ]);
    inject_constants_config(GithubStaleConstants::class, [
        'package'  => ['name' => 'vendor/pack-b'],
        'upstream' => [
            'source'            => ['type' => 'github', 'owner' => 'x', 'repo' => 'y'],
            'current_version'   => '17.0.0',
            'version_check_url' => 'https://api.github.com/repos/x/y/releases/latest',
        ],
    ]);
    inject_constants_config(GithubTagConstants::class, [
        'package'  => ['name' => 'vendor/pack-tag'],
        'upstream' => [
            'source'            => ['type' => 'github-tag', 'owner' => 'lipis', 'repo' => 'flag-icons'],
            'current_version'   => '7.0.0',
            'version_check_url' => 'https://api.github.com/repos/lipis/flag-icons/tags?per_page=1',
        ],
    ]);
    inject_constants_config(NpmConstants::class, [
        'package'  => ['name' => 'vendor/pack-npm'],
        'upstream' => [
            'source'            => ['type' => 'npm', 'package' => '@x/y'],
            'current_version'   => '5.0.0',
            'version_check_url' => 'https://registry.npmjs.org/@x/y/latest',
        ],
    ]);
    inject_constants_config(PackagistConstants::class, [
        'package'  => ['name' => 'vendor/pack-cmps'],
        'upstream' => [
            'source'            => ['type' => 'packagist', 'vendor' => 'foo', 'package' => 'bar'],
            'current_version'   => '2.0.0',
            'version_check_url' => 'https://repo.packagist.org/p2/foo/bar.json',
        ],
    ]);
    inject_constants_config(BareConstants::class, [
        'package' => ['name' => 'vendor/pack-bare'],
        // No 'upstream' key at all.
    ]);
    inject_constants_config(UrlSourceConstants::class, [
        'package'  => ['name' => 'vendor/pack-url'],
        'upstream' => [
            'source' => [
                'type'                 => 'url',
                'version_field'        => 'version',
                'release_url_template' => 'https://example.com/releases/{version}',
            ],
            'current_version'   => '1.0.0',
            'version_check_url' => 'https://example.com/latest.json',
        ],
    ]);
    inject_constants_config(MultiSourceConstants::class, [
        'package'  => ['name' => 'vendor/pack-multi'],
        'upstream' => [
            'source'             => ['type' => 'npm', 'package' => '@twemoji/svg'],
            'current_version'    => '17.0.0',
            'version_check_url'  => 'https://registry.npmjs.org/@twemoji/svg/latest',
            'additional_sources' => [
                [
                    'name'              => 'openmoji',
                    'type'              => 'github',
                    'owner'             => 'hfg-gmuend',
                    'repo'              => 'openmoji',
                    'current_version'   => '15.0.0',
                    'version_check_url' => 'https://api.github.com/repos/hfg-gmuend/openmoji/releases/latest',
                ],
            ],
        ],
    ]);
    inject_constants_config(MetadataIpConstants::class, [
        'package'  => ['name' => 'vendor/pack-evil-meta'],
        'upstream' => [
            'source'            => ['type' => 'url', 'version_field' => 'version'],
            'current_version'   => '1.0.0',
            'version_check_url' => 'https://169.254.169.254/latest/meta-data/',
        ],
    ]);
    inject_constants_config(LoopbackConstants::class, [
        'package'  => ['name' => 'vendor/pack-evil-loop'],
        'upstream' => [
            'source'            => ['type' => 'url', 'version_field' => 'version'],
            'current_version'   => '1.0.0',
            'version_check_url' => 'https://127.0.0.1:8443/latest.json',
        ],
    ]);
    inject_constants_config(PrivateNetConstants::class, [
        'package'  => ['name' => 'vendor/pack-evil-priv'],
        'upstream' => [
            'source'            => ['type' => 'url', 'version_field' => 'version'],
            'current_version'   => '1.0.0',
            'version_check_url' => 'https://10.0.0.1/latest.json',
        ],
    ]);
    inject_constants_config(PlainHttpConstants::class, [
        'package'  => ['name' => 'vendor/pack-evil-http'],
        'upstream' => [
            'source'            => ['type' => 'url', 'version_field' => 'version'],
            'current_version'   => '1.0.0',
            'version_check_url' => 'http://example.com/latest.json',
        ],
    ]);
    inject_constants_config(FileSchemeConstants::class, [
        'package'  => ['name' => 'vendor/pack-evil-file'],
        'upstream' => [
            'source'            => ['type' => 'url', 'version_field' => 'version'],
            'current_version'   => '1.0.0',
            'version_check_url' => 'file:///etc/passwd',
        ],
    ]);
    inject_constants_config(PrivateHostnameConstants::class, [
        'package'  => ['name' => 'vendor/pack-evil-host'],
        'upstream' => [
            'source'            => ['type' => 'url', 'version_field' => 'version'],
            'current_version'   => '1.0.0',
            'version_check_url' => 'https://internal.test/latest.json',
        ],
    ]);
    inject_constants_config(UnresolvableHostConstants::class, [
        'package'  => ['name' => 'vendor/pack-unresolvable'],
        'upstream' => [
            'source'            => ['type' => 'url', 'version_field' => 'version'],
            'current_version'   => '1.0.0',
            'version_check_url' => 'https://nowhere.invalid/latest.json',
        ],
    ]);
});
