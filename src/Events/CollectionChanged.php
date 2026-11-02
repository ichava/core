<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One of the current user's collections changed.
 *
 * `action` is one of the class constants; `iconId` is set for the two icon
 * actions only.
 */
final readonly class CollectionChanged
{
    use Dispatchable;

    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string DELETED = 'deleted';

    public const string ICON_ADDED = 'icon_added';

    public const string ICON_REMOVED = 'icon_removed';

    public function __construct(
        public string $action,
        public string $collectionId,
        public ?int $iconId,
        public bool $persisted,
    ) {}
}
