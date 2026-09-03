<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Events;

use Throwable;
use DateTimeImmutable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Lifecycle event covering every state of an icon set / package registration.
 *
 * The same class is dispatched for `started`, `processing`, `registered`,
 * `failed`, `completed`, and `unregistered`, branch on `$action` (or the
 * `is*()` helpers) in listeners. Use the named constructors to build each
 * variant; the constructor itself is private to enforce the action discriminator.
 */
final class IconRegistrationEvent
{
    use Dispatchable, SerializesModels;

    // Action type constants
    public const ACTION_STARTED = 'started';

    public const ACTION_PROCESSING = 'processing';

    public const ACTION_REGISTERED = 'registered';

    public const ACTION_FAILED = 'failed';

    public const ACTION_COMPLETED = 'completed';

    public const ACTION_UNREGISTERED = 'unregistered';

    // Registration mode constants
    public const MODE_SINGLE = 'single';

    public const MODE_BULK = 'bulk';

    public const MODE_ICONSET = 'iconset';

    public const MODE_PACKAGE = 'package';

    private function __construct(
        public readonly string $action,
        public readonly string $registrarId,
        public readonly string $name,
        public readonly DateTimeImmutable $timestamp,
        public readonly array $context = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * Create a 'started' event (registration process begins)
     *
     * @param string $registrarId Unique registrar instance ID
     * @param string $name Icon set or package name
     * @param int $totalIconSets Total number of icon sets to register
     * @param string $mode Registration mode (single, bulk, iconset, package)
     * @param array<string, mixed> $context Additional context data
     */
    public static function started(
        string $registrarId,
        string $name,
        int $totalIconSets,
        string $mode,
        array $context = [],
    ): self {
        return new self(
            action: self::ACTION_STARTED,
            registrarId: $registrarId,
            name: $name,
            timestamp: new DateTimeImmutable,
            context: $context,
            metadata: [
                'total_icon_sets' => $totalIconSets,
                'mode'            => $mode,
            ],
        );
    }

    /**
     * Create a 'processing' event (processing each icon set)
     *
     * @param string $registrarId Unique registrar instance ID
     * @param string $name Icon set or package name
     * @param array<string, mixed> $metadata Icon set metadata
     * @param int $position Current position in batch
     * @param int $total Total in batch
     * @param array<string, mixed> $context Additional context data
     */
    public static function processing(
        string $registrarId,
        string $name,
        array $metadata,
        int $position,
        int $total,
        array $context = [],
    ): self {
        return new self(
            action: self::ACTION_PROCESSING,
            registrarId: $registrarId,
            name: $name,
            timestamp: new DateTimeImmutable,
            context: $context,
            metadata: array_merge($metadata, [
                'position' => $position,
                'total'    => $total,
                'progress' => $total > 0 ? round(($position / $total) * 100, 2) : 0,
            ]),
        );
    }

    /**
     * Create a 'registered' event (icon set successfully registered)
     *
     * @param string $registrarId Unique registrar instance ID
     * @param string $name Icon set or package name
     * @param array<string, mixed> $metadata Icon set metadata
     * @param float $duration Registration duration in milliseconds
     * @param int|null $iconCount Number of icons in the set (if available)
     * @param array<string, mixed> $context Additional context data
     */
    public static function registered(
        string $registrarId,
        string $name,
        array $metadata,
        float $duration,
        ?int $iconCount = null,
        array $context = [],
    ): self {
        return new self(
            action: self::ACTION_REGISTERED,
            registrarId: $registrarId,
            name: $name,
            timestamp: new DateTimeImmutable,
            context: $context,
            metadata: array_merge($metadata, [
                'duration'   => $duration,
                'icon_count' => $iconCount,
            ]),
        );
    }

    /**
     * Create a 'failed' event (icon set registration failed)
     *
     * @param string $registrarId Unique registrar instance ID
     * @param string $name Icon set or package name
     * @param array<string, mixed> $metadata Icon set metadata
     * @param Throwable $exception Exception that caused the failure
     * @param array<string, mixed> $context Additional context data
     */
    public static function failed(
        string $registrarId,
        string $name,
        array $metadata,
        Throwable $exception,
        array $context = [],
    ): self {
        return new self(
            action: self::ACTION_FAILED,
            registrarId: $registrarId,
            name: $name,
            timestamp: new DateTimeImmutable,
            context: $context,
            metadata: array_merge($metadata, [
                'exception'     => $exception,
                'error_message' => $exception->getMessage(),
                'error_type'    => get_class($exception),
                'error_file'    => $exception->getFile(),
                'error_line'    => $exception->getLine(),
            ]),
        );
    }

    /**
     * Create a 'completed' event (registration process completes)
     *
     * @param string $registrarId Unique registrar instance ID
     * @param string $name Icon set or package name
     * @param array<string, mixed> $statistics Registration statistics
     * @param float $duration Total duration in milliseconds
     * @param array<string, mixed> $context Additional context data
     */
    public static function completed(
        string $registrarId,
        string $name,
        array $statistics,
        float $duration,
        array $context = [],
    ): self {
        return new self(
            action: self::ACTION_COMPLETED,
            registrarId: $registrarId,
            name: $name,
            timestamp: new DateTimeImmutable,
            context: $context,
            metadata: [
                'statistics'     => $statistics,
                'duration'       => $duration,
                'was_successful' => ($statistics['failed'] ?? 0) === 0,
            ],
        );
    }

    /**
     * Create an 'unregistered' event (icon set removed/unregistered)
     *
     * @param string $registrarId Unique registrar instance ID
     * @param string $name Icon set or package name
     * @param array<string, mixed> $metadata Icon set metadata
     * @param array<string, mixed> $context Additional context data
     */
    public static function unregistered(
        string $registrarId,
        string $name,
        array $metadata = [],
        array $context = [],
    ): self {
        return new self(
            action: self::ACTION_UNREGISTERED,
            registrarId: $registrarId,
            name: $name,
            timestamp: new DateTimeImmutable,
            context: $context,
            metadata: $metadata,
        );
    }

    /**
     * Check if this is a 'started' event
     */
    public function isStarted(): bool
    {
        return $this->action === self::ACTION_STARTED;
    }

    /**
     * Check if this is a 'processing' event
     */
    public function isProcessing(): bool
    {
        return $this->action === self::ACTION_PROCESSING;
    }

    /**
     * Check if this is a 'registered' event
     */
    public function isRegistered(): bool
    {
        return $this->action === self::ACTION_REGISTERED;
    }

    /**
     * Check if this is a 'failed' event
     */
    public function isFailed(): bool
    {
        return $this->action === self::ACTION_FAILED;
    }

    /**
     * Check if this is a 'completed' event
     */
    public function isCompleted(): bool
    {
        return $this->action === self::ACTION_COMPLETED;
    }

    /**
     * Check if this is an 'unregistered' event
     */
    public function isUnregistered(): bool
    {
        return $this->action === self::ACTION_UNREGISTERED;
    }

    /**
     * Get total icon sets (for 'started' events)
     */
    public function getTotalIconSets(): int
    {
        return $this->metadata['total_icon_sets'] ?? 0;
    }

    /**
     * Get registration mode (for 'started' events)
     */
    public function getMode(): string
    {
        return $this->metadata['mode'] ?? '';
    }

    /**
     * Get current position (for 'processing' events)
     */
    public function getPosition(): int
    {
        return $this->metadata['position'] ?? 0;
    }

    /**
     * Get total items (for 'processing' events)
     */
    public function getTotal(): int
    {
        return $this->metadata['total'] ?? 0;
    }

    /**
     * Get progress percentage (for 'processing' events)
     */
    public function getProgress(): float
    {
        return $this->metadata['progress'] ?? 0.0;
    }

    /**
     * Get duration in milliseconds (for 'registered' and 'completed' events)
     */
    public function getDuration(): float
    {
        return $this->metadata['duration'] ?? 0.0;
    }

    /**
     * Get icon count (for 'registered' events)
     */
    public function getIconCount(): ?int
    {
        return $this->metadata['icon_count'] ?? null;
    }

    /**
     * Get statistics (for 'completed' events)
     */
    public function getStatistics(): array
    {
        return $this->metadata['statistics'] ?? [];
    }

    /**
     * Check if registration was successful (for 'completed' events)
     */
    public function wasSuccessful(): bool
    {
        return $this->metadata['was_successful'] ?? false;
    }

    /**
     * Get exception (for 'failed' events)
     */
    public function getException(): ?Throwable
    {
        return $this->metadata['exception'] ?? null;
    }

    /**
     * Get error message (for 'failed' events)
     */
    public function getErrorMessage(): string
    {
        return $this->metadata['error_message'] ?? '';
    }

    /**
     * Get error type (for 'failed' events)
     */
    public function getErrorType(): string
    {
        return $this->metadata['error_type'] ?? '';
    }

    /**
     * Get event name for logging
     */
    public function getEventName(): string
    {
        return match ($this->action) {
            self::ACTION_STARTED      => 'icon.registration.started',
            self::ACTION_PROCESSING   => 'icon.registration.processing',
            self::ACTION_REGISTERED   => 'icon.registration.success',
            self::ACTION_FAILED       => 'icon.registration.failed',
            self::ACTION_COMPLETED    => 'icon.registration.completed',
            self::ACTION_UNREGISTERED => 'icon.registration.unregistered',
            default                   => 'icon.registration.unknown',
        };
    }

    /**
     * Convert event to array for logging
     */
    public function toArray(): array
    {
        $base = [
            'event'        => $this->getEventName(),
            'action'       => $this->action,
            'registrar_id' => $this->registrarId,
            'name'         => $this->name,
            'timestamp'    => $this->timestamp->format('Y-m-d H:i:s.u'),
            'context'      => $this->context,
        ];

        // Add action-specific data
        $actionData = match ($this->action) {
            self::ACTION_STARTED => [
                'total_icon_sets' => $this->getTotalIconSets(),
                'mode'            => $this->getMode(),
            ],
            self::ACTION_PROCESSING => [
                'position' => $this->getPosition(),
                'total'    => $this->getTotal(),
                'progress' => round($this->getProgress(), 2) . '%',
            ],
            self::ACTION_REGISTERED => [
                'duration'   => round($this->getDuration(), 2) . 'ms',
                'icon_count' => $this->getIconCount(),
            ],
            self::ACTION_FAILED => [
                'error_message' => $this->getErrorMessage(),
                'error_type'    => $this->getErrorType(),
                'error_file'    => $this->metadata['error_file'] ?? null,
                'error_line'    => $this->metadata['error_line'] ?? null,
            ],
            self::ACTION_COMPLETED => [
                'statistics'     => $this->getStatistics(),
                'duration'       => round($this->getDuration(), 2) . 'ms',
                'was_successful' => $this->wasSuccessful(),
            ],
            self::ACTION_UNREGISTERED => [
                'package' => $this->name,
            ],
            default => [],
        };

        return array_merge($base, $actionData);
    }
}
