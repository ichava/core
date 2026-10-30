<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

/**
 * Operational health of the Ichava install, in one shape for every surface:
 * `ichava::ichava-core.info status`, `Ichava::status()` and the gated status
 * endpoint icon-browser serves.
 *
 * Holds no absolute server path, so it is safe to send to an authorised
 * browser. The CLI adds paths separately when it prints.
 */
final readonly class StatusData
{
    /**
     * @param array<string, mixed> $lifecycle Stage, checks and icon count
     * @param array<string, mixed> $conflicts Registry conflicts, by kind
     * @param list<string> $orphans Packages with seeded rows that no registered pack owns
     * @param array{total_discovered: int, total_registered: int, total_unregistered: int, unregistered_list: list<string>} $discovery
     * @param array{enabled: bool, performance: bool, security: bool, channel: string, seeding_channel: string} $logging
     * @param array<string, mixed>|null $lastRebuild Counts, duration and time of the last cache rebuild
     */
    public function __construct(
        public array $lifecycle,
        public int $packages,
        public array $conflicts,
        public array $orphans,
        public array $discovery,
        public ?string $lastScan,
        public array $logging,
        public ?array $lastRebuild,
        public string $optimizationLevel,
        public string $renderFingerprint,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lifecycle' => $this->lifecycle,
            'registry'  => ['packages' => $this->packages, 'conflicts' => $this->conflicts],
            'orphans'   => $this->orphans,
            'discovery' => $this->discovery,
            'watcher'   => ['lastScan' => $this->lastScan],
            'logging'   => $this->logging,
            'cache'     => ['lastRebuild' => $this->lastRebuild],
            'render'    => ['optimizationLevel' => $this->optimizationLevel, 'fingerprint' => $this->renderFingerprint],
        ];
    }
}
