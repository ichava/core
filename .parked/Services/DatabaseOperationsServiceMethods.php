<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Parked\Services;

/**
 * Members with no caller, moved here verbatim from `src/Services/DatabaseOperationsService.php` on 2026-09-26.
 *
 * NOT autoloaded and NOT shipped (`/.parked export-ignore`). Kept so a member
 * can be restored from a file rather than from history, if a caller ever
 * appears. See `.parked/README.md` for the measurement behind each entry.
 */
trait DatabaseOperationsServiceMethods
{
    /**
     * Count icons in a directory
     */
    public function countIconsInDirectory(string $path): int
    {
        if (empty($path) || ! File::isDirectory($path)) {
            return 0;
        }

        $count = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && Str::lower($file->getExtension()) === 'svg') {
                    $count++;
                }
            }
        } catch (Exception $e) {
            $this->logger->warning("Failed to count icons in: {$path}", ['error' => $e->getMessage()]);
        }

        return $count;
    }
}
