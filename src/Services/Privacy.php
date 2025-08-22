<?php

namespace SlimStat\Services;

/**
 * Privacy helper: masking and hashing visitor identifiers.
 *
 * Visitor ID formula (daily uniqueness):
 *   HMAC_SHA256( masked_ip + "|" + user_agent + "|" + Ymd , secret )
 *
 * Masking uses WordPress' wp_privacy_anonymize_ip for consistency.
 */
class Privacy
{
    /**
     * Returns the masked version of an IP (IPv4/IPv6) using WP core if available.
     */
    public static function maskIp(string $ip): string
    {
        if (function_exists('wp_privacy_anonymize_ip')) {
            $masked = wp_privacy_anonymize_ip($ip);
            if (!empty($masked)) {
                return $masked;
            }
        }

        // Fallback simple IPv4 masking: zero last octet; IPv6: collapse last 5 hextets.
        if (false !== strpos($ip, ':')) { // IPv6
            $parts = explode(':', $ip);
            $keep  = array_slice($parts, 0, 3);
            return implode(':', $keep) . '::';
        }

        $octets = explode('.', $ip);
        if (4 === count($octets)) {
            $octets[3] = '0';
            return implode('.', $octets);
        }

        return $ip; // Unknown format, return as-is.
    }

    /**
     * Compute daily visitor hash (stable within same day, changes each day).
     * Always uses the masked IP (even if user stores full IP) to reduce identifiability.
     */
    public static function computeVisitorId(string $ip, string $userAgent, int $timestamp, string $secret): string
    {
        $masked = self::maskIp($ip);
        $date   = gmdate('Ymd', $timestamp ?: time());
        $data   = $masked . '|' . substr($userAgent, 0, 512) . '|' . $date; // limit UA length
        return hash_hmac('sha256', $data, $secret);
    }
}
