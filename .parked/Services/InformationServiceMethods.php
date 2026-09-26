<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Parked\Services;

/**
 * Members with no caller, moved here verbatim from `src/Services/InformationService.php` on 2026-09-26.
 *
 * NOT autoloaded and NOT shipped (`/.parked export-ignore`). Kept so a member
 * can be restored from a file rather than from history, if a caller ever
 * appears. See `.parked/README.md` for the measurement behind each entry.
 */
trait InformationServiceMethods
{
    /**
     * Format file size
     */
    public function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
