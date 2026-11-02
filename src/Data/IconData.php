<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

use Stringable;
use InvalidArgumentException;

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
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: self::stringField($data['name'] ?? ''),
            path: self::stringField($data['path'] ?? ''),
            variant: isset($data['variant']) ? self::stringField($data['variant']) : null,
            category: isset($data['category']) ? self::stringField($data['category']) : null,
            set: self::stringField($data['set'] ?? ''),
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

    /**
     * The `(string)` cast this replaces, minus its silent cases: a scalar or
     * Stringable converts as before, anything else is rejected rather than
     * turned into "Array" -- callers treat the throw as a malformed payload.
     */
    private static function stringField(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException('IconData field must be a string, got ' . get_debug_type($value) . '.');
    }
}
