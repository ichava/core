<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

final readonly class IconMetadata
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $author = null,
        public ?string $license = null,
        public ?string $version = null,
        public array $tags = [],
    ) {}
}
