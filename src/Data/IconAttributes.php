<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

final readonly class IconAttributes
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public array $attributes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function merge(array $attributes): self
    {
        return new self(array_merge($this->attributes, $attributes));
    }
}
