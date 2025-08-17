<?php

namespace SlimStat\Traits;

trait TransientCacheTrait
{
    public function getCacheKey($input)
    {
        $normalized = $input;
        if (preg_match('/BETWEEN\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?\s+AND\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?/i', $input, $matches)) {
            $from       = $matches[1];
            $to         = $matches[2];
            $normalized = preg_replace('/BETWEEN\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?\s+AND\s+[\'\"]?(\d{4}-\d{2}-\d{2})[\s\d:]*[\'\"]?/i', sprintf("BETWEEN '%s' AND '%s'", $from, $to), $input);
        }

        $normalized = preg_replace_callback('/(\d{4}-\d{2}-\d{2})[\s\d:]{0,8}/', fn ($m) => $m[1], $normalized);
        $hash       = substr(md5($normalized), 0, 10);
        return sprintf('wp_slimstat_cache_%s', $hash);
    }

    public function getCachedResult($input)
    {
        $cacheKey = $this->getCacheKey($input);
        return get_transient($cacheKey);
    }

    public function setCachedResult($input, $result, $expiration = DAY_IN_SECONDS)
    {
        $cacheKey = $this->getCacheKey($input);
        return set_transient($cacheKey, $result, $expiration * 24);
    }

    public function clearCache($query)
    {
        $cacheKey = $this->getCacheKey($query);
        return delete_transient($cacheKey);
    }
}
