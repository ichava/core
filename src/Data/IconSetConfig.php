<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

final readonly class IconSetConfig
{
    public function __construct(
        public string $name,
        public string $prefix,
        public string $basePath,
        public ?string $defaultVariant,
        public array $variants,
        public bool $supportsCategories,
        public string $defaultClass,
        public array $defaultAttributes,
        public ?string $fallback = null,
    ) {}
}
