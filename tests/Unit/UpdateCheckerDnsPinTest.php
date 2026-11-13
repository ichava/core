<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;

/*
 * The version check connects to the address it validated (S8).
 *
 * The SSRF guard resolves the host and refuses anything not publicly
 * routable, but the HTTP client used to resolve the host again on its own.
 * A rebinding resolver could answer a public address to the guard and a
 * private one to the client. The validated addresses are now pinned into the
 * request (curl's CURLOPT_RESOLVE), so the second lookup never happens.
 */
function pinProbe(array $ips): object
{
    return new class(app(IconRegistry::class), $ips) extends IconPackUpdateChecker
    {
        /** @param list<string> $ips */
        public function __construct(IconRegistry $registry, array $ips)
        {
            parent::__construct($registry, hostResolver: static fn (string $host): array => $ips);
        }

        /** @return list<string> */
        public function pins(string $url): array
        {
            return $this->resolvePins($url);
        }

        /** @return array<string, mixed> */
        public function options(string $url): array
        {
            return $this->requestOptions($url);
        }
    };
}

it('pins every validated address for the host and port', function (): void {
    $probe = pinProbe(['140.82.112.6', '2606:50c0:8000::153']);

    expect($probe->pins('https://api.github.com/repos/x/y/releases/latest'))
        ->toBe(['api.github.com:443:140.82.112.6', 'api.github.com:443:[2606:50c0:8000::153]'])
        ->and($probe->pins('https://registry.example.com:8443/x'))
        ->toBe(['registry.example.com:8443:140.82.112.6', 'registry.example.com:8443:[2606:50c0:8000::153]']);
});

it('passes the pins to curl, and pins nothing for a literal address', function (): void {
    $probe = pinProbe(['140.82.112.6']);

    expect($probe->options('https://api.github.com/x'))->toBe(['curl' => [CURLOPT_RESOLVE => ['api.github.com:443:140.82.112.6']]])
        ->and($probe->pins('https://140.82.112.6/x'))->toBe([])
        ->and($probe->options('https://140.82.112.6/x'))->toBe([]);
});

it('pins nothing an address check would refuse', function (): void {
    expect(pinProbe(['10.0.0.1'])->pins('https://api.github.com/x'))->toBe([])
        ->and(pinProbe([])->pins('https://api.github.com/x'))->toBe([]);
});
