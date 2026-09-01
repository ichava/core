<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Closure;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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
        $this->ttl = config('ichava.core.cache.ttl', IchavaConstants::DEFAULT_CACHE_TTL);
        $this->prefix = config('ichava.core.cache.prefix', 'ichava');
    }

    /**
     * Clear all icon-related caches
     */
    public function clearAll(): array
    {
        $cleared = [
            'discovery' => 0,
            'manifests' => 0,
            'watcher' => 0,
            'manager' => false,
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

        $exitCode = Artisan::call('ichava:cache', ['action' => 'rebuild'] + $options);
        $output = Artisan::output();

        $stats = $this->parseRebuildOutput($output);
        $stats['exit_code'] = $exitCode;
        $stats['success'] = $exitCode === 0;

        return $stats;
    }

    /**
     * Clear discovery cache only
     */
    public function clearDiscovery(): bool
    {
        return $this->safeForget(IconDiscoveryService::CACHE_PREFIX.'.packages');
    }

    /**
     * Clear manifest cache for specific path
     */
    public function clearManifest(string $basePath): bool
    {
        return $this->safeForget(IconDiscoveryService::CACHE_PREFIX.'.manifest.'.md5($basePath));
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
     * Warm up cache for all packages
     */
    public function warmUp(): array
    {
        return $this->rebuild(force: true);
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
                    callback: $callback,
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
     */
    public function remember(string $key, callable $callback): mixed
    {
        // Only cache after Laravel has fully booted
        if (! app()->hasBeenBootstrapped()) {
            return $callback();
        }

        $fullKey = $this->buildKey($key);

        try {
            return cache()->remember(
                key: $fullKey,
                ttl: now()->addSeconds($this->ttl),
                callback: $callback,
            );
        } catch (Exception $e) {
            $this->logger->warning('Ichava cache failed, executing directly', [
                'driver' => config('cache.default'),
                'error' => $e->getMessage(),
                'key' => $fullKey,
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
            $store = cache()->store(config('cache.driver', 'file'));

            if (method_exists($store, 'flush')) {
                return $store->flush();
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Clear all ichava cache by prefix (more targeted than flush)
     */
    public function flushPrefix(): bool
    {
        try {
            $driver = config('cache.driver', 'file');
            $cacheStore = Cache::store($driver);

            // For file driver, delete by pattern
            if ($driver === 'file') {
                $path = config('cache.stores.file.path', storage_path('framework/cache/data'));
                $pattern = $path.'/'.md5($this->prefix).'*';

                foreach (File::glob($pattern) as $file) {
                    File::delete($file);
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
     * Format bytes to human-readable format
     */
    public function formatSize(int $bytes, int $precision = 2): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $precision).' '.$units[$i];
    }

    /**
     * Refresh/rebuild cache file
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
     * Clear and optionally rebuild cache
     */
    public function clearAndRefresh(string $path, ?Closure $rebuildCallback = null): bool
    {
        $cleared = $this->clear($path);

        if (! $cleared) {
            return false;
        }

        if ($rebuildCallback) {
            return $this->refresh($path, $rebuildCallback);
        }

        return true;
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
     * Set cache TTL
     */
    public function setTtl(int $ttl): self
    {
        $this->ttl = $ttl;

        return $this;
    }

    /**
     * Get cache TTL
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Set cache prefix
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;

        return $this;
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
     */
    public function getStats(): array
    {
        return [
            'watcher' => $this->watcher()->getStats(),
            'driver' => config('cache.default'),
            'ttl' => $this->ttl,
            'prefix' => $this->prefix,
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
            $testKey = 'ichava.health_check.'.time();
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
     */
    private function parseRebuildOutput(string $output): array
    {
        $stats = [
            'rebuilt' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total' => 0,
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

        return $this->prefix.':'.config('ichava.core.cache.version', 'v1').':'.$hashedKey;
    }

    /**
     * Check if cache is available
     */
    private function isAvailable(): bool
    {
        try {
            $store = cache()->store(config('cache.driver', 'file'));
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
