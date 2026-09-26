<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Parked\Commands;

use Laravel\Prompts\Progress;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\error;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\progress;

/**
 * BaseCommand helpers that nothing called, moved here verbatim from
 * `src/Commands/BaseCommand.php` on 2026-09-26.
 *
 * NOT autoloaded and NOT shipped (`/.parked export-ignore`). Kept so a helper
 * can be restored from a file rather than from history, if a caller ever
 * appears. See `.parked/README.md` for the measurement behind each entry.
 *
 * The bodies are unchanged, so they still assume a BaseCommand `$this`
 * (`$this->option()`, `$this->line()`, `$this->failure()`, ...).
 */
trait BaseCommandHelpers
{
    /**
     * Display a boxed header using Laravel Prompts note
     */
    protected function displayBoxedHeader(string $title): void
    {
        note($title);
    }

    /**
     * Display a warning message using Laravel Prompts
     */
    protected function displayWarning(string $message): void
    {
        warning("⚠️  {$message}");
    }

    /**
     * Display an outro/completion message using Laravel Prompts
     */
    protected function displayOutro(string $message): void
    {
        outro($message);
    }

    /**
     * Display a table using Laravel Prompts
     */
    protected function displayTable(array $headers, array $rows): void
    {
        table($headers, $rows);
    }

    /**
     * Display key-value pairs using Laravel Prompts table
     */
    protected function displayKeyValue(string $key, mixed $value): void
    {
        $this->components->twoColumnDetail($key, (string) $value);
    }

    /**
     * Display multiple key-value pairs
     */
    protected function displayKeyValues(array $items): void
    {
        foreach ($items as $key => $value) {
            $this->displayKeyValue($key, $value);
        }
    }

    /**
     * Display a status row with icon
     */
    protected function displayStatusRow(string $label, bool $status, int $labelWidth = 15): void
    {
        $icon = $status ? '<fg=green>✓</fg=green>' : '<fg=red>✗</fg=red>';
        $statusText = $status ? '<fg=green>OK</fg=green>' : '<fg=red>NOT READY</fg=red>';
        $this->line("  {$icon} <fg=white>" . str_pad($label . ':', $labelWidth) . "</fg=white> {$statusText}");
    }

    /**
     * Confirm destructive operation using Laravel Prompts (respects --force flag)
     */
    protected function confirmDestructive(string $message = 'This will clear existing data. Continue?'): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return confirm(
            label: $message,
            default: false,
            yes: 'Yes, proceed',
            no: 'No, cancel',
            hint: 'This action cannot be undone.',
        );
    }

    /**
     * Confirm operation using Laravel Prompts with default value
     */
    protected function confirmOperation(string $message, bool $default = false): bool
    {
        if ($this->option('force')) {
            return true;
        }

        return confirm(
            label: $message,
            default: $default,
        );
    }

    /**
     * Execute a callback with a spinner using Laravel Prompts
     */
    protected function withSpinner(string $message, callable $callback): mixed
    {
        return spin(
            callback: $callback,
            message: $message,
        );
    }

    /**
     * Create a progress bar for iterating items using Laravel Prompts
     *
     * @template TKey
     * @template TValue
     *
     * @param iterable<TKey, TValue> $items
     * @param callable(TValue, Progress<TKey, TValue>): mixed $callback
     *
     * @return array<TKey, mixed>
     */
    protected function withProgress(string $label, iterable $items, callable $callback, ?string $hint = null): array
    {
        return progress(
            label: $label,
            steps: $items,
            callback: $callback,
            hint: $hint,
        );
    }

    /**
     * Display a section divider
     */
    protected function displayDivider(string $char = '─', int $width = 50): void
    {
        $this->line(str_repeat($char, $width));
    }

    /**
     * Output only if not in quiet mode
     */
    protected function outputIfNotQuiet(string $message, string $type = 'line'): void
    {
        if (! $this->isQuiet()) {
            match ($type) {
                'info'    => info($message),
                'warn'    => warning($message),
                'error'   => error($message),
                'comment' => $this->comment($message),
                default   => $this->line($message),
            };
        }
    }

    /**
     * Output only if in verbose mode
     */
    protected function outputIfVerbose(string $message, string $type = 'line'): void
    {
        if ($this->isVerbose()) {
            match ($type) {
                'info'    => info($message),
                'warn'    => warning($message),
                'error'   => error($message),
                'comment' => $this->comment($message),
                default   => $this->line($message),
            };
        }
    }

    /**
     * Execute a callback with spinner and error handling
     */
    protected function tryWithSpinner(string $message, callable $callback, string $failureMessage = 'Operation failed'): int
    {
        try {
            $result = $this->withSpinner($message, $callback);

            return is_int($result) ? $result : self::SUCCESS;
        } catch (Exception $e) {
            $this->failure("{$failureMessage}: {$e->getMessage()}");

            if ($this->isVerbose()) {
                $this->line("<fg=gray>{$e->getTraceAsString()}</>");
            }

            return self::FAILURE;
        }
    }
}
