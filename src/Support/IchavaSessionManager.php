<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Illuminate\Support\Str;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;

/**
 * Ichava Session Manager
 *
 * Self-contained session management with 3-tier fallback:
 * 1. Browser localStorage (always works) - handled by frontend
 * 2. Laravel session (if available) - this class
 * 3. User account (if authenticated) - future enhancement
 *
 * Philosophy:
 * - Never fail if host's session doesn't work
 * - Gracefully degrade to browser storage
 * - Log issues but don't throw exceptions
 * - Always return safe defaults
 */
final class IchavaSessionManager
{
    private const SESSION_PREFIX = 'ichava';

    private HostCapabilities $capabilities;

    private bool $sessionAvailable;

    public function __construct()
    {
        $this->capabilities = HostCapabilities::getInstance();
        $this->sessionAvailable = $this->capabilities->hasSession();

        // Detect session availability once per request
        if (! $this->sessionAvailable && config('app.debug')) {
            app(IchavaLogger::class)->debug('ℹ️ Session not available, using browser storage fallback');
        }
    }

    /**
     * Store a preference value
     *
     * @param  string  $key  Preference key (e.g., 'theme', 'display.view_mode')
     * @param  mixed  $value  Value to store
     * @return bool Success status
     */
    public function put(string $key, mixed $value): bool
    {
        if (! $this->sessionAvailable) {
            // Session not available, frontend localStorage will handle it
            return false;
        }

        try {
            $sessionKey = $this->makeKey($key);
            session()->put($sessionKey, $value);

            if (config('app.debug') && config('ichava.logging.session_debug', false)) {
                app(IchavaLogger::class)->debug("Session stored: {$key}", ['value' => $value]);
            }

            return true;
        } catch (\Exception $e) {
            app(IchavaLogger::class)->warning('Failed to store in session', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Retrieve a preference value
     *
     * @param  string  $key  Preference key
     * @param  mixed  $default  Default value if not found
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->sessionAvailable) {
            return $default;
        }

        try {
            $sessionKey = $this->makeKey($key);
            $value = session()->get($sessionKey, $default);

            if (config('app.debug') && config('ichava.logging.session_debug', false)) {
                app(IchavaLogger::class)->debug("Session retrieved: {$key}", ['value' => $value]);
            }

            return $value;
        } catch (\Exception $e) {
            app(IchavaLogger::class)->warning('Failed to retrieve from session', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * Check if a preference exists
     */
    public function has(string $key): bool
    {
        if (! $this->sessionAvailable) {
            return false;
        }

        try {
            $sessionKey = $this->makeKey($key);

            return session()->has($sessionKey);
        } catch (\Exception $e) {
            app(IchavaLogger::class)->warning('Failed to check session', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Remove a preference
     */
    public function forget(string $key): bool
    {
        if (! $this->sessionAvailable) {
            return false;
        }

        try {
            $sessionKey = $this->makeKey($key);
            session()->forget($sessionKey);

            return true;
        } catch (\Exception $e) {
            app(IchavaLogger::class)->warning('Failed to forget session key', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get all Ichava preferences from session
     */
    public function all(): array
    {
        if (! $this->sessionAvailable) {
            return [];
        }

        try {
            $allSession = session()->all();
            $ichavaPrefs = [];

            // Filter only Ichava keys
            $prefix = self::SESSION_PREFIX.'.';
            foreach ($allSession as $key => $value) {
                if (Str::startsWith($key, $prefix)) {
                    // Remove prefix for clean keys
                    $cleanKey = substr($key, strlen($prefix));
                    $ichavaPrefs[$cleanKey] = $value;
                }
            }

            return $ichavaPrefs;
        } catch (\Exception $e) {
            app(IchavaLogger::class)->warning('Failed to retrieve all session data', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Clear all Ichava preferences from session
     */
    public function clear(): bool
    {
        if (! $this->sessionAvailable) {
            return false;
        }

        try {
            $allSession = session()->all();
            $prefix = self::SESSION_PREFIX.'.';

            // Remove all Ichava keys
            foreach (array_keys($allSession) as $key) {
                if (Str::startsWith($key, $prefix)) {
                    session()->forget($key);
                }
            }

            return true;
        } catch (\Exception $e) {
            app(IchavaLogger::class)->warning('Failed to clear session', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if session storage is available
     */
    public function isAvailable(): bool
    {
        return $this->sessionAvailable;
    }

    /**
     * Get storage tier
     *
     * @return string 'browser'|'session'|'database'
     */
    public function getTier(): string
    {
        if (! $this->sessionAvailable) {
            return 'browser';
        }

        // Check if we can store in database (future enhancement)
        if ($this->capabilities->hasDatabase() && $this->capabilities->hasAuth()) {
            return 'database';
        }

        return 'session';
    }

    /**
     * Store entire preferences object (from API)
     */
    public function putAll(array $preferences): bool
    {
        if (! $this->sessionAvailable) {
            return false;
        }

        $success = true;
        foreach ($preferences as $section => $values) {
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    $fullKey = "{$section}.{$key}";
                    if (! $this->put($fullKey, $value)) {
                        $success = false;
                    }
                }
            } else {
                if (! $this->put($section, $values)) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Make a namespaced session key
     */
    private function makeKey(string $key): string
    {
        return self::SESSION_PREFIX.'.'.$key;
    }

    /**
     * Get browser ID from request headers
     * Used for cross-domain session identification
     */
    public function getBrowserId(): ?string
    {
        try {
            return request()->header('X-Browser-Id');
        } catch (\Exception $e) {
            return null;
        }
    }
}
