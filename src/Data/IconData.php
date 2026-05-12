<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

final readonly class IconData
{
    public function __construct(
        public string $name,
        public string $path,
        public ?string $variant,
        public ?string $category,
        public string $set,
    ) {}
}
