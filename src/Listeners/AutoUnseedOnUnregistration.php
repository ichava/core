<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Listeners;

use Simtabi\Laranail\Ichava\Events\IconRegistrationEvent;
use Simtabi\Laranail\Ichava\Services\DatabaseOperationsService;
use Simtabi\Laranail\Ichava\Services\IchavaLifecycleManager;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Throwable;

/**
 * Removes a package's icon rows + orphaned terms when the package unregisters.
 */
class AutoUnseedOnUnregistration
{
    public function __construct(
        protected DatabaseOperationsService $databaseService,
        protected IchavaLifecycleManager $lifecycle,
        protected IchavaLogger $logger,
    ) {}

    /**
     * Handle the event
     */
    public function handle(IconRegistrationEvent $event): void
    {
        // Only handle 'unregistered' events
        if (! $event->isUnregistered()) {
            return;
        }

        // Check if auto-unseed is enabled
        if (! config('ichava.core.database.auto_unseed', true)) {
            $this->logger->debug('ℹ️ Auto-unseed is disabled in config');

            return;
        }

        // Check if database is enabled
        if (! config('ichava.core.database.enabled', true)) {
            $this->logger->debug('ℹ️ Database is disabled in config');

            return;
        }

        // Check if migrations exist before attempting to unseed
        if (! $this->lifecycle->hasMigrations()) {
            $this->logger->info('⏭️ Skipping auto-unseed - migrations not run yet', [
                'package' => $event->name,
                'stage' => $this->lifecycle->getStage(),
            ]);

            return;
        }

        $packageName = $event->metadata['package_name'] ?? $event->name;

        $this->logger->debug("Auto-unseeding icons for package: {$packageName}");

        try {
            $stats = $this->databaseService->unseedPackage($packageName);

            $this->logger->info("Successfully auto-unseeded package: {$packageName}", $stats);
        } catch (Throwable $e) {
            // Log error but don't throw - unseed failure shouldn't break unregistration
            $this->logger->error("Failed to auto-unseed package: {$packageName}", $e, [
                'package' => $packageName,
            ]);
        }
    }

    /**
     * Determine whether the listener should be queued
     */
    public function shouldQueue(): bool
    {
        // Run synchronously to ensure immediate cleanup
        return false;
    }
}
