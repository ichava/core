<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Ichava\Events\SecurityAuditEvent;
use Simtabi\Laranail\Ichava\Support\AuditLogger;

it('dispatches a SecurityAuditEvent when an audit record is written', function (): void {
    Event::fake([SecurityAuditEvent::class]);

    /** @var AuditLogger $logger */
    $logger = $this->app->make(AuditLogger::class);
    $logger->error('test.event', ['extra' => 'value']);

    Event::assertDispatched(SecurityAuditEvent::class, function (SecurityAuditEvent $event): bool {
        return $event->event === 'test.event'
            && $event->severity === AuditLogger::SEVERITY_ERROR
            && ($event->context['extra'] ?? null) === 'value';
    });
});

it('respects the audit.enabled config flag', function (): void {
    config(['ichava.security.audit.enabled' => false]);
    Event::fake([SecurityAuditEvent::class]);

    /** @var AuditLogger $logger */
    $logger = $this->app->make(AuditLogger::class);
    $logger->error('disabled.event');

    Event::assertNotDispatched(SecurityAuditEvent::class);
});

it('honours the audit.events whitelist', function (): void {
    config(['ichava.security.audit.events' => ['allowed.event']]);
    Event::fake([SecurityAuditEvent::class]);

    /** @var AuditLogger $logger */
    $logger = $this->app->make(AuditLogger::class);
    $logger->warning('not.allowed');
    $logger->warning('allowed.event');

    Event::assertDispatched(SecurityAuditEvent::class, 1);
});

it('skips event dispatch when dispatch_event is disabled but still logs', function (): void {
    config(['ichava.security.audit.dispatch_event' => false]);
    Event::fake([SecurityAuditEvent::class]);

    /** @var AuditLogger $logger */
    $logger = $this->app->make(AuditLogger::class);
    $logger->info('still.recorded');

    Event::assertNotDispatched(SecurityAuditEvent::class);
});
