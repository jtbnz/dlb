<?php

namespace App\Services;

/**
 * Simple in-memory cache for request-scoped data
 * Helps avoid redundant database queries within a single request
 */
class Cache
{
    private static array $cache = [];
    private static array $stats = ['hits' => 0, 'misses' => 0];

    /**
     * Get a value from cache
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (isset(self::$cache[$key])) {
            self::$stats['hits']++;
            return self::$cache[$key];
        }
        self::$stats['misses']++;
        return $default;
    }

    /**
     * Store a value in cache
     * Note: TTL is not implemented for request-scoped cache (cleared after request ends)
     */
    public static function set(string $key, mixed $value): void
    {
        self::$cache[$key] = $value;
    }

    /**
     * Check if key exists in cache
     */
    public static function has(string $key): bool
    {
        return isset(self::$cache[$key]);
    }

    /**
     * Remove a key from cache
     */
    public static function forget(string $key): void
    {
        unset(self::$cache[$key]);
    }

    /**
     * Clear all cached data
     */
    public static function clear(): void
    {
        self::$cache = [];
    }

    /**
     * Get cache statistics
     */
    public static function stats(): array
    {
        return self::$stats;
    }

    /**
     * Remember: Get from cache or execute callback and store result
     */
    public static function remember(string $key, callable $callback): mixed
    {
        if (self::has($key)) {
            return self::get($key);
        }

        $value = $callback();
        self::set($key, $value);
        return $value;
    }
}
