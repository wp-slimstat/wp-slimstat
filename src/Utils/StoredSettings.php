<?php

namespace SlimStat\Utils;

/**
 * Keeps runtime-only values out of the stored slimstat_options.
 *
 * Pro's Advanced Whois swaps ip_lookup_service for a nonce'd admin-ajax URL through
 * slimstat_init_options on admin requests. Several paths save wp_slimstat::$settings
 * back, which stored that URL: the link broke when the nonce expired or Pro went
 * inactive, and turning the feature off never restored the user's own lookup URL.
 */
final class StoredSettings
{
    public const DEFAULT_LOOKUP = 'https://ip-api.com/#';

    /**
     * @param array      $options The value about to be stored, or just loaded.
     * @param array|null $stored  The value already stored, whose lookup URL is restored.
     */
    public static function withoutRuntimeOverrides(array $options, ?array $stored = null): array
    {
        if (!self::isOverride($options['ip_lookup_service'] ?? '')) {
            return $options;
        }

        $previous = $stored['ip_lookup_service'] ?? '';
        $options['ip_lookup_service'] = is_string($previous) && '' !== $previous && !self::isOverride($previous) ? $previous : self::DEFAULT_LOOKUP;

        return $options;
    }

    private static function isOverride($url): bool
    {
        return is_string($url) && false !== strpos($url, 'action=slimstat_ip2location_iframe_content');
    }
}
