<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Throwable;
use RuntimeException;
use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Models\Icon;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Simtabi\Laranail\Ichava\Support\Coerce;
use Simtabi\Laranail\Ichava\Enums\KindSource;
use Simtabi\Laranail\Ichava\Support\KindGlob;
use Simtabi\Laranail\Ichava\Support\KindResolver;
use Simtabi\Laranail\Ichava\Support\KindHeuristic;
use Simtabi\Laranail\Ichava\Support\IconClassifier;

/**
 * Doctor's kind checks (contract §7).
 *
 * Each check is `{id, status, count, detail}` with status `ok`, `warn` or
 * `fail`. None of these fails doctor: a kind mistake degrades to the next
 * row of the resolution order, never to a broken install.
 *
 * Reads what the registry stored at registration and the seeded rows. Only
 * `kind.suggestions`, off unless asked for, reads every SVG on disk.
 */
final readonly class KindDoctor
{
    /** The longest list of rows `kind.drift` reports per package. */
    private const MAX_DRIFT_SAMPLES = 20;

    public function __construct(
        private IconRegistry $registry,
        private KindResolver $resolver,
        private KindHeuristic $heuristic,
    ) {}

    /**
     * Every kind check, in report order.
     *
     * @param string|null $suggest `default` or `all` to run the heuristic over every SVG; null to skip it
     *
     * @return list<array{id: string, status: string, count: int, detail: list<mixed>}>
     */
    public function checks(?string $suggest = null): array
    {
        $rows = $this->scanRows();

        $checks = [
            $this->invalidValues(),
            $this->invalidGlobs(),
            $this->invalidOverrides(),
            $rows['rule_unused'],
            $this->missingItems(),
            $rows['drift'],
            $rows['non_default'],
        ];

        if ($suggest !== null) {
            $checks[] = $this->suggestions($suggest === 'all');
        }

        return $checks;
    }

    /**
     * @param list<mixed> $detail
     *
     * @return array{id: string, status: string, count: int, detail: list<mixed>}
     */
    private static function check(string $id, int $count, array $detail, bool $informational = false): array
    {
        return [
            'id'     => $id,
            'status' => $count === 0 || $informational ? 'ok' : 'warn',
            'count'  => $count,
            'detail' => $detail,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<array{key: string, value: mixed, index: int|null, pattern: string}>
     */
    private static function issues(array $metadata, string $check): array
    {
        $issues = [];

        foreach (is_array($metadata['kind_issues'] ?? null) ? $metadata['kind_issues'] : [] as $issue) {
            if (is_array($issue) && ($issue['check'] ?? null) === $check) {
                $issues[] = [
                    'key'     => Coerce::string($issue['key'] ?? ''),
                    'value'   => $issue['value'] ?? null,
                    'index'   => is_int($issue['index'] ?? null) ? $issue['index'] : null,
                    'pattern' => Coerce::string($issue['pattern'] ?? ''),
                ];
            }
        }

        return $issues;
    }

    /** @return array{id: string, status: string, count: int, detail: list<mixed>} */
    private function invalidValues(): array
    {
        $detail = [];

        foreach ($this->registry->all() as $package => $metadata) {
            foreach (self::issues($metadata, 'kind.invalid_value') as $issue) {
                $detail[] = ['pack' => $package, 'key' => $issue['key'], 'value' => $issue['value']];
            }
        }

        foreach ($this->resolver->overrides() as $override) {
            if ($override['kind'] === null) {
                $detail[] = [
                    'pack'  => $override['package'],
                    'key'   => 'ichava.ichava-core.kinds.overrides.' . $override['key'],
                    'value' => $override['value'],
                ];
            }
        }

        return self::check('kind.invalid_value', count($detail), $detail);
    }

    /** @return array{id: string, status: string, count: int, detail: list<mixed>} */
    private function invalidGlobs(): array
    {
        $detail = [];

        foreach ($this->registry->all() as $package => $metadata) {
            foreach (self::issues($metadata, 'kind.invalid_glob') as $issue) {
                $detail[] = ['pack' => $package, 'index' => $issue['index'], 'pattern' => $issue['pattern']];
            }
        }

        return self::check('kind.invalid_glob', count($detail), $detail);
    }

    /** @return array{id: string, status: string, count: int, detail: list<mixed>} */
    private function invalidOverrides(): array
    {
        $detail = array_map(static fn (string $key): array => ['key' => $key], $this->resolver->invalidOverrideKeys());

        return self::check('kind.override_invalid', count($detail), $detail);
    }

    /** @return array{id: string, status: string, count: int, detail: list<mixed>} */
    private function missingItems(): array
    {
        $detail = [];

        foreach ($this->registry->all() as $package => $metadata) {
            $base = is_string($metadata['base_path'] ?? null) ? rtrim($metadata['base_path'], '/') : null;
            $items = is_array($metadata['item_kinds'] ?? null) ? $metadata['item_kinds'] : [];

            foreach (array_keys($items) as $item) {
                if ($base === null || ! File::exists("{$base}/files/{$item}.svg")) {
                    $detail[] = ['pack' => $package, 'item' => (string) $item];
                }
            }
        }

        return self::check('kind.item_missing', count($detail), $detail);
    }

    /**
     * One pass over the seeded rows: unused rules and overrides, drift
     * against a fresh resolution, and the non-default kinds.
     *
     * A theme twin is not a row, so a rule that matches only twins counts as
     * unused, which is what it is.
     *
     * @return array{rule_unused: array{id: string, status: string, count: int, detail: list<mixed>}, drift: array{id: string, status: string, count: int, detail: list<mixed>}, non_default: array{id: string, status: string, count: int, detail: list<mixed>}}
     */
    private function scanRows(): array
    {
        $packages = $this->registry->all();
        $rules = [];

        foreach ($packages as $package => $metadata) {
            foreach (is_array($metadata['kind_rules'] ?? null) ? $metadata['kind_rules'] : [] as $rule) {
                if (is_array($rule) && is_string($rule['match'] ?? null)) {
                    $rules[] = ['pack' => (string) $package, 'pattern' => $rule['match'], 'match' => $rule['match'], 'source' => KindSource::Rule->value, 'used' => false];
                }
            }
        }

        foreach ($this->resolver->overrides() as $override) {
            if ($override['kind'] !== null) {
                $rules[] = ['pack' => $override['package'], 'pattern' => $override['key'], 'match' => $override['pattern'], 'source' => KindSource::Host->value, 'used' => false];
            }
        }

        $drift = [];
        $driftCount = 0;
        $nonDefault = [];

        try {
            if (! config('ichava.ichava-core.database.enabled', true)) {
                throw new RuntimeException('database disabled');
            }

            foreach (Icon::query()->select(['id', 'package', 'path', 'kind', 'kind_source', 'kind_rule'])->lazyById(1000) as $icon) {
                $package = (string) $icon->package;
                $item = KindResolver::itemPath((string) $icon->path);

                foreach ($rules as $index => $rule) {
                    if (! $rule['used'] && ($rule['pack'] === '*' || $rule['pack'] === $package) && KindGlob::matches($rule['match'], $item)) {
                        $rules[$index]['used'] = true;
                    }
                }

                $fresh = $this->resolver->resolve($package, $item);
                $stored = $icon->kind instanceof IconKind ? $icon->kind : IconKind::Icon;

                if (! $fresh->matches($stored, $icon->kindSource(), $icon->kind_rule)) {
                    $driftCount++;
                    $drift[$package] ??= ['package' => $package, 'rows' => 0, 'command' => "php artisan ichava::ichava-core.database seed --package={$package}", 'samples' => []];
                    $drift[$package]['rows']++;

                    if (count($drift[$package]['samples']) < self::MAX_DRIFT_SAMPLES) {
                        $drift[$package]['samples'][] = [
                            'path'     => $item,
                            'stored'   => ['kind' => $stored->value, 'source' => $icon->kindSource()->value, 'rule' => $icon->kind_rule],
                            'resolved' => ['kind' => $fresh->kind->value, 'source' => $fresh->source->value, 'rule' => $fresh->rule],
                        ];
                    }
                }

                if ($stored !== IconKind::Icon) {
                    $nonDefault[] = ['package' => $package, 'path' => $item, 'kind' => $stored->value, 'source' => $icon->kindSource()->value, 'rule' => $icon->kind_rule];
                }
            }
        } catch (Throwable) {
            // No database, or no table yet: nothing is seeded, so there is
            // nothing to compare. Reported as zero rather than guessed.
            $rules = array_map(static fn (array $rule): array => ['used' => true] + $rule, $rules);
        }

        $unused = array_values(array_map(
            static fn (array $rule): array => ['pack' => $rule['pack'], 'pattern' => $rule['pattern'], 'source' => $rule['source']],
            array_filter($rules, static fn (array $rule): bool => ! $rule['used']),
        ));

        return [
            'rule_unused' => self::check('kind.rule_unused', count($unused), $unused),
            'drift'       => self::check('kind.drift', $driftCount, array_values($drift)),
            'non_default' => self::check('kind.non_default', count($nonDefault), $nonDefault, informational: true),
        ];
    }

    /**
     * Row 7 over every SVG of every registered pack. Theme twins and sets
     * another pack took over are skipped, as the seeder skips them.
     *
     * @return array{id: string, status: string, count: int, detail: list<mixed>}
     */
    private function suggestions(bool $includePack): array
    {
        $classifier = new IconClassifier($this->registry, $this->resolver);
        $records = [];

        foreach ($this->registry->all() as $package => $metadata) {
            $base = is_string($metadata['base_path'] ?? null) ? rtrim($metadata['base_path'], '/') : null;

            if ($base === null || ! File::isDirectory("{$base}/files")) {
                continue;
            }

            $items = [];

            foreach (File::allFiles("{$base}/files") as $file) {
                if (strtolower($file->getExtension()) !== 'svg' || $file->isLink()) {
                    continue;
                }

                $relative = 'files/' . str_replace('\\', '/', $file->getRelativePathname());
                $item = KindResolver::itemPath($relative);

                if ($classifier->twinOf((string) $package, $base, $relative) !== null) {
                    continue;
                }

                $resolution = $this->resolver->resolve((string) $package, $item);

                if ($file->getSize() > $resolution->kind->maxFileSize()) {
                    continue;
                }

                $items[$item] = ['resolution' => $resolution, 'svg' => (string) file_get_contents($file->getPathname())];
            }

            ksort($items);
            array_push($records, ...$this->heuristic->suggestions((string) $package, $items, $includePack));
        }

        return self::check('kind.suggestions', count($records), $records, informational: true);
    }
}
