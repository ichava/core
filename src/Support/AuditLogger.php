<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Simtabi\Laranail\Ichava\Events\SecurityAuditEvent;
use Throwable;

/**
 * Central security-audit pipeline.
 *
 * Every protection layer in Ichava (sanitiser rejection, path-traversal
 * detection, rate-limit hit, signed-URL bypass attempt, suspicious input
 * pattern, etc.) routes through here. The logger:
 *
 *   1. Writes a structured line to the dedicated `ichava-audit` channel.
 *   2. Dispatches a {@see SecurityAuditEvent} so host applications can
 *      forward to SIEM, alert on severity, or persist for compliance.
 *
 * Both sinks are independently toggleable so headless deployments (no event
 * dispatcher) and minimal deployments (no audit channel configured) keep
 * working.
 */
final class AuditLogger
{
    public const SEVERITY_EMERGENCY = 0;

    public const SEVERITY_CRITICAL = 2;

    public const SEVERITY_ERROR = 3;

    public const SEVERITY_WARNING = 4;

    public const SEVERITY_INFO = 6;

    public function __construct(
        private readonly LogManager $logs,
        private readonly Dispatcher $events,
        private readonly ConfigRepository $config,
    ) {}

    public function emergency(string $event, array $context = []): void
    {
        $this->record($event, self::SEVERITY_EMERGENCY, $context);
    }

    public function critical(string $event, array $context = []): void
    {
        $this->record($event, self::SEVERITY_CRITICAL, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->record($event, self::SEVERITY_ERROR, $context);
    }

    public function warning(string $event, array $context = []): void
    {
        $this->record($event, self::SEVERITY_WARNING, $context);
    }

    public function info(string $event, array $context = []): void
    {
        $this->record($event, self::SEVERITY_INFO, $context);
    }

    public function record(string $event, int $severity, array $context = []): void
    {
        if (! (bool) $this->config->get('ichava.core.security.audit.enabled', true)) {
            return;
        }

        $allowed = (array) $this->config->get('ichava.core.security.audit.events', []);
        if ($allowed !== [] && ! in_array($event, $allowed, true)) {
            return;
        }

        $payload = $this->buildPayload($event, $severity, $context);

        $this->writeLog($severity, $event, $payload);
        $this->dispatchEvent($payload);
    }

    private function buildPayload(string $event, int $severity, array $context): array
    {
        $request = $this->resolveRequest();

        return [
            'event' => $event,
            'severity' => $severity,
            'context' => $context,
            'actor' => $this->resolveActor($request),
            'source_ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $request?->headers->get('X-Request-Id'),
            'timestamp' => time(),
        ];
    }

    private function writeLog(int $severity, string $event, array $payload): void
    {
        $channel = (string) $this->config->get('ichava.core.security.audit.channel', 'ichava-audit');

        try {
            $logger = $this->logs->channel($channel);
        } catch (Throwable) {
            $logger = $this->logs->channel('ichava');
        }

        $level = match (true) {
            $severity <= self::SEVERITY_EMERGENCY => 'emergency',
            $severity <= self::SEVERITY_CRITICAL => 'critical',
            $severity <= self::SEVERITY_ERROR => 'error',
            $severity <= self::SEVERITY_WARNING => 'warning',
            default => 'info',
        };

        $logger->{$level}($event, $payload);
    }

    private function dispatchEvent(array $payload): void
    {
        if (! (bool) $this->config->get('ichava.core.security.audit.dispatch_event', true)) {
            return;
        }

        $this->events->dispatch(new SecurityAuditEvent(
            event: $payload['event'],
            severity: $payload['severity'],
            context: $payload['context'],
            actor: $payload['actor'],
            sourceIp: $payload['source_ip'],
            userAgent: $payload['user_agent'],
            requestId: $payload['request_id'],
            timestamp: $payload['timestamp'],
        ));
    }

    private function resolveRequest(): ?Request
    {
        try {
            $request = app('request');
        } catch (Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }

    private function resolveActor(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        try {
            $user = $request->user();
        } catch (Throwable) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        return method_exists($user, 'getAuthIdentifier')
            ? (string) $user->getAuthIdentifier()
            : null;
    }
}
