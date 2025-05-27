<?php

namespace SlimStat\Utils;

trait TransientCacheTrait
{
    public function getCacheKey($input)
    {
        $hash = substr(md5($input), 0, 10);
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
