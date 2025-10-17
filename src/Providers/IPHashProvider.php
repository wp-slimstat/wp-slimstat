<?php

namespace SlimStat\Providers;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

use SlimStat\Services\Privacy;
use SlimStat\Utils\Consent;

/**
 * IP Hash Provider
 *
 * Handles IP hashing and anonymization functionality for GDPR compliance.
 * Provides salted hash operations for visitor identification.
 *
 * Features:
 * - IP anonymization using WordPress privacy functions
 * - Salted hash generation with daily salt rotation
 * - Fallback to Privacy service for hash computation
 * - GDPR-compliant visitor identification
 *
 * @since 5.4.0
 */
class IPHashProvider
{
    /**
     * Process IP address according to privacy settings
     *
     * @param array $stat The slimstat array containing IP data
     * @return array Modified slimstat array with processed IP
     */
    public static function processIp(array $stat): array
    {
        if (empty($stat['ip'])) {
            return $stat;
        }

        $originalIp = $stat['ip'];
        $isAnonymousTracking = 'on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off');
        $piiAllowed = Consent::piiAllowed();

        // Step 1: Anonymize IP if enabled OR if anonymous tracking is on OR PII not allowed
        if ($isAnonymousTracking || !$piiAllowed || 'on' === (\wp_slimstat::$settings['anonymize_ip'] ?? 'off')) {
            $stat['ip'] = self::anonymizeIP($stat['ip']);
            if (!empty($stat['other_ip'])) {
                $stat['other_ip'] = self::anonymizeIP($stat['other_ip']);
            }
        }

        // Step 2: Hash IP if enabled OR if anonymous tracking is on OR PII not allowed
        if ($isAnonymousTracking || !$piiAllowed || 'on' === (\wp_slimstat::$settings['hash_ip'] ?? 'off')) {
            $stat = self::hashIP($stat, $originalIp);
        }

        return $stat;
    }

    /**
     * Anonymize IP address using WordPress privacy function
     *
     * @param string $ip The IP address to anonymize
     * @return string Anonymized IP address
     */
    public static function anonymizeIP(string $ip): string
    {
        if (function_exists('wp_privacy_anonymize_ip')) {
            $anonymized = wp_privacy_anonymize_ip($ip);
            if (!empty($anonymized)) {
                return $anonymized;
            }
        }

        // Fallback to Privacy service if WordPress function fails
        return Privacy::maskIp($ip);
    }

    /**
     * Hash IP address with salt for GDPR compliance
     *
     * @param array $stat The slimstat array
     * @param string $originalIp The original IP address
     * @return array Modified slimstat array with hashed IP
     */
    public static function hashIP(array $stat, string $originalIp): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $secret = \wp_slimstat::$settings['secret'] ?? wp_hash('slimstat');

        // Try to use daily salt first
        $dailySalt = get_option('slimstat_daily_salt');
        if (!empty($dailySalt)) {
            $hash = self::hashWithDailySalt($originalIp, $userAgent, $dailySalt, $secret);
        } else {
            // Fallback to Privacy service
            $hash = self::hashWithPrivacyService($originalIp, $userAgent, $secret);
        }

        if ($hash !== '' && $hash !== '0') {
            // Clear other_ip if anonymization is not enabled
            if ('on' !== (\wp_slimstat::$settings['anonymize_ip'] ?? 'off')) {
                $stat['other_ip'] = '';
            }

            $stat['ip'] = $hash;
        }

        return $stat;
    }

    /**
     * Hash IP using daily salt
     *
     * @param string $ip Original IP address
     * @param string $userAgent User agent string
     * @param string $dailySalt Daily salt value
     * @param string $secret Secret key
     * @return string Hashed IP address
     */
    private static function hashWithDailySalt(string $ip, string $userAgent, string $dailySalt, string $secret): string
    {
        $data = $dailySalt . '|' . $ip . '|' . $userAgent;
        return hash_hmac('sha256', $data, $secret);
    }

    /**
     * Hash IP using Privacy service
     *
     * @param string $ip Original IP address
     * @param string $userAgent User agent string
     * @param string $secret Secret key
     * @return string Hashed IP address
     */
    private static function hashWithPrivacyService(string $ip, string $userAgent, string $secret): string
    {
        if (!class_exists(Privacy::class)) {
            @include_once SLIMSTAT_ANALYTICS_DIR . 'src/Services/Privacy.php';
        }

        if (class_exists(Privacy::class)) {
            return Privacy::computeVisitorId($ip, $userAgent, time(), $secret);
        }

        return '';
    }

    /**
     * Generate daily salt for IP hashing
     *
     * @return string Daily salt value
     */
    public static function generateDailySalt(): string
    {
        $today = gmdate('Y-m-d');
        $existingSalt = get_option('slimstat_daily_salt');
        $saltDate = get_option('slimstat_daily_salt_date');

        // Generate new salt if date changed or no salt exists
        if ($saltDate !== $today || empty($existingSalt)) {
            $newSalt = wp_generate_password(32, false);
            update_option('slimstat_daily_salt', $newSalt);
            update_option('slimstat_daily_salt_date', $today);
            return $newSalt;
        }

        return $existingSalt;
    }

    /**
     * Check if IP hashing is enabled
     *
     * @return bool True if IP hashing is enabled
     */
    public static function isHashingEnabled(): bool
    {
        return 'on' === (\wp_slimstat::$settings['hash_ip'] ?? 'off');
    }

    /**
     * Check if IP anonymization is enabled
     *
     * @return bool True if IP anonymization is enabled
     */
    public static function isAnonymizationEnabled(): bool
    {
        return 'on' === (\wp_slimstat::$settings['anonymize_ip'] ?? 'off');
    }
}
