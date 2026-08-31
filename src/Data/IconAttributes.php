<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

final readonly class IconAttributes
{
    public function __construct(
        public array $attributes,
    ) {}

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function merge(array $attributes): self
    {
        return new self(array_merge($this->attributes, $attributes));
    }
}
