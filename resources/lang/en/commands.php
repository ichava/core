<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Command output
|--------------------------------------------------------------------------
|
| Strings the Artisan commands print. Grouped by command so a translator sees
| one screen's worth of context at a time, and so a removed command takes its
| strings with it.
|
| Command *descriptions* are deliberately absent: `$description` is a property,
| evaluated before the container exists, so `__()` cannot run there.
|
*/

return [

    // Shared by every command through BaseCommand.
    'common' => [
        'completed_in'       => '⏱️  Completed in :time',
        'tip'                => '💡 :message',
        'invalid_action'     => 'Invalid action: :action',
        'valid_actions'      => 'Valid actions: :actions',
        'select_action'      => 'Would you like to select a valid action?',
        'select_action_hint' => 'Select an action or cancel',
        'invalid_type'       => 'Invalid type: :type',
        'valid_types'        => 'Valid types: :types',
        'select_type'        => 'Would you like to select a valid type?',
        'select_type_hint'   => 'Select a type or cancel',
        'cancel_option'      => 'Cancel operation',
        'exported'           => 'Exported to: :path',
        'export_failed'      => 'Failed to export: :error',
        'tables_missing'     => 'Required tables do not exist: :tables',
        'run_migrations'     => 'Run migrations first: php artisan :command migrate',
        'operation_failed'   => 'Operation failed',
    ],

    'cleanup_logs' => [
        'analyzing'         => 'Analyzing log files...',
        'processing'        => 'Processing log files...',
        'processing_hint'   => 'This may take a moment for large log directories',
        'dry_run'           => 'DRY RUN MODE - No files will be deleted',
        'none_found'        => 'No Ichava log files found',
        'nothing_to_do'     => 'No old log files to clean up.',
        'dir_missing'       => 'Log directory not found: :path',
        'retention_ask'     => 'How many days of logs to retain?',
        'retention_hint'    => 'Logs older than this will be deleted',
        'retention_invalid' => 'Please enter a valid number of days (minimum 1)',
        'total_files'       => 'Total log files',
        'would_delete'      => 'Would delete',
        'deleted'           => 'Deleted',

        // Laravel Prompts surfaces: intro/outro/note/warning and the two
        // summary tables. These reach the user exactly like the strings above;
        // they were missed on the first pass because the guard only watched
        // `$this->` helpers and these are free functions.
        'intro'             => '🧹 Cleaning up Ichava logs older than :days days',
        'cleaned_up'        => '✅ Cleaned up :count old log file(s)',
        'would_delete_note' => 'Would delete :count file(s). Run without --dry-run to actually delete.',
        'delete_failed'     => ':count file(s) failed to delete. Check permissions.',

        'table' => [
            'metric' => 'Metric',
            'count'  => 'Count',
            'kept'   => 'Kept',
            'failed' => 'Failed',
            'file'   => 'File',
            'age'    => 'Age',
            'action' => 'Action',
            'days'   => ':days days',
        ],

        'action' => [
            'deleted'      => '✅ Deleted',
            'would_delete' => '🔍 Would delete',
            'failed'       => '❌ Failed',
            'kept'         => '⏭️ Kept',
        ],
    ],

];
