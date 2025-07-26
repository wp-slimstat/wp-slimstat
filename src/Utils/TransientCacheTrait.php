<?php

namespace SlimStat\Utils;

trait TransientCacheTrait
{
    /**
     * Generates a cache key for a given query.
     *
     * This method normalizes the query by removing time parts from date
     * ranges and then generates an MD5 hash of the normalized query.
     * The hash is then used as a prefix for the cache key.
     *
     * @param string $input The SQL query to be cached.
     * @return string The generated cache key.
     */
    public function getCacheKey($input)
    {
        $normalized = $input;
        if (preg_match('/BETWEEN\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?\s+AND\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?/i', $input, $matches)) {
            $from = $matches[1];
            $to = $matches[2];
            $normalized = preg_replace('/BETWEEN\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?\s+AND\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?/i', "BETWEEN '$from' AND '$to'", $input);
        }
        $normalized = preg_replace_callback('/(\d{4}-\d{2}-\d{2})[\s\d:]{0,8}/', function ($m) {
            return $m[1];
        }, $normalized);
        $hash = substr(md5($normalized), 0, 10);
        if (empty($input) || $hash === '' || $input === false) return '';
        return sprintf('wp_slimstat_query_%s', $hash);
    }

    /**
     * Retrieves the cached result for the given query and args
     *
     * @param string $input  The SQL query
     *
     * @return mixed The query result, or false if there is no cached result
     */
    public function getCachedResult($input)
    {
        $cacheKey = $this->getCacheKey($input);
        if (empty($cacheKey) || $cacheKey === 'wp_slimstat_query_') return false;
        return get_transient($cacheKey);
    }

    /**
     * Sets the transient cache for the given query.
     *
     * @param string $input     The SQL query
     * @param mixed  $result    The query result
     * @param int    $expiration The cache expiration time, in seconds
     *
     * @return bool True if cache was successfully set, false otherwise
     */
    public function setCachedResult($input, $result, $expiration = DAY_IN_SECONDS)
    {
        $cacheKey = $this->getCacheKey($input);
        if (empty($cacheKey) || $cacheKey === 'wp_slimstat_query_') return false;
        return set_transient($cacheKey, $result, $expiration * 24);
    }

    /**
     * Clears the transient cache for the given query.
     *
     * This function generates a cache key based on the query and
     * attempts to delete the corresponding transient from the cache.
     *
     * @param string $query The SQL query whose cache should be cleared.
     * @return bool True if the cache was successfully cleared, false otherwise.
     */
    public function clearCache($query)
    {
        $cacheKey = $this->getCacheKey($query);
        if (empty($cacheKey) || $cacheKey === 'wp_slimstat_query_') return false;
        return delete_transient($cacheKey);
    }
}
