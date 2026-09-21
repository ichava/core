<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Actions;

use Throwable;
use Illuminate\Support\Str;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Illuminate\Filesystem\Filesystem;

/**
 * How many `.svg` files live under a path.
 *
 * Two modes, because the two callers want different things. A pack's total is a
 * full recursive walk. A folder-tree node only needs to know whether this
 * directory holds icons, and stops at a cap rather than walking a set of
 * 121,314 files to render one row.
 *
 * **`Filesystem` is injected**, which is what makes this assertable against a
 * temporary directory instead of requiring a registered pack.
 *
 * ## The catch this replaces
 *
 * Both counters were wrapped in `catch (IchavaException $e)`. Neither
 * `RecursiveDirectoryIterator` nor `scandir()` throws that: an unreadable
 * directory produces `UnexpectedValueException`, which extends
 * `RuntimeException` exactly as `IchavaException` does and is therefore its
 * *sibling*, not its subclass.
 *
 * The handler could not fire, so the failure it was written for propagated out
 * of a method whose contract is to return `0`. Catching `Throwable` here is the
 * behaviour the original was reaching for.
 */
final readonly class CountSvgFiles
{
    /** Stop the shallow count here; a caller rendering a tree node needs "many", not the number. */
    public const int SHALLOW_CAP = 100;

    public function __construct(private Filesystem $files) {}

    /**
     * Every `.svg` beneath `$path`, at any depth.
     */
    public function recursively(string $path): int
    {
        if (! $this->files->isDirectory($path)) {
            return 0;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            $count = 0;

            foreach ($iterator as $file) {
                if ($file->isFile() && Str::endsWith($file->getFilename(), '.svg')) {
                    $count++;
                }
            }

            return $count;
        } catch (Throwable) {
            // An unreadable directory is not worth failing a page render over.
            return 0;
        }
    }

    /**
     * `.svg` files directly in `$path`, capped at {@see self::SHALLOW_CAP}.
     */
    public function directly(string $path): int
    {
        if (! $this->files->isDirectory($path)) {
            return 0;
        }

        try {
            $items = @scandir($path);

            if ($items === false) {
                return 0;
            }

            $count = 0;

            foreach ($items as $item) {
                if (! Str::endsWith($item, '.svg')) {
                    continue;
                }

                $count++;

                if ($count >= self::SHALLOW_CAP) {
                    return self::SHALLOW_CAP;
                }
            }

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }
}
