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
    ],

];
