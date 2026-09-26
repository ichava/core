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
use function Laravel\Prompts\intro;
use function Laravel\Prompts\select;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Console\Tools\Commands\Command;
use Simtabi\Laranail\Console\Tools\Commands\Concerns\SupportsNamespacedNames;

/**
 * Base command for all Ichava Artisan commands
 *
 * Provides shared functionality using Laravel Prompts:
 * - Timing/performance tracking
 * - Formatting helpers (bytes, duration, progress bars)
 * - Confirmation dialogs (using Prompts)
 * - Invalid action/type handling
 * - Display helpers (headers, status rows, tables)
 * - Export functionality
 * - Table existence checks
 *
 * Extends the laranail/console command base, which unlocks the
 * namespaced `laranail::<slug>.<command>` naming, capability-aware
 * console services, and short-alias support for every Ichava command.
 *
 * @see https://laravel.com/docs/12.x/prompts
 */
abstract class BaseCommand extends Command
{
    /*
     * Symfony's validateName() rejects the empty segment in `::`, so a command
     * named `ichava::ichava-core.cache` cannot be registered through the normal
     * path. This trait writes the name past that validator. Dispatch still works
     * because Symfony resolves an exact name before its `:`-splitting namespace
     * lookup. Same mechanism `laranail/db-console` uses.
     */
    use SupportsNamespacedNames;

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

    public function __construct()
    {
        parent::__construct();
    }

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
        $elapsed = $this->getElapsedMs();
        info('⏱️  Completed in ' . round($elapsed, 2) . 'ms');
    }

    /**
     * Format bytes to human-readable size
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return number_format($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    /**
     * Format duration in seconds to human-readable format
     */
    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return "{$minutes}m {$remainingSeconds}s";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return "{$hours}h {$remainingMinutes}m {$remainingSeconds}s";
    }

    /**
     * Format milliseconds to human-readable format
     */
    protected function formatMs(float $ms): string
    {
        if ($ms < 1000) {
            return round($ms, 2) . 'ms';
        }

        return $this->formatDuration((int) ($ms / 1000));
    }

    /**
     * Create a visual progress bar
     */
    protected function createProgressBar(float $percent, int $width = 10): string
    {
        $filled = (int) round($percent / (100 / $width));
        $empty = $width - $filled;

        return '<fg=green>' . str_repeat('█', $filled) . '</>' .
               '<fg=gray>' . str_repeat('░', $empty) . '</>';
    }

    /**
     * Format a number with thousands separator
     */
    protected function formatNumber(int|float $number): string
    {
        return number_format($number);
    }

    /**
     * Display a styled intro header using Laravel Prompts
     */
    protected function displayHeader(string $title, string $icon = '📊'): void
    {
        intro("{$icon} {$title}");
    }

    /**
     * Display a success message using Laravel Prompts
     */
    protected function success(string $message): void
    {
        info("✅ {$message}");
    }

    /**
     * Display a failure message using Laravel Prompts
     */
    protected function failure(string $message): void
    {
        error("❌ {$message}");
    }

    /**
     * Display a tip/hint message using Laravel Prompts
     */
    protected function tip(string $message): void
    {
        note("💡 {$message}");
    }

    /**
     * Format status with color coding
     */
    protected function formatStatus(string $status): string
    {
        return match (Str::lower($status)) {
            'processing', 'running', 'in_progress' => '<fg=yellow>⏳ Processing</>',
            'completed', 'done', 'success'         => '<fg=green>✅ Completed</>',
            'failed', 'error'                      => '<fg=red>❌ Failed</>',
            'pending', 'queued'                    => '<fg=blue>⏸️  Pending</>',
            'skipped'                              => '<fg=gray>⏭️  Skipped</>',
            'active'                               => '<fg=green>● Active</>',
            'inactive'                             => '<fg=gray>○ Inactive</>',
            default                                => '<fg=gray>Unknown</>',
        };
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
     * Ask for selection from options using Laravel Prompts
     */
    protected function askSelect(
        string $label,
        array $options,
        ?string $default = null,
        int $scroll = 5,
        ?string $hint = null,
    ): string {
        return select(
            label: $label,
            options: $options,
            default: $default,
            scroll: $scroll,
            hint: $hint,
        );
    }

    /**
     * Handle invalid action argument using Laravel Prompts
     */
    protected function handleInvalidAction(string $action, array $validActions): int
    {
        error("Invalid action: {$action}");
        note('Valid actions: ' . implode(', ', $validActions));

        // Offer to select a valid action
        if (! $this->isQuiet()) {
            $selectedAction = $this->askSelect(
                label: 'Would you like to select a valid action?',
                options: array_merge(['cancel' => 'Cancel operation'], array_combine($validActions, $validActions)),
                default: 'cancel',
                hint: 'Select an action or cancel',
            );

            if ($selectedAction !== 'cancel') {
                // Re-run with the selected action
                $this->input->setArgument('action', $selectedAction);

                return $this->handle();
            }
        }

        return self::INVALID;
    }

    /**
     * Handle invalid type argument using Laravel Prompts
     */
    protected function handleInvalidType(string $type, array $validTypes): int
    {
        error("Invalid type: {$type}");
        note('Valid types: ' . implode(', ', $validTypes));

        // Offer to select a valid type
        if (! $this->isQuiet()) {
            $selectedType = $this->askSelect(
                label: 'Would you like to select a valid type?',
                options: array_merge(['cancel' => 'Cancel operation'], array_combine($validTypes, $validTypes)),
                default: 'cancel',
                hint: 'Select a type or cancel',
            );

            if ($selectedType !== 'cancel') {
                // Re-run with the selected type
                $this->input->setArgument('type', $selectedType);

                return $this->handle();
            }
        }

        return self::INVALID;
    }

    /**
     * Export data to JSON file
     */
    protected function exportToJson(array $data, string $filename): bool
    {
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            File::put($filename, $json);
            $this->success("Exported to: {$filename}");

            return true;
        } catch (Exception $e) {
            $this->failure("Failed to export: {$e->getMessage()}");

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
            $this->success("Exported to: {$filename}");

            return true;
        } catch (Exception $e) {
            $this->failure("Failed to export: {$e->getMessage()}");

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
            $this->failure('Required tables do not exist: ' . implode(', ', $missing));
            $this->tip('Run migrations first: php artisan ichava::ichava-core.database migrate');

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
     * Get relative path from base path
     */
    protected function getRelativePath(string $path, ?string $basePath = null): string
    {
        $basePath = $basePath ?? base_path();

        if (Str::startsWith($path, $basePath)) {
            return substr($path, strlen($basePath) + 1);
        }

        return $path;
    }

    /**
     * Check if output is quiet mode
     */
    protected function isQuiet(): bool
    {
        return $this->output->isQuiet();
    }

    /**
     * Check if verbose mode is enabled
     */
    protected function isVerbose(): bool
    {
        return $this->output->isVerbose();
    }

    /**
     * Execute a callback with error handling
     */
    protected function tryExecute(callable $callback, string $failureMessage = 'Operation failed'): int
    {
        try {
            return $callback();
        } catch (Exception $e) {
            $this->failure("{$failureMessage}: {$e->getMessage()}");

            if ($this->isVerbose()) {
                $this->line("<fg=gray>{$e->getTraceAsString()}</>");
            }

            return self::FAILURE;
        }
    }
}
