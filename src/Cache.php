<?php

namespace SilverStripe\Versioned;

use SilverStripe\Core\Resettable;

/**
 * Used to cache instance based queries in versioned
 */
class Cache implements Resettable
{
    protected static $versionedNumberCache;

    /*
     * Set the version number cache for an item
     */
    public static function setCacheVersionedNumber(string $baseClass, string $stage, int $id, int $version): void
    {
        if (!isset(self::$versionedNumberCache[$baseClass])) {
            self::$versionedNumberCache[$baseClass] = [];
        }

        if (!isset(self::$versionedNumberCache[$baseClass][$stage])) {
            self::$versionedNumberCache[$baseClass][$stage] = [];
        }

        self::$versionedNumberCache[$baseClass][$stage][$id] = $version;
    }

    /*
     * Get the version number cache for an item
     */
    public static function getCachedVersionNumber(string $baseClass, string $stage, int $id): ?int
    {
        if (isset(self::$versionedNumberCache[$baseClass][$stage][$id])) {
            return self::$versionedNumberCache[$baseClass][$stage][$id] ?: null;
        } elseif (isset(self::$versionedNumberCache[$baseClass][$stage]['_complete'])) {
            // if the cache was marked as "complete" then we know the record is missing, just return null
            // this is used for tree view optimisation to avoid unnecessary re-requests for draft pages
            return null;
        }
    }

    /*
     * Check if a versioned number is cached
     */
    public static function isCachedVersionNumber(string $baseClass, string $stage, int $id): bool
    {
        return isset(self::$versionedNumberCache[$baseClass][$stage][$id])
            || isset(self::$versionedNumberCache[$baseClass][$stage]['_complete']);
    }

    /*
     * Marks a cache complete
     */
    public static function markVersionNumberCacheComplete(string $baseClass, string $stage): void
    {
        if (!isset(self::$versionedNumberCache[$baseClass])) {
            self::$versionedNumberCache[$baseClass] = [];
        }

        if (!isset(self::$versionedNumberCache[$baseClass][$stage])) {
            self::$versionedNumberCache[$baseClass][$stage] = [];
        }

        self::$versionedNumberCache[$baseClass][$stage]['_complete'] = true;
    }

    /*
     * Clear the cache, used for tests
     */
    public static function reset(): void
    {
        self::$versionedNumberCache = [];
    }
}
