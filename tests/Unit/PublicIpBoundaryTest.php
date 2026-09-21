<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;

/**
 * The address predicate behind the version-check SSRF guard.
 *
 * It used to be `filter_var(..., NO_PRIV_RANGE | NO_RES_RANGE)` plus a `127.`
 * prefix check. That pair covers RFC 1918, loopback and link-local and admits
 * everything else in the IANA special-purpose registry -- carrier-grade NAT
 * among them -- and it judges an address by its notation, so three IPv6 forms
 * that carry an IPv4 address inside them passed while the address they carry
 * would not have.
 *
 * Every case below was accepted by that implementation unless marked otherwise.
 */
function ipProbe(): object
{
    return new class extends IconPackUpdateChecker
    {
        public function __construct() {}

        public function allows(string $ip): bool
        {
            return $this->isPublicIp($ip);
        }
    };
}

dataset('blocked addresses', [
    'loopback'                  => ['127.0.0.1'],
    'loopback, high octet'      => ['127.255.255.254'],
    'unspecified'               => ['0.0.0.0'],
    'RFC 1918 /8'               => ['10.0.0.1'],
    'RFC 1918 /12'              => ['172.16.0.1'],
    'RFC 1918 /16'              => ['192.168.1.1'],
    'cloud metadata'            => ['169.254.169.254'],
    'carrier-grade NAT'         => ['100.64.0.1'],
    'carrier-grade NAT, top'    => ['100.127.255.254'],
    'IETF protocol assignments' => ['192.0.0.1'],
    'benchmarking'              => ['198.18.0.1'],
    'TEST-NET-1'                => ['192.0.2.1'],
    'TEST-NET-2'                => ['198.51.100.1'],
    'TEST-NET-3'                => ['203.0.113.1'],
    'multicast'                 => ['224.0.0.1'],
    'reserved'                  => ['240.0.0.1'],
    'broadcast'                 => ['255.255.255.255'],
    'IPv6 loopback'             => ['::1'],
    'IPv6 unspecified'          => ['::'],
    'IPv6 unique-local'         => ['fd00::1'],
    'IPv6 link-local'           => ['fe80::1'],
    'IPv6 multicast'            => ['ff02::1'],
    'IPv6 documentation'        => ['2001:db8::1'],
    'IPv4-mapped loopback'      => ['::ffff:127.0.0.1'],
    'IPv4-mapped private'       => ['::ffff:10.0.0.1'],
    'IPv4-mapped CGNAT'         => ['::ffff:100.64.0.1'],
    'NAT64 loopback'            => ['64:ff9b::7f00:1'],
    '6to4 loopback'             => ['2002:7f00:1::'],
    '6to4 private'              => ['2002:0a00:0001::'],
    'IPv4-compatible loopback'  => ['::7f00:1'],
    'IPv4-compatible private'   => ['::a00:1'],
    'IPv4-compatible metadata'  => ['::a9fe:a9fe'],
    'IPv4-compatible CGNAT'     => ['::6440:1'],
]);

dataset('routable addresses', [
    'Google DNS'      => ['8.8.8.8'],
    'Cloudflare DNS'  => ['1.1.1.1'],
    'GitHub'          => ['140.82.121.4'],
    'Cloudflare IPv6' => ['2606:4700:4700::1111'],
    'Google IPv6'     => ['2a00:1450:4001:80e::200e'],
]);

it('refuses an address in the special-purpose registry', function (string $ip) {
    expect(ipProbe()->allows($ip))->toBeFalse();
})->with('blocked addresses');

it('still allows a genuinely routable address', function (string $ip) {
    expect(ipProbe()->allows($ip))->toBeTrue();
})->with('routable addresses');

it('refuses anything that is not an address at all', function () {
    $probe = ipProbe();

    foreach (['', 'not-an-ip', '999.999.999.999', '127.0.0.1 ', '0x7f000001'] as $bad) {
        expect($probe->allows($bad))->toBeFalse();
    }
});

it('judges an address by its value, not its notation', function () {
    // Every spelling of loopback must answer the same way. There are five, and
    // the count is the point: the first version of this fix unwrapped three of
    // them and left `::7f00:1` accepted, which made "by value, not notation"
    // false as stated while reading as though it were true.
    $probe = ipProbe();

    foreach (['127.0.0.1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::', '::7f00:1'] as $spelling) {
        expect($probe->allows($spelling))->toBeFalse();
    }
});
