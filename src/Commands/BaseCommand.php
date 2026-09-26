<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Commands;

use Closure;
use Exception;
use RuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\error;
use function Laravel\Prompts\select;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Ichava\Support\CommandName;
use Simtabi\Laranail\Console\Tools\Support\Status;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Console\Tools\Support\TimeFormat;
use Simtabi\Laranail\Console\Tools\Widgets\MetricTable;
use Simtabi\Laranail\Console\Tools\Widgets\StatusBadge;
use Simtabi\Laranail\Console\Tools\Support\ExceptionRenderer;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\ConfirmsDestructiveActions;

/**
 * Base command for all Ichava Artisan commands.
 *
 * A thin adapter over the laranail/console command base. Presentation --
 * status labels, byte and time formatting, checklists, metric tables, error
 * rendering, destructive-action confirmation -- lives in that package's
 * widgets and support classes, so it is defined once for every command in the
 * family. What stays here is what is specific to Ichava: its tables, its
 * invalid-argument recovery and the handful of helpers the commands share.
 *
 * Helpers nothing called were parked in `.parked/Commands/` on 2026-09-26.
 */
abstract class BaseCommand extends Command
{
    /*
     * `--force` answers yes without prompting; declining returns SUCCESS
     * through cancelled(). One implementation for every destructive action.
     */
    use ConfirmsDestructiveActions;

    /*
     * Symfony's validateName() rejects the empty segment in `::`, so a command
     * named `ichava::ichava-core.cache` cannot be registered through the normal
     * path. This trait writes the name past that validator. Dispatch still works
     * because Symfony resolves an exact name before its `:`-splitting namespace
     * lookup. Same mechanism `laranail/db-console` uses.
     */
    use SupportsNamespacedNames;

    /**
     * Job and package states, mapped onto the shared status vocabulary. The
     * domain strings stay here; the glyph, colour and label come from Status.
     *
     * @var array<string, Status>
     */
    protected const array STATUS_MAP = [
        'processing'  => Status::Running,
        'running'     => Status::Running,
        'in_progress' => Status::Running,
        'completed'   => Status::Success,
        'done'        => Status::Success,
        'success'     => Status::Success,
        'failed'      => Status::Failed,
        'error'       => Status::Failed,
        'pending'     => Status::Pending,
        'queued'      => Status::Pending,
        'skipped'     => Status::Skipped,
        'active'      => Status::Active,
        'inactive'    => Status::Inactive,
    ];

    /**
     * Start time for performance tracking
     */
    protected float $startTime;

    /**
     * Ichava table names for existence checks
     */
    protected array $ichavaTables = [
        'ichava_icons',
        'ichava_icon_terms',
        'ichava_icon_termables',
    ];

    /**
     * Start timing for performance tracking
     */
    protected function startTiming(): void
    {
        $this->startTime = microtime(true);
    }

    /**
     * Get elapsed time in milliseconds
     */
    protected function getElapsedMs(): float
    {
        return (microtime(true) - $this->startTime) * 1000;
    }

    /**
     * Display elapsed time since startTiming() was called
     */
    protected function displayElapsedTime(): void
    {
        info(__('ichava/ichava-core::commands.common.completed_in', ['time' => TimeFormat::fromMillis($this->getElapsedMs())]));
    }

    /**
     * Format a number with thousands separator
     */
    protected function formatNumber(int|float $number): string
    {
        return number_format($number);
    }

    /**
     * Display a success message using Laravel Prompts
     */
    protected function success(string $message): void
    {
        info(Status::Success->symbol() . " {$message}");
    }

    /**
     * Display a failure message using Laravel Prompts
     */
    protected function failure(string $message): void
    {
        error(Status::Failed->symbol() . " {$message}");
    }

    /**
     * Display a tip/hint message using Laravel Prompts
     */
    protected function tip(string $message): void
    {
        note(__('ichava/ichava-core::commands.common.tip', ['message' => $message]));
    }

    /**
     * One indented detail line under a heading.
     */
    protected function detail(string $text): void
    {
        $this->line(str_repeat(' ', 2) . $text);
    }

    /**
     * A coloured status label for a job or package state.
     */
    protected function formatStatus(string $status): string
    {
        return StatusBadge::fromMap(self::STATUS_MAP, Str::lower($status))->render();
    }

    /**
     * The icon-database statistics table `database stats` and `info stats`
     * both print, built in one place so the two cannot drift apart. A row is
     * shown when its statistic is present in `$stats`; the database size is
     * PostgreSQL-only and reads N/A elsewhere.
     *
     * @param array<string, mixed> $stats
     */
    protected function statisticsTable(array $stats): MetricTable
    {
        $rows = [
            'icons'              => __('ichava/ichava-core::commands.common.stats.icons'),
            'packages'           => __('ichava/ichava-core::commands.common.stats.packages'),
            'categories'         => __('ichava/ichava-core::commands.common.stats.categories'),
            'variants'           => __('ichava/ichava-core::commands.common.stats.variants'),
            'term_relationships' => __('ichava/ichava-core::commands.common.stats.term_relationships'),
            'database_size'      => __('ichava/ichava-core::commands.common.stats.database_size'),
            'cache_driver'       => __('ichava/ichava-core::commands.common.stats.cache_driver'),
        ];

        $table = MetricTable::make();

        foreach ($rows as $key => $label) {
            if (! array_key_exists($key, $stats)) {
                continue;
            }

            $value = $stats[$key];

            $table->metric($label, match (true) {
                is_int($value)  => $value,
                $value === null => __('ichava/ichava-core::commands.common.not_available'),
                default         => (string) $value,
            });
        }

        return $table;
    }

    /**
     * Ask for text input using Laravel Prompts
     */
    protected function askText(
        string $label,
        string $placeholder = '',
        string $default = '',
        bool $required = false,
        ?string $hint = null,
        ?Closure $validate = null,
    ): string {
        return text(
            label: $label,
            placeholder: $placeholder,
            default: $default,
            required: $required,
            hint: $hint,
            validate: $validate,
        );
    }

    /**
     * Handle invalid action argument using Laravel Prompts
     */
    protected function handleInvalidAction(string $action, array $validActions): int
    {
        error(__('ichava/ichava-core::commands.common.invalid_action', ['action' => $action]));
        note(__('ichava/ichava-core::commands.common.valid_actions', ['actions' => implode(', ', $validActions)]));

        return $this->offerValidArgument(
            'action',
            $validActions,
            __('ichava/ichava-core::commands.common.select_action'),
            __('ichava/ichava-core::commands.common.select_action_hint'),
        );
    }

    /**
     * Handle invalid type argument using Laravel Prompts
     */
    protected function handleInvalidType(string $type, array $validTypes): int
    {
        error(__('ichava/ichava-core::commands.common.invalid_type', ['type' => $type]));
        note(__('ichava/ichava-core::commands.common.valid_types', ['types' => implode(', ', $validTypes)]));

        return $this->offerValidArgument(
            'type',
            $validTypes,
            __('ichava/ichava-core::commands.common.select_type'),
            __('ichava/ichava-core::commands.common.select_type_hint'),
        );
    }

    /**
     * Export data to JSON file
     */
    protected function exportToJson(array $data, string $filename): bool
    {
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            File::put($filename, $json);
            $this->success(__('ichava/ichava-core::commands.common.exported', ['path' => $filename]));

            return true;
        } catch (Exception $e) {
            $this->failure(__('ichava/ichava-core::commands.common.export_failed', ['error' => $e->getMessage()]));

            return false;
        }
    }

    /**
     * Export data to CSV file
     */
    protected function exportToCsv(array $data, string $filename, array $headers = []): bool
    {
        try {
            $handle = fopen($filename, 'w');

            if (! $handle) {
                throw new RuntimeException("Cannot open file: {$filename}");
            }

            // Write headers
            if (! empty($headers)) {
                fputcsv($handle, $headers);
            } elseif (! empty($data) && is_array($data[0])) {
                fputcsv($handle, array_keys($data[0]));
            }

            // Write data
            foreach ($data as $row) {
                fputcsv($handle, is_array($row) ? $row : [$row]);
            }

            fclose($handle);
            $this->success(__('ichava/ichava-core::commands.common.exported', ['path' => $filename]));

            return true;
        } catch (Exception $e) {
            $this->failure(__('ichava/ichava-core::commands.common.export_failed', ['error' => $e->getMessage()]));

            return false;
        }
    }

    /**
     * Check if all Ichava tables exist
     */
    protected function ichavaTablesExist(): bool
    {
        foreach ($this->ichavaTables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get list of missing Ichava tables
     */
    protected function getMissingIchavaTables(): array
    {
        return Arr::where($this->ichavaTables, fn ($table) => ! Schema::hasTable($table));
    }

    /**
     * Ensure Ichava tables exist, display error if not
     */
    protected function ensureIchavaTablesExist(): bool
    {
        if (! $this->ichavaTablesExist()) {
            $missing = $this->getMissingIchavaTables();
            $this->failure(__('ichava/ichava-core::commands.common.tables_missing', ['tables' => implode(', ', $missing)]));
            $this->tip(__('ichava/ichava-core::commands.common.run_migrations', ['command' => CommandName::of(DatabaseCommand::class)]));

            return false;
        }

        return true;
    }

    /**
     * Truncate a path for display
     */
    protected function truncatePath(string $path, int $maxLength = 50): string
    {
        if (strlen($path) <= $maxLength) {
            return $path;
        }

        // Try to show the end of the path (more relevant)
        return '...' . substr($path, -($maxLength - 3));
    }

    /**
     * Check if output is quiet mode
     */
    protected function isQuiet(): bool
    {
        return $this->output->isQuiet();
    }

    /**
     * Execute a callback, reporting a failure under the action's own prefix.
     *
     * The message always prints; the file and line from -v, the stack trace
     * only from -vvv. Traces can carry call arguments -- credentials handed to
     * a connector, tokens in a URL -- so ExceptionRenderer holds them back
     * below debug verbosity.
     */
    protected function tryExecute(callable $callback, ?string $failureMessage = null): int
    {
        try {
            return $callback();
        } catch (Exception $e) {
            ExceptionRenderer::make($this->output)
                ->context($failureMessage ?? __('ichava/ichava-core::commands.common.operation_failed'))
                ->render($e);

            return self::FAILURE;
        }
    }

    /**
     * Run a callback that may call another Artisan command through
     * `Artisan::call()`, and take Laravel Prompts back afterwards.
     *
     * A nested command re-points Prompts' shared output -- and its fallbacks --
     * at ITS OWN buffered output and never restores them, so everything this
     * command printed through Prompts afterwards, success outro included,
     * went into that buffer and never reached the user.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    protected function reclaimingPrompts(callable $callback): mixed
    {
        try {
            return $callback();
        } finally {
            $this->configurePrompts($this->input);
        }
    }

    /**
     * Offer the valid values for an argument, and re-run with the one chosen.
     * Quiet runs cannot answer, so they return INVALID without asking.
     *
     * @param list<string> $valid
     */
    private function offerValidArgument(string $argument, array $valid, string $label, string $hint): int
    {
        if ($this->isQuiet()) {
            return self::INVALID;
        }

        $selected = select(
            label: $label,
            options: array_merge(['cancel' => __('ichava/ichava-core::commands.common.cancel_option')], array_combine($valid, $valid)),
            default: 'cancel',
            hint: $hint,
        );

        if ($selected === 'cancel') {
            return self::INVALID;
        }

        $this->input->setArgument($argument, $selected);

        return $this->handle();
    }
}
