<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Data;

use Illuminate\Support\Str;

/**
 * One variant of a pack (tabler's `outline`, `filled`), as the browser shows
 * it in the variant picker.
 *
 * Built from the pack's `metadata.data.variants.<slug>` block, the one parser
 * for those fields: JsonConfigConstants' variant getters and the browser
 * service both read through it, so they cannot disagree. A translated label
 * or description from the pack's lang file wins over config.json's English.
 */
final readonly class VariantData
{
    /**
     * @param array<string, mixed> $attributes SVG attributes the variant is drawn with
     */
    public function __construct(
        public string $slug,
        public string $label,
        public string $description = '',
        public array $attributes = [],
        public ?string $previewIcon = null,
        public ?string $colorScheme = null,
        public int $displayOrder = 999,
        public bool $default = false,
    ) {}

    /**
     * @param array<array-key, mixed> $metadata The config.json block for this variant
     */
    public static function fromMetadata(
        string $slug,
        array $metadata,
        ?string $translatedLabel = null,
        ?string $translatedDescription = null,
    ): self {
        $attributes = [];

        foreach (is_array($metadata['attributes'] ?? null) ? $metadata['attributes'] : [] as $key => $value) {
            $attributes[(string) $key] = $value;
        }

        $order = $metadata['display_order'] ?? null;

        return new self(
            slug: $slug,
            label: $translatedLabel ?? self::string($metadata, 'name') ?? Str::ucfirst($slug),
            description: $translatedDescription ?? self::string($metadata, 'description') ?? '',
            attributes: $attributes,
            previewIcon: self::string($metadata, 'preview_icon'),
            colorScheme: self::string($metadata, 'color_scheme'),
            displayOrder: is_int($order) ? $order : 999,
            default: ($metadata['default'] ?? false) === true,
        );
    }

    /**
     * @param array<array-key, mixed> $variants slug => config.json block
     * @param array<string, string> $labels slug => translated label
     * @param array<string, string> $descriptions slug => translated description
     *
     * @return list<self> In display order, then declaration order
     */
    public static function listFromMetadata(array $variants, array $labels = [], array $descriptions = []): array
    {
        $list = [];

        foreach ($variants as $slug => $metadata) {
            $slug = (string) $slug;
            $list[] = self::fromMetadata($slug, is_array($metadata) ? $metadata : [], $labels[$slug] ?? null, $descriptions[$slug] ?? null);
        }

        // usort is stable since PHP 8, so equal orders keep declaration order.
        usort($list, static fn (self $a, self $b): int => $a->displayOrder <=> $b->displayOrder);

        return $list;
    }

    /**
     * @return array{slug: string, label: string, description: string, attributes: array<string, mixed>, previewIcon: string|null, colorScheme: string|null, displayOrder: int, default: bool}
     */
    public function toArray(): array
    {
        return [
            'slug'         => $this->slug,
            'label'        => $this->label,
            'description'  => $this->description,
            'attributes'   => $this->attributes,
            'previewIcon'  => $this->previewIcon,
            'colorScheme'  => $this->colorScheme,
            'displayOrder' => $this->displayOrder,
            'default'      => $this->default,
        ];
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private static function string(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
