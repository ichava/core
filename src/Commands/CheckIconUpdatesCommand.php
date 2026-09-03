<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

use Simtabi\Laranail\Ichava\Services\IconPackUpdateChecker;

/**
 * Reports which registered icon packs are behind their upstream source.
 *
 * Reads each pack's `upstream` block from its config.json, hits the
 * declared version_check_url (typically a GitHub releases endpoint),
 * and prints a table. Exits non-zero when any pack is stale so this
 * command can be wired into CI / scheduled tasks.
 *
 * @example
 *   php artisan ichava:icons:check-updates
 *   php artisan ichava:icons:check-updates --package=ichava/twemoji-icons
 *   php artisan ichava:icons:check-updates --quiet --format=json
 */
final class CheckIconUpdatesCommand extends BaseCommand
{
    protected $signature = 'ichava:icons:check-updates
                            {--package= : Check just this package (vendor/name)}
                            {--format=table : Output format: table|json}
                            {--fail-on-stale : Exit non-zero when any pack is behind}';

    protected $description = 'Check whether any registered icon pack is behind its upstream source';

    public function handle(): int
    {
        // Resolve the checker at handle() time -- not in the constructor --
        // so test doubles bound via $this->app->instance() AFTER provider
        // boot still take effect. The Command itself is cached by Artisan
        // and would otherwise capture whatever singleton was first resolved.
        $checker = $this->laravel->make(IconPackUpdateChecker::class);

        $packageFilter = $this->option('package');
        $format = $this->option('format');
        $failOnStale = (bool) $this->option('fail-on-stale');

        intro('🔍 Checking icon-pack upstream sources');

        // Spin while checkAll() does its HTTP round-trips per pack.
        // For json / non-interactive runs the spinner falls back to a
        // silent execution -- Laravel Prompts handles that automatically.
        $results = $format === 'json'
            ? $checker->checkAll($packageFilter)
            : spin(
                fn () => $checker->checkAll($packageFilter),
                'Polling upstream sources (12h cache on hit)…',
            );

        if (empty($results)) {
            note('No registered packs to check.');

            return self::SUCCESS;
        }

        if ($format === 'json') {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            // Only render the Source column when at least one row has a
            // non-primary source -- otherwise it's noise for the common
            // single-source case.
            $hasSecondary = array_filter(
                $results,
                static fn (array $r): bool => ($r['source'] ?? 'primary') !== 'primary',
            ) !== [];

            if ($hasSecondary) {
                $rows = array_map(static fn (array $r): array => [
                    $r['package'],
                    $r['source'] ?? 'primary',
                    self::statusBadge($r['status']),
                    $r['current'] ?? '-',
                    $r['latest'] ?? '-',
                    $r['reason'] ?? '-',
                ], $results);

                table(
                    headers: ['Package', 'Source', 'Status', 'Current', 'Latest', 'Notes'],
                    rows: $rows,
                );
            } else {
                $rows = array_map(static fn (array $r): array => [
                    $r['package'],
                    self::statusBadge($r['status']),
                    $r['current'] ?? '-',
                    $r['latest'] ?? '-',
                    $r['reason'] ?? '-',
                ], $results);

                table(
                    headers: ['Package', 'Status', 'Current', 'Latest', 'Notes'],
                    rows: $rows,
                );
            }
        }

        $stale = array_filter($results, static fn (array $r): bool => $r['status'] === 'update-available');
        $unreachable = array_filter($results, static fn (array $r): bool => in_array($r['status'], ['unreachable', 'error'], true));

        if (! empty($stale)) {
            outro(sprintf('⚠️  %d pack(s) behind upstream', count($stale)));
        } elseif (! empty($unreachable)) {
            outro(sprintf('⚠️  %d pack(s) unreachable; rest up to date', count($unreachable)));
        } else {
            outro('✅ All packs up to date');
        }

        if ($failOnStale && (! empty($stale) || ! empty($unreachable))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected static function statusBadge(string $status): string
    {
        return match ($status) {
            'up-to-date'       => '<fg=green>up-to-date</fg=green>',
            'update-available' => '<fg=yellow>update-available</fg=yellow>',
            'unreachable'      => '<fg=red>unreachable</fg=red>',
            'no-upstream'      => '<fg=gray>no-upstream</fg=gray>',
            default            => "<fg=red>{$status}</fg=red>",
        };
    }
}
