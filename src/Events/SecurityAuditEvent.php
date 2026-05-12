<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Simtabi\Laranail\Ichava\Support\AuditLogger;

/**
 * Dispatched by {@see AuditLogger} for every
 * audit-relevant event the package emits. Listeners may forward to SIEM, alert
 * on high-severity items, or persist to a tamper-evident store.
 *
 * Severity follows RFC 5424 numeric levels (lower is more severe). Common
 * values used by Ichava:
 *   - 0 emergency (system unusable)
 *   - 2 critical (cache poisoning, signed-URL bypass attempt)
 *   - 3 error    (sanitiser rejected input, path-traversal attempt)
 *   - 4 warning  (rate-limit hit, suspicious pattern)
 *   - 6 info     (registration, normal lifecycle)
 */
final readonly class SecurityAuditEvent
{
    use Dispatchable;

    public function __construct(
        public string $event,
        public int $severity,
        public array $context = [],
        public ?string $actor = null,
        public ?string $sourceIp = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
        public int $timestamp = 0,
    ) {}
}
