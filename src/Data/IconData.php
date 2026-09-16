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

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            path: (string) ($data['path'] ?? ''),
            variant: isset($data['variant']) ? (string) $data['variant'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            set: (string) ($data['set'] ?? ''),
        );
    }

    /**
     * @return array{name: string, path: string, variant: string|null, category: string|null, set: string}
     */
    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'path'     => $this->path,
            'variant'  => $this->variant,
            'category' => $this->category,
            'set'      => $this->set,
        ];
    }
}
