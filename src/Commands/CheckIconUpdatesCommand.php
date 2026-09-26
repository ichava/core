<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

use Simtabi\Laranail\Console\Tools\Support\Status;
use Simtabi\Laranail\Console\Tools\Widgets\StatusBadge;
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
 *   php artisan ichava::ichava-core.check-updates
 *   php artisan ichava::ichava-core.check-updates --package=ichava/twemoji-icons
 *   php artisan ichava::ichava-core.check-updates --quiet --format=json
 */
final class CheckIconUpdatesCommand extends BaseCommand
{
    /**
     * The checker's states on the shared status vocabulary. The state itself
     * stays the label: it is what `--format=json` prints and what a user
     * searches the output for.
     *
     * @var array<string, Status>
     */
    private const array UPDATE_STATUS_MAP = [
        'up-to-date'       => Status::Success,
        'update-available' => Status::Warning,
        'unreachable'      => Status::Failed,
        'error'            => Status::Failed,
        'no-upstream'      => Status::Skipped,
    ];

    /*
     * Named `.check-updates`, not `.icons:check-updates`.
     *
     * The `:` inside a command segment is the sub-command separator -- it is how
     * `laranail::db-console.webhook:list` groups a family. It earns its place when
     * there is a family to group. Here `icons:` grouped exactly one command, in a
     * package whose entire subject is icons, so it said nothing twice. The three
     * -segment original predates the namespacing and its siblings never had it.
     */
    protected $signature = 'ichava::ichava-core.check-updates
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

        // Under --format=json stdout is the JSON document and nothing else --
        // no intro, spinner, note or outro -- so it can be piped straight
        // into a parser. The exit code still carries --fail-on-stale.
        $json = $format === 'json';

        if (! $json) {
            intro(__('ichava/ichava-core::commands.check_updates.intro'));
        }

        // Spin while checkAll() does its HTTP round-trips per pack.
        $results = $json
            ? $checker->checkAll($packageFilter)
            : spin(
                fn () => $checker->checkAll($packageFilter),
                __('ichava/ichava-core::commands.check_updates.polling'),
            );

        if ($json) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif (empty($results)) {
            note(__('ichava/ichava-core::commands.check_updates.none'));

            return self::SUCCESS;
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
                    headers: [__('ichava/ichava-core::commands.check_updates.table.package'), __('ichava/ichava-core::commands.check_updates.table.source'), __('ichava/ichava-core::commands.check_updates.table.status'), __('ichava/ichava-core::commands.check_updates.table.current'), __('ichava/ichava-core::commands.check_updates.table.latest'), __('ichava/ichava-core::commands.check_updates.table.notes')],
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
                    headers: [__('ichava/ichava-core::commands.check_updates.table.package'), __('ichava/ichava-core::commands.check_updates.table.status'), __('ichava/ichava-core::commands.check_updates.table.current'), __('ichava/ichava-core::commands.check_updates.table.latest'), __('ichava/ichava-core::commands.check_updates.table.notes')],
                    rows: $rows,
                );
            }
        }

        $stale = array_filter($results, static fn (array $r): bool => $r['status'] === 'update-available');
        $unreachable = array_filter($results, static fn (array $r): bool => in_array($r['status'], ['unreachable', 'error'], true));

        if (! $json) {
            outro(match (true) {
                $stale !== []       => __('ichava/ichava-core::commands.check_updates.behind', ['count' => count($stale)]),
                $unreachable !== [] => __('ichava/ichava-core::commands.check_updates.unreachable', ['count' => count($unreachable)]),
                default             => __('ichava/ichava-core::commands.check_updates.up_to_date'),
            });
        }

        if ($failOnStale && (! empty($stale) || ! empty($unreachable))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected static function statusBadge(string $status): string
    {
        return StatusBadge::fromMap(self::UPDATE_STATUS_MAP, $status)->label($status)->render();
    }
}
