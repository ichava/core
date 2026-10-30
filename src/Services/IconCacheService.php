<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Closure;
use Exception;
use __PHP_Incomplete_Class;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Ichava\Constants\IchavaConstants;

/**
 * Unified cache layer for Ichava: icon discovery results, manifest payloads,
 * directory fingerprints, and the underlying Laravel cache + file-cache
 * primitives (remember, forget, flush, stats).
 */
final class IconCacheService
{
    private int $ttl;

    private string $prefix;

    private ?IconWatcherService $watcher = null;

    public function __construct(
        private IchavaLogger $logger,
    ) {
        // Use config() directly instead of injecting ConfigurationService
        $this->ttl = config()->integer('ichava.ichava-core.cache.ttl', IchavaConstants::DEFAULT_CACHE_TTL);
        $this->prefix = config()->string('ichava.ichava-core.cache.prefix', 'ichava');
    }

    /**
     * Clear all icon-related caches
     *
     * @return array{discovery: int, manifests: int, watcher: int, manager: bool}
     */
    public function clearAll(): array
    {
        $cleared = [
            'discovery' => 0,
            'manifests' => 0,
            'watcher'   => 0,
            'manager'   => false,
        ];

        try {
            $prefix = IconDiscoveryService::CACHE_PREFIX;
            $store = cache()->store('file');

            // Use tags if supported
            if (method_exists($store->getStore(), 'tags')) {
                $store->tags(['ichava', 'icons'])->flush();
                $cleared['discovery'] = 1;
            } else {
                // Clear by pattern
                $keys = [
                    "{$prefix}.packages",
                    "{$prefix}.manifest.*",
                    "{$prefix}.search.*",
                    "{$prefix}.categories",
                    "{$prefix}.variants",
                ];

                foreach ($keys as $key) {
                    $store->forget($key);
                    $cleared['discovery']++;
                }
            }

            // Clear directory watcher fingerprints
            $this->watcher()->clearAll();
            $cleared['watcher'] = 1;

            // Clear via generic flush
            $this->flush();
            $cleared['manager'] = true;

        } catch (Exception $e) {
            $this->logger->error('❌ Failed to clear some icon caches', $e);
        }

        return $cleared;
    }

    /**
     * Rebuild icon cache intelligently
     *
     * @return array{rebuilt: int, skipped: int, errors: int, total: int, exit_code: int, success: bool}
     */
    public function rebuild(bool $force = false, ?string $package = null): array
    {
        $options = [];

        if ($force) {
            $options[] = '--force';
        }

        if ($package) {
            $options[] = "--package={$package}";
        }

        $exitCode = Artisan::call('ichava::ichava-core.cache', ['action' => 'rebuild'] + $options);
        $output = Artisan::output();

        $stats = $this->parseRebuildOutput($output);
        $stats['exit_code'] = $exitCode;
        $stats['success'] = $exitCode === 0;

        return $stats;
    }

    /**
     * Clear manifest cache for specific path
     */
    public function clearManifest(string $basePath): bool
    {
        return $this->safeForget(IconDiscoveryService::CACHE_PREFIX . '.manifest.' . md5($basePath));
    }

    /**
     * Clear directory watcher fingerprints
     */
    public function clearWatcher(): bool
    {
        try {
            $this->watcher()->clearAll();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if directory has changed
     */
    public function hasDirectoryChanged(string $path): bool
    {
        return $this->watcher()->hasChanged($path);
    }

    /**
     * Update directory fingerprint
     */
    public function updateDirectoryFingerprint(string $path): void
    {
        $this->watcher()->updateFingerprint($path);
    }

    /**
     * Remember config with caching (for ConfigurationService)
     */
    public function rememberConfig(string $key, callable $callback): mixed
    {
        try {
            return cache()
                ->store('file')
                ->remember(
                    key: $key,
                    ttl: now()->addHours(24),
                    callback: $callback(...),
                );
        } catch (Exception $e) {
            return $callback();
        }
    }

    /**
     * Forget cached config
     */
    public function forgetConfig(string $key): bool
    {
        return $this->safeForget($key);
    }

    /**
     * Remember a value in cache.
     *
     * Only caches after Laravel has fully booted to avoid serializing host
     * paths during package discovery. Gracefully handles cache failures.
     *
     * Pass $ttl and $expects by name. This method took exactly two parameters
     * for its whole life, and PHP accepts surplus arguments to a userland
     * function without complaint, so a third positional argument was silently
     * discarded rather than rejected -- which is how Icon::getPackageCounts()
     * spent its life believing it had asked for a 24-hour TTL.
     *
     * The store is untrusted input. Whatever it hands back was written by another
     * process, possibly running a different build of this package, and
     * `unserialize()` answers with `__PHP_Incomplete_Class` -- raising nothing --
     * when the class a payload names cannot be resolved. A hit is therefore
     * checked before it is returned: an incomplete object is always refused, and
     * when $expects is given the value must be an instance of that class, or have
     * that `get_debug_type()` (`array`, `string`, `int`, ...). A refused entry is
     * evicted and rebuilt from $callback, so a poisoned key repairs itself on the
     * next read instead of failing at the first typed boundary until its TTL runs
     * out.
     *
     * @param int|null $ttl Lifetime in seconds; defaults to the configured TTL.
     * @param string|null $expects A class name, or a `get_debug_type()` name, the value must have.
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, ?string $expects = null): mixed
    {
        // Only cache after Laravel has fully booted
        if (! app()->hasBeenBootstrapped()) {
            return $callback();
        }

        $fullKey = $this->buildKey($key);

        try {
            $cached = cache()->get($fullKey);

            if ($cached !== null) {
                if (self::satisfies($cached, $expects)) {
                    return $cached;
                }

                $this->logger->warning('Ichava cache entry failed validation, rebuilding', [
                    'key'      => $fullKey,
                    'expected' => $expects ?? 'a resolvable value',
                    'actual'   => get_debug_type($cached),
                ]);

                cache()->forget($fullKey);
            }

            return cache()->remember(
                key: $fullKey,
                ttl: now()->addSeconds($ttl ?? $this->ttl),
                callback: $callback(...),
            );
        } catch (Exception $e) {
            $this->logger->warning('Ichava cache failed, executing directly', [
                'driver' => config('cache.default'),
                'error'  => $e->getMessage(),
                'key'    => $fullKey,
            ]);

            return $callback();
        }
    }

    /**
     * Forget a cached value
     */
    public function forget(string $key): bool
    {
        try {
            return cache()->forget($this->buildKey($key));
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Clear all ichava cache
     */
    public function flush(): bool
    {
        try {
            $store = cache()->store($this->configuredDriver());

            if (method_exists($store, 'flush')) {
                return $store->flush();
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Forget the named caches, returning the keys that were actually forgotten.
     *
     * Callers that know which caches they depend on list them here (see
     * `InvalidateIconCache::clearAllIconCaches()`), because {@see flushPrefix()}
     * cannot find them on the file driver.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    public function forgetMany(array $keys): array
    {
        $cleared = [];

        foreach ($keys as $key) {
            if ($this->forget($key)) {
                $cleared[] = $key;
            }
        }

        return $cleared;
    }

    /**
     * Clear all ichava cache by prefix (more targeted than flush).
     *
     * Known not to work on the file driver: it globs for `md5($prefix)`, while
     * `buildKey()` writes `<prefix>:<version>:<md5>` and the file store names each
     * file after the digest of the whole key, so the prefix is not recoverable
     * from a filename and this clears nothing there. Name keys with
     * {@see forgetMany()} instead.
     */
    public function flushPrefix(): bool
    {
        try {
            $driver = $this->configuredDriver();
            $cacheStore = Cache::store($driver);

            // For file driver, delete by pattern
            if ($driver === 'file') {
                $path = config('cache.stores.file.path', storage_path('framework/cache/data'));
                $path = is_string($path) ? $path : storage_path('framework/cache/data');
                $pattern = $path . '/' . md5($this->prefix) . '*';

                foreach (File::glob($pattern) as $file) {
                    if (is_string($file)) {
                        File::delete($file);
                    }
                }

                return true;
            }

            return $this->flush();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Clear/delete a cache file
     */
    public function clear(string $path): bool
    {
        if (! File::exists($path)) {
            return true;
        }

        return File::delete($path);
    }

    /**
     * Check if cache file exists
     */
    public function exists(string $path): bool
    {
        return File::exists($path);
    }

    /**
     * Ensure directory exists for a file path
     */
    public function ensureDirectory(string $filePath): bool
    {
        $directory = dirname($filePath);

        if (File::isDirectory($directory)) {
            return true;
        }

        return File::makeDirectory($directory, 0755, true);
    }

    /**
     * Write content to file (creates directory if needed)
     */
    public function write(string $path, string $content): bool
    {
        $this->ensureDirectory($path);

        return File::put($path, $content) !== false;
    }

    /**
     * Read and require a PHP file
     */
    public function load(string $path): mixed
    {
        if (! File::exists($path)) {
            return null;
        }

        return File::getRequire($path);
    }

    /**
     * Get file size in bytes
     */
    public function size(string $path): int
    {
        if (! File::exists($path)) {
            return 0;
        }

        return File::size($path);
    }

    /**
     * Refresh/rebuild cache file
     *
     * @param Closure(): (string|false|null) $buildCallback
     */
    public function refresh(string $path, Closure $buildCallback): bool
    {
        try {
            $content = $buildCallback();

            if ($content === null || $content === false) {
                return false;
            }

            return $this->write($path, $content);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get last modified time of cache file
     */
    public function lastModified(string $path): ?int
    {
        if (! File::exists($path)) {
            return null;
        }

        return File::lastModified($path);
    }

    /**
     * Check if cache is stale (older than given seconds)
     */
    public function isStale(string $path, int $maxAge): bool
    {
        $lastModified = $this->lastModified($path);

        if ($lastModified === null) {
            return true;
        }

        return (time() - $lastModified) > $maxAge;
    }

    /**
     * Touch cache file (update modification time)
     */
    public function touch(string $path): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        return touch($path);
    }

    /**
     * Get cache prefix
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get cache statistics
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'watcher'   => $this->watcher()->getStats(),
            'driver'    => config('cache.default'),
            'ttl'       => $this->ttl,
            'prefix'    => $this->prefix,
            'available' => $this->isAvailable(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Check if cache is healthy
     */
    public function isHealthy(): bool
    {
        try {
            $testKey = 'ichava.health_check.' . time();
            $store = cache()->store('file');

            $store->put(key: $testKey, value: 'ok', ttl: now()->addSeconds(10));
            $result = $store->get($testKey) === 'ok';
            $store->forget($testKey);

            return $result;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Whether a value read back from the store can be served.
     *
     * `__PHP_Incomplete_Class` is refused whatever $expects says. Only the top
     * level is inspected: a whole-set payload holds thousands of rows, and the
     * callers that cache nested data rebuild typed objects from it themselves.
     */
    private static function satisfies(mixed $value, ?string $expects): bool
    {
        if ($value instanceof __PHP_Incomplete_Class) {
            return false;
        }

        if ($expects === null) {
            return true;
        }

        if (class_exists($expects) || interface_exists($expects)) {
            return $value instanceof $expects;
        }

        return get_debug_type($value) === $expects;
    }

    /**
     * Get IconWatcherService instance (lazy loading to avoid circular dependency)
     */
    private function watcher(): IconWatcherService
    {
        if ($this->watcher === null) {
            $this->watcher = app(IconWatcherService::class);
        }

        return $this->watcher;
    }

    /**
     * Forget a single key from the file cache store, swallowing driver errors
     * so callers can chain teardown without try/catch boilerplate at every site.
     */
    private function safeForget(string $key): bool
    {
        try {
            return (bool) cache()->store('file')->forget($key);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Parse rebuild command output for statistics
     *
     * @return array{rebuilt: int, skipped: int, errors: int, total: int}
     */
    private function parseRebuildOutput(string $output): array
    {
        $stats = [
            'rebuilt' => 0,
            'skipped' => 0,
            'errors'  => 0,
            'total'   => 0,
        ];

        if (preg_match('/Rebuilt.*?(\d+)/i', $output, $matches)) {
            $stats['rebuilt'] = (int) $matches[1];
        }

        if (preg_match('/Skipped.*?(\d+)/i', $output, $matches)) {
            $stats['skipped'] = (int) $matches[1];
        }

        if (preg_match('/Errors.*?(\d+)/i', $output, $matches)) {
            $stats['errors'] = (int) $matches[1];
        }

        if (preg_match('/Total Packages.*?(\d+)/i', $output, $matches)) {
            $stats['total'] = (int) $matches[1];
        }

        return $stats;
    }

    /**
     * Build cache key with collision prevention
     *
     * Uses MD5 hash for collision prevention and length normalization
     */
    private function buildKey(string $key): string
    {
        $hashedKey = md5($key);

        $version = config('ichava.ichava-core.cache.version', 'v2');
        $version = is_scalar($version) || $version === null ? (string) $version : 'v2';

        return $this->prefix . ':' . $version . ':' . $hashedKey;
    }

    /**
     * The store named by `cache.driver`, `file` when it is unset; null (the
     * default store) when it is explicitly null.
     */
    private function configuredDriver(): ?string
    {
        $driver = config('cache.driver', 'file');

        return is_string($driver) || $driver === null ? $driver : 'file';
    }

    /**
     * Check if cache is available
     */
    private function isAvailable(): bool
    {
        try {
            $store = cache()->store($this->configuredDriver());
            $testKey = "{$this->prefix}.health_test";

            $store->put(key: $testKey, value: 'ok', ttl: now()->addSeconds(5));
            $result = $store->get($testKey) === 'ok';
            $store->forget($testKey);

            return $result;
        } catch (Exception $e) {
            return false;
        }
    }
}
