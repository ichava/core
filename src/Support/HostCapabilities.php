<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Exception;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;

/**
 * Host Capabilities Detection
 *
 * Detects what features the host application supports to enable
 * graceful degradation and optional enhancements.
 *
 * Core Philosophy:
 * - Ichava works standalone (browser storage only)
 * - Enhanced with Laravel session (if available)
 * - Premium with user authentication (if available)
 *
 * Never assume host has any feature - always check first!
 */
final class HostCapabilities
{
    private static ?self $instance = null;

    private array $capabilities = [];

    private bool $detected = false;

    private function __construct()
    {
        // Singleton pattern
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Detect all host capabilities once
     */
    public function detect(): void
    {
        if ($this->detected) {
            return;
        }

        $this->capabilities = [
            'session'  => $this->detectSession(),
            'auth'     => $this->detectAuth(),
            'sanctum'  => $this->detectSanctum(),
            'database' => $this->detectDatabase(),
            'cache'    => $this->detectCache(),
        ];

        $this->detected = true;

        // Log detected capabilities in debug mode
        if (config('app.debug')) {
            app(IchavaLogger::class)->debug('Host capabilities detected', $this->capabilities);
        }
    }

    /**
     * Check if Laravel session is available and functional
     */
    public function hasSession(): bool
    {
        $this->detect();

        return $this->capabilities['session'];
    }

    /**
     * Check if host has authentication system
     */
    public function hasAuth(): bool
    {
        $this->detect();

        return $this->capabilities['auth'];
    }

    /**
     * Check if host has Laravel Sanctum installed
     */
    public function hasSanctum(): bool
    {
        $this->detect();

        return $this->capabilities['sanctum'];
    }

    /**
     * Check if host has functional database
     */
    public function hasDatabase(): bool
    {
        $this->detect();

        return $this->capabilities['database'];
    }

    /**
     * Check if host has functional cache
     */
    public function hasCache(): bool
    {
        $this->detect();

        return $this->capabilities['cache'];
    }

    /**
     * Get all capabilities as array
     */
    public function all(): array
    {
        $this->detect();

        return $this->capabilities;
    }

    /**
     * Get capability tier for user
     *
     * @return string 'basic'|'enhanced'|'premium'
     */
    public function getTier(): string
    {
        $this->detect();

        // Premium: Has auth + session + sanctum
        if ($this->capabilities['auth'] && $this->capabilities['session'] && $this->capabilities['sanctum']) {
            return 'premium';
        }

        // Enhanced: Has session (can store preferences server-side)
        if ($this->capabilities['session']) {
            return 'enhanced';
        }

        // Basic: Browser storage only
        return 'basic';
    }

    /**
     * Clear cached capabilities (for testing)
     */
    public function reset(): void
    {
        $this->capabilities = [];
        $this->detected = false;
    }

    /**
     * Detect if session is available and functional
     */
    private function detectSession(): bool
    {
        try {
            // Check if session driver is not 'array' (which doesn't persist)
            $driver = config('session.driver');
            if ($driver === 'array') {
                return false;
            }

            // For database sessions, check if sessions table exists first
            // This prevents errors during initial setup before migrations run
            if ($driver === 'database') {
                if (! Schema::hasTable(config('session.table', 'sessions'))) {
                    return false;
                }
            }

            // Check if session can be started
            if (! session()->isStarted()) {
                session()->start();
            }

            // Verify session works by testing a value
            $testKey = '_ichava_capability_test';
            session()->put($testKey, true);
            $works = session()->get($testKey) === true;
            session()->forget($testKey);

            return $works;
        } catch (Exception) {
            // Silently fail - session detection may fail during init before migrations
            return false;
        }
    }

    /**
     * Detect if authentication system is available
     */
    private function detectAuth(): bool
    {
        try {
            // Check if auth guard is configured
            if (! config('auth.defaults.guard')) {
                return false;
            }

            // Check if User provider exists
            $provider = config('auth.guards.' . config('auth.defaults.guard') . '.provider');
            if (! $provider) {
                return false;
            }

            // Check if User model exists
            $model = config("auth.providers.{$provider}.model");
            if (! $model || ! class_exists($model)) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            app(IchavaLogger::class)->debug('Auth detection failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Detect if Laravel Sanctum is installed and configured
     */
    private function detectSanctum(): bool
    {
        try {
            // Check if Sanctum is installed
            if (! class_exists(Sanctum::class)) {
                return false;
            }

            // Check if Sanctum config exists
            if (! config('sanctum.stateful')) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            app(IchavaLogger::class)->debug('Sanctum detection failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Detect if database is available and functional
     */
    private function detectDatabase(): bool
    {
        try {
            // Try a simple query
            Schema::hasTable('users'); // Most Laravel apps have this

            return true;
        } catch (Exception $e) {
            app(IchavaLogger::class)->debug('Database detection failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Detect if cache is available and functional
     */
    private function detectCache(): bool
    {
        try {
            $driver = config('cache.default');
            if ($driver === 'array') {
                return false; // Array cache doesn't persist
            }

            // Test cache
            $testKey = 'ichava_capability_test';
            cache()->put($testKey, true, 1);
            $works = cache()->get($testKey) === true;
            cache()->forget($testKey);

            return $works;
        } catch (Exception $e) {
            app(IchavaLogger::class)->debug('Cache detection failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
