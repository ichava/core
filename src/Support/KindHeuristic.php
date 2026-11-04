<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Enums\KindSource;
use Simtabi\Laranail\Ichava\Data\KindResolution;
use Simtabi\Laranail\Ichava\Constants\IchavaConstants;

/**
 * D6 row 7: signals that an SVG resolved as an icon may be an illustration.
 *
 * It suggests and never applies. No code path writes a suggestion into a
 * pack's config.json, the database or a cache: a silent misclassification
 * could hide an icon or publish a private illustration, so a human accepts a
 * suggestion into row 2 (`items`) or row 3 (`kinds.rules`).
 *
 * Signals, with the thresholds in {@see IchavaConstants} (`KIND_HEURISTIC_*`,
 * identical in icon-sets-sync's `internal/core/kinds`):
 *
 * - `aspect`   viewBox width / height outside [0.8, 1.25];
 * - `palette`  4 or more distinct literal paint colours;
 * - `bytes`    more than 20,000 bytes;
 * - `elements` more than 150 elements;
 * - `canvas`   the larger viewBox side above 256.
 *
 * An item is suggested when 3 or more fire, at least one of them `bytes` or
 * `elements` (which keeps flags out: large, multicolour canvases, but small),
 * and it resolved `icon` from the default row -- or the pack row too, when
 * asked. Host, item, rule and category kinds are human decisions and are
 * never second-guessed.
 */
final readonly class KindHeuristic
{
    /** Signal ids, in report order. */
    public const SIGNALS = ['aspect', 'palette', 'bytes', 'elements', 'canvas'];

    /** Paint values that name no literal colour. */
    private const NOT_COLOURS = ['none', 'transparent', 'currentcolor', 'inherit', 'initial', 'unset', 'context-fill', 'context-stroke'];

    /**
     * The signals that fire for one SVG, in report order.
     *
     * @return list<array{id: string, value: int|float}>
     */
    public function signals(string $svg): array
    {
        $fired = [];
        [$width, $height] = self::viewBox($svg);

        if ($width > 0 && $height > 0) {
            $aspect = $width / $height;

            if ($aspect < IchavaConstants::KIND_HEURISTIC_ASPECT_MIN || $aspect > IchavaConstants::KIND_HEURISTIC_ASPECT_MAX) {
                $fired['aspect'] = round($aspect, 2);
            }
        }

        $palette = count(self::colours($svg));

        if ($palette >= IchavaConstants::KIND_HEURISTIC_PALETTE_MIN) {
            $fired['palette'] = $palette;
        }

        $bytes = strlen($svg);

        if ($bytes > IchavaConstants::KIND_HEURISTIC_MAX_BYTES) {
            $fired['bytes'] = $bytes;
        }

        $elements = preg_match_all('/<[A-Za-z]/', $svg);

        if ($elements > IchavaConstants::KIND_HEURISTIC_MAX_ELEMENTS) {
            $fired['elements'] = $elements;
        }

        $canvas = max($width, $height);

        if ($canvas > IchavaConstants::KIND_HEURISTIC_MAX_CANVAS) {
            $fired['canvas'] = $canvas == (int) $canvas ? (int) $canvas : $canvas;
        }

        $signals = [];

        foreach (self::SIGNALS as $id) {
            if (isset($fired[$id])) {
                $signals[] = ['id' => $id, 'value' => $fired[$id]];
            }
        }

        return $signals;
    }

    /**
     * Whether these signals suggest `illustration` for an item that resolved
     * as it did.
     *
     * @param list<array{id: string, value: int|float}> $signals
     */
    public function suggests(array $signals, KindResolution $current, bool $includePack): bool
    {
        if ($current->kind !== IconKind::Icon) {
            return false;
        }

        if ($current->source !== KindSource::Default && ! ($includePack && $current->source === KindSource::Pack)) {
            return false;
        }

        $ids = array_column($signals, 'id');

        return count($ids) >= IchavaConstants::KIND_HEURISTIC_MIN_SIGNALS
            && (in_array('bytes', $ids, true) || in_array('elements', $ids, true));
    }

    /**
     * Suggestion records for one pack, grouped where a folder is mostly
     * suggested, sorted by path.
     *
     * A folder with at least 5 direct items, 80% or more of them suggested,
     * becomes one rule suggestion (`<folder>/**`) in place of its per-item
     * records.
     *
     * @param array<string, array{resolution: KindResolution, svg: string}> $items item path => item
     *
     * @return list<array<string, mixed>>
     */
    public function suggestions(string $package, array $items, bool $includePack): array
    {
        $suggested = [];
        $perFolder = [];

        foreach ($items as $path => $item) {
            $path = (string) $path;
            $folder = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
            $perFolder[$folder] = ($perFolder[$folder] ?? 0) + 1;
            $signals = $this->signals($item['svg']);

            if ($this->suggests($signals, $item['resolution'], $includePack)) {
                $suggested[$folder][$path] = ['resolution' => $item['resolution'], 'signals' => $signals];
            }
        }

        $records = [];

        foreach ($suggested as $folder => $paths) {
            $total = $perFolder[$folder];

            if ($folder !== ''
                && $total >= IchavaConstants::KIND_HEURISTIC_GROUP_MIN_ITEMS
                && count($paths) / $total >= IchavaConstants::KIND_HEURISTIC_GROUP_MIN_SHARE) {
                $records[] = $this->groupRecord($package, (string) $folder, $paths);

                continue;
            }

            foreach ($paths as $path => $entry) {
                $records[] = $this->record($package, (string) $path, $entry['resolution'], $entry['signals']);
            }
        }

        usort($records, static fn (array $a, array $b): int => strcmp(Coerce::string($a['path'] ?? ''), Coerce::string($b['path'] ?? '')));

        return $records;
    }

    /**
     * One item's record, in the shape icon-sets-sync writes too.
     *
     * @param list<array{id: string, value: int|float}> $signals
     *
     * @return array<string, mixed>
     */
    public function record(string $package, string $path, KindResolution $current, array $signals): array
    {
        return [
            'package'   => $package,
            'path'      => $path,
            'current'   => ['kind' => $current->kind->value, 'source' => $current->source->value],
            'suggested' => IconKind::Illustration->value,
            'signals'   => $signals,
            'accept'    => ['items' => [$path => ['kind' => IconKind::Illustration->value]]],
        ];
    }

    /**
     * The viewBox width and height, or zeros when there is none.
     *
     * @return array{float, float}
     */
    private static function viewBox(string $svg): array
    {
        $number = '([-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)';

        if (preg_match('/\bviewBox\s*=\s*["\']\s*' . $number . '[\s,]+' . $number . '[\s,]+' . $number . '[\s,]+' . $number . '/i', $svg, $m) !== 1) {
            return [0.0, 0.0];
        }

        return [(float) $m[3], (float) $m[4]];
    }

    /**
     * Distinct literal paint colours from `fill`, `stroke` and `stop-color`,
     * as attributes or style properties: hex normalised to lowercase hex6,
     * `rgb()` converted to hex6, names kept lowercased.
     *
     * @return list<string>
     */
    private static function colours(string $svg): array
    {
        preg_match_all('/(?<![\w-])(?:fill|stroke|stop-color)\s*(?:=\s*["\']|:\s*)([^"\';}]+)/i', $svg, $matches);

        $colours = [];

        foreach ($matches[1] as $raw) {
            $colour = self::normaliseColour(strtolower(trim($raw)));

            if ($colour !== null) {
                $colours[$colour] = true;
            }
        }

        return array_keys($colours);
    }

    private static function normaliseColour(string $value): ?string
    {
        if ($value === '' || in_array($value, self::NOT_COLOURS, true) || str_starts_with($value, 'url(')) {
            return null;
        }

        if (preg_match('/^#([0-9a-f]{3,8})$/', $value, $m) === 1) {
            $hex = $m[1];

            return match (strlen($hex)) {
                3, 4    => '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2],
                6, 8    => '#' . substr($hex, 0, 6),
                default => null,
            };
        }

        if (preg_match('/^rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})/', $value, $m) === 1) {
            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }

        return preg_match('/^[a-z]+$/', $value) === 1 ? $value : null;
    }

    /**
     * @param array<string, array{resolution: KindResolution, signals: list<array{id: string, value: int|float}>}> $paths
     *
     * @return array<string, mixed>
     */
    private function groupRecord(string $package, string $folder, array $paths): array
    {
        $fired = [];
        $sources = [];

        foreach ($paths as $entry) {
            $sources[$entry['resolution']->source->value] = ($sources[$entry['resolution']->source->value] ?? 0) + 1;

            foreach ($entry['signals'] as $signal) {
                $fired[$signal['id']] = ($fired[$signal['id']] ?? 0) + 1;
            }
        }

        arsort($sources);
        $signals = [];

        // For a group, each signal's value is how many of its items fired it.
        foreach (self::SIGNALS as $id) {
            if (isset($fired[$id])) {
                $signals[] = ['id' => $id, 'value' => $fired[$id]];
            }
        }

        return [
            'package'   => $package,
            'path'      => $folder,
            'current'   => ['kind' => IconKind::Icon->value, 'source' => (string) array_key_first($sources)],
            'suggested' => IconKind::Illustration->value,
            'signals'   => $signals,
            'accept'    => ['kinds' => ['rules' => [['match' => $folder . '/**', 'kind' => IconKind::Illustration->value]]]],
        ];
    }
}
