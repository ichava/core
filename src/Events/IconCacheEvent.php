<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Lifecycle event covering icon-cache state changes.
 *
 * Dispatched as `invalidated`, `rebuilt`, or `changed`. Branch on `$action`
 * (or the `is*()` helpers) in listeners. Use the named constructors to build
 * each variant; the constructor itself is private to enforce the discriminator.
 */
final class IconCacheEvent
{
    use Dispatchable, SerializesModels;

    // Action type constants
    public const ACTION_INVALIDATED = 'invalidated';

    public const ACTION_REBUILT = 'rebuilt';

    public const ACTION_CHANGED = 'changed';

    private function __construct(
        public readonly string $action,
        public readonly ?string $package = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Create an 'invalidated' event (cache was cleared)
     *
     * @param string $reason Why the cache was invalidated
     * @param array<string> $clearedKeys List of cache keys that were cleared
     */
    public static function invalidated(string $reason, array $clearedKeys = []): self
    {
        return new self(
            action: self::ACTION_INVALIDATED,
            reason: $reason,
            metadata: ['cleared_keys' => $clearedKeys],
        );
    }

    /**
     * Create a 'rebuilt' event (cache was rebuilt with new data)
     *
     * @param int $iconCount Total number of icons cached
     * @param int $categoryCount Total number of categories cached
     * @param int $packageCount Total number of packages cached
     * @param float $buildTimeMs Time taken to rebuild cache (milliseconds)
     */
    public static function rebuilt(
        int $iconCount,
        int $categoryCount,
        int $packageCount,
        float $buildTimeMs,
    ): self {
        return new self(
            action: self::ACTION_REBUILT,
            metadata: [
                'icon_count'     => $iconCount,
                'category_count' => $categoryCount,
                'package_count'  => $packageCount,
                'build_time_ms'  => $buildTimeMs,
            ],
        );
    }

    /**
     * Create a 'changed' event (icons were added/removed/modified)
     *
     * @param string|null $package Specific package that changed (null = all packages)
     * @param string|null $reason Why the icons changed
     * @param array<string, mixed> $metadata Additional metadata about the change
     */
    public static function changed(?string $package = null, ?string $reason = null, array $metadata = []): self
    {
        return new self(
            action: self::ACTION_CHANGED,
            package: $package,
            reason: $reason,
            metadata: $metadata,
        );
    }

    /**
     * Check if this is an 'invalidated' event
     */
    public function isInvalidated(): bool
    {
        return $this->action === self::ACTION_INVALIDATED;
    }

    /**
     * Check if this is a 'rebuilt' event
     */
    public function isRebuilt(): bool
    {
        return $this->action === self::ACTION_REBUILT;
    }

    /**
     * Check if this is a 'changed' event
     */
    public function isChanged(): bool
    {
        return $this->action === self::ACTION_CHANGED;
    }

    /**
     * Get cleared cache keys (for 'invalidated' events)
     *
     * @return array<string>
     */
    public function getClearedKeys(): array
    {
        return $this->metadata['cleared_keys'] ?? [];
    }

    /**
     * Get rebuild statistics (for 'rebuilt' events)
     *
     * @return array{icon_count: int, category_count: int, package_count: int, build_time_ms: float}
     */
    public function getRebuildStats(): array
    {
        return [
            'icon_count'     => $this->metadata['icon_count'] ?? 0,
            'category_count' => $this->metadata['category_count'] ?? 0,
            'package_count'  => $this->metadata['package_count'] ?? 0,
            'build_time_ms'  => $this->metadata['build_time_ms'] ?? 0.0,
        ];
    }

    /**
     * Get icon count (for 'rebuilt' events)
     */
    public function getIconCount(): int
    {
        return $this->metadata['icon_count'] ?? 0;
    }

    /**
     * Get category count (for 'rebuilt' events)
     */
    public function getCategoryCount(): int
    {
        return $this->metadata['category_count'] ?? 0;
    }

    /**
     * Get package count (for 'rebuilt' events)
     */
    public function getPackageCount(): int
    {
        return $this->metadata['package_count'] ?? 0;
    }

    /**
     * Get build time in milliseconds (for 'rebuilt' events)
     */
    public function getBuildTimeMs(): float
    {
        return $this->metadata['build_time_ms'] ?? 0.0;
    }

    /**
     * Convert event to array for logging
     */
    public function toArray(): array
    {
        return [
            'action'   => $this->action,
            'package'  => $this->package,
            'reason'   => $this->reason,
            'metadata' => $this->metadata,
        ];
    }
}
