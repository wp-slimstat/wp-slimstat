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
 * Handles IP hashing and anonymization for GDPR compliance.
 * Processes IPs based on privacy settings and consent status.
 * Hash uses original IP for better uniqueness; anonymization applied after hashing if needed.
 *
 * @since 5.4.0
 */
class IPHashProvider
{
    /**
     * Length of the stored IP hash (must fit DB column, 39 chars matches IPv6 max length).
     */
    public const HASH_LENGTH = 39;
    /**
     * Process IP address according to privacy settings and consent status.
     *
     * This is the main entry point for IP processing in the tracking pipeline.
     *
     * Processing modes:
     * 1. Anonymous tracking WITHOUT consent: Hash only (strictest)
     * 2. Anonymous tracking WITH consent: Store full IP (after consent upgrade)
     * 3. Standard mode WITHOUT PII consent: Anonymize + Hash
     * 4. Standard mode WITH settings: Respect anonymize_ip and hash_ip settings
     * 5. Standard mode WITH PII consent: Store full IP (no processing needed)
     *
     * @param array $stat The slimstat array containing IP data
     * @return array Modified slimstat array with processed IP
     */
    public static function processIp(array $stat): array
    {
        if (empty($stat['ip'])) {
            return $stat;
        }

        // Store original IP for processing (never modify this variable)
        $originalIp = $stat['ip'];
        $originalOtherIp = $stat['other_ip'] ?? '';

        // Determine mode and consent status
        $isAnonymousTracking = 'on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off');
        $piiAllowed = Consent::piiAllowed();

        // Handle consent granted in same request (cookie not set yet or invalid)
        $hasCmpConsentButNoCookie = false;
        if ($isAnonymousTracking && !$piiAllowed) {
            $integrationKey = Consent::getIntegrationKey();

            // Check CMP consent only if integration is configured
            if (!empty($integrationKey)) {
                if ('slimstat_banner' === $integrationKey) {
                    $gdpr_service = new \SlimStat\Services\GDPRService(\wp_slimstat::$settings);
                    if ($gdpr_service->hasConsent()) {
                        $hasCmpConsentButNoCookie = true;
                    }
                    } elseif ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
                    $wpConsentCategory = (string) (\wp_slimstat::$settings['consent_level_integration'] ?? 'statistics');
                    try {
                        if ((bool) \wp_has_consent($wpConsentCategory)) {
                            $hasCmpConsentButNoCookie = true;
                        }
                    } catch (\Throwable $e) {
                        // Ignore errors
                    }
                } elseif ('real_cookie_banner' === $integrationKey) {
                    // Real Cookie Banner fallback: try to read consent from cookie
                    // This handles race conditions where tracking cookie isn't set yet but RCB cookie is present
                    $wpConsentCategory = (string) (\wp_slimstat::$settings['consent_level_integration'] ?? 'statistics');
                    $rcbCookies = ['real_cookie_banner', 'rcb_consent', 'rcb_acceptance', 'real_cookie_consent', 'rcb-consent'];

                    foreach ($_COOKIE as $name => $value) {
                        $isMatch = false;
                        foreach ($rcbCookies as $rcbName) {
                            if (strpos($name, $rcbName) === 0) {
                                $isMatch = true;
                                break;
                            }
                        }

                        if ($isMatch) {
                            // Try to decode value: handle both URL encoded and raw JSON
                            // WP cookies are often slashed, so strip slashes first
                            $rawJson = stripslashes($value);
                            $data = json_decode($rawJson, true);

                            if (json_last_error() !== JSON_ERROR_NONE) {
                                // If failed, try urldecode first
                                $data = json_decode(stripslashes(urldecode($value)), true);
                            }

                            if (is_array($data)) {
                                // Check various structures based on RCB versions

                                // Structure 1: { "groups": { "statistics": true } }
                                if (isset($data['groups'][$wpConsentCategory]) && true === $data['groups'][$wpConsentCategory]) {
                                    $hasCmpConsentButNoCookie = true;
                                    break;
                                }

                                // Structure 2: { "decision": { "statistics": true } } OR { "decision": "all" }
                                if (isset($data['decision'])) {
                                    if ('all' === $data['decision']) {
                                        $hasCmpConsentButNoCookie = true;
                                        break;
                                    }
                                    if (is_array($data['decision']) && isset($data['decision'][$wpConsentCategory]) && true === $data['decision'][$wpConsentCategory]) {
                                        $hasCmpConsentButNoCookie = true;
                                        break;
                                    }
                                }

                                // Structure 3: { "statistics": true } (Legacy/Simplified)
                                if (isset($data[$wpConsentCategory]) && true === $data[$wpConsentCategory]) {
                                    $hasCmpConsentButNoCookie = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Anonymous mode without consent: hash IP (strictest privacy)
        if ($isAnonymousTracking && !$piiAllowed && !$hasCmpConsentButNoCookie) {
            $stat = self::hashIP($stat, $originalIp, $originalOtherIp);

            // Validate hash (39 chars, hex, different from original)
            $hashSucceeded = !empty($stat['ip'])
                && strlen($stat['ip']) === self::HASH_LENGTH
                && ctype_xdigit($stat['ip'])
                && $stat['ip'] !== $originalIp;
            if (!$hashSucceeded) {
                $anonymizedIp = self::anonymizeIP($originalIp);
                if (!empty($anonymizedIp) && $anonymizedIp !== $originalIp) {
                    $stat['ip'] = $anonymizedIp;
                } else {
                    $stat['ip'] = '';
                }

                // Handle other_ip only if present
                if (!empty($originalOtherIp)) {
                    $anonymizedOtherIp = self::anonymizeIP($originalOtherIp);
                    // Validate anonymization succeeded
                    if (!empty($anonymizedOtherIp) && $anonymizedOtherIp !== $originalOtherIp) {
                        $stat['other_ip'] = $anonymizedOtherIp;
                    } else {
                        $stat['other_ip'] = '';
                    }
                }
            }

            return $stat;
        }

        // MODE 2: Anonymous tracking mode WITH consent
        // Consent was granted - allow full IP storage
        // Also handle case where CMP consent exists but tracking cookie hasn't been set yet
        if ($isAnonymousTracking && ($piiAllowed || $hasCmpConsentButNoCookie)) {
            // Keep original IPs, no processing needed
            // Cookie will be set by ensureVisitId() in the same request
            return $stat;
        }

        // MODE 3+: Standard tracking mode (not anonymous)
        // Respect individual privacy settings and consent status

        // IMPORTANT: In Anonymous Tracking Mode, we should NEVER reach here
        // If we do, it means MODE 1 and MODE 2 didn't match, which is a bug
        // In Anonymous Tracking Mode without consent, IP MUST be hashed (handled in MODE 1)
        // In Anonymous Tracking Mode with consent, IP is kept (handled in MODE 2)
        // So if isAnonymousTracking is true, we should have already returned above
        if ($isAnonymousTracking) {
            // This should never happen, but as a safety fallback, hash the IP
            // This ensures GDPR compliance even if there's a logic error
            $stat = self::hashIP($stat, $originalIp, $originalOtherIp);

            // Validate hash succeeded
            $hashSucceeded = !empty($stat['ip'])
                && strlen($stat['ip']) === self::HASH_LENGTH
                && ctype_xdigit($stat['ip'])
                && $stat['ip'] !== $originalIp;

            if (!$hashSucceeded) {
                // Hash failed - anonymize as fallback
                $stat['ip'] = self::anonymizeIP($originalIp);
                if (!empty($originalOtherIp)) {
                    $stat['other_ip'] = self::anonymizeIP($originalOtherIp);
                } else {
                    $stat['other_ip'] = '';
                }
            }

            return $stat;
        }

        // Get individual privacy settings
        $shouldAnonymize = 'on' === (\wp_slimstat::$settings['anonymize_ip'] ?? 'off');
        $shouldHash = 'on' === (\wp_slimstat::$settings['hash_ip'] ?? 'off');

        // If PII is NOT allowed (DNT, consent denied, etc), force maximum privacy
        if (!$piiAllowed) {
            $shouldAnonymize = true;
            $shouldHash = true;
        }

        // Apply processing in correct order:
        // 1. Hash first (if needed) - uses original IP
        // 2. Anonymize after (if needed) - modifies stored IP or provides fallback if hash failed

        if ($shouldHash) {
            // Hash using original IP (before any anonymization)
            // This replaces the IP with a hash value
            $stat = self::hashIP($stat, $originalIp, $originalOtherIp);

            // Check if hashing succeeded
            // Valid hash must be: 39 chars (truncated SHA-256), hexadecimal, and different from original IP
            $hashSucceeded = !empty($stat['ip'])
                && strlen($stat['ip']) === self::HASH_LENGTH
                && ctype_xdigit($stat['ip'])
                && $stat['ip'] !== $originalIp;

            // If hashing failed AND anonymization is enabled, apply anonymization as fallback
            if (!$hashSucceeded && $shouldAnonymize) {
                $stat['ip'] = self::anonymizeIP($originalIp);
                if (!empty($originalOtherIp)) {
                    $stat['other_ip'] = self::anonymizeIP($originalOtherIp);
                } else {
                    $stat['other_ip'] = '';
                }
            }
        } elseif ($shouldAnonymize) {
            // Only anonymize if NOT hashing (hashing already provides privacy)
            $stat['ip'] = self::anonymizeIP($stat['ip']);
            if (!empty($stat['other_ip'])) {
                $stat['other_ip'] = self::anonymizeIP($stat['other_ip']);
            }
        }

        // Note: If neither hash nor anonymize, full IP is stored (requires PII consent)

        return $stat;
    }

    /**
     * Upgrades the stored IP to the real IP if consent is granted.
     *
     * @param array $stat The slimstat array containing IP data
     * @return array Modified slimstat array with the real IP
     */
    public static function upgradeToPii(array $stat): array
    {
        $isAnonymousTracking = 'on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off');
        $piiAllowed          = Consent::piiAllowed(true);

        if (!$isAnonymousTracking || !$piiAllowed) {
            return $stat;
        }

        // Restore the original IP before updating records
        [$stat['ip'], $stat['other_ip']] = \SlimStat\Tracker\Utils::getRemoteIp();

        // Ensure the anonymous visit ID is carried over to the new cookie-based session
        $anonymousVisitId = \SlimStat\Tracker\Session::getVisitId();
        if ($anonymousVisitId > 0) {
            // Force set the cookie, as we are in the consent upgrade flow
            \SlimStat\Tracker\Session::setTrackingCookie($anonymousVisitId, 'visit', null, true);
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
     * Hash IP address with daily salt for GDPR-compliant visitor identification.
     *
     * Creates a one-way hash from the original IP address + user agent + daily salt.
     * The hash changes daily, preventing long-term visitor tracking while allowing
     * same-day uniqueness counting.
     *
     * Hash formula:
     * HMAC-SHA256(daily_salt + "|" + original_ip + "|" + user_agent, secret)
     *
     * Privacy behavior:
     * - Always clears other_ip (proxy information) for privacy, regardless of hash success
     * - On success: IP is replaced with hash
     * - On failure: IP remains original (caller handles privacy fallback via anonymization)
     *
     * Fallback behavior:
     * - If daily salt fails: use Privacy service (date-based hash)
     * - If all hashing fails: returns original IP only (other_ip cleared)
     *
     * @param array  $stat          The slimstat array
     * @param string $originalIp    The original IP address (BEFORE any processing)
     * @param string $originalOtherIp The original other_ip address (if proxy detected) - always cleared for privacy
     * @return array Modified slimstat array with hashed IP (or original if hash failed), other_ip always cleared
     */
    public static function hashIP(array $stat, string $originalIp, string $originalOtherIp = ''): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $secret = \wp_slimstat::$settings['secret'] ?? wp_hash('slimstat');

        // Ensure daily salt exists (generate if missing)
        $dailySalt = self::getDailySalt();
        if (empty($dailySalt)) {
            $dailySalt = self::generateDailySalt();
        }

        // Try to generate hash using daily salt
        if (!empty($dailySalt)) {
            $hash = self::hashWithDailySalt($originalIp, $userAgent, $dailySalt, $secret);
        } else {
            // Fallback to Privacy service (date-based hash)
            $hash = self::hashWithPrivacyService($originalIp, $userAgent, $secret);
        }

            // Validate hash result
        if ($hash !== '' && $hash !== '0') {
            // Hash succeeded - replace IP with hash
            $stat['ip'] = $hash;
        } else {
            // Keep original IP in stat - caller will handle privacy fallback
            $stat['ip'] = $originalIp;
        }

        // Always clear other_ip when hashing is intended (for privacy)
        // The hash represents the unique visitor; storing proxy IP would leak PII
        $stat['other_ip'] = '';

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
        $hash = hash_hmac('sha256', $data, $secret);
        return self::normalizeHashLength($hash);
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
        // Use start of day timestamp to ensure hash consistency throughout the day
        $todayTimestamp = strtotime(gmdate('Y-m-d 00:00:00'));
        $hash = Privacy::computeVisitorId($ip, $userAgent, $todayTimestamp, $secret);
        return self::normalizeHashLength($hash);
    }

    /**
     * Normalize hash output to the configured length, keeping hexadecimal characters.
     *
     * @param string $hash Raw hexadecimal hash string
     * @return string Hash trimmed to HASH_LENGTH characters
     */
    private static function normalizeHashLength(string $hash): string
    {
        if ('' === $hash) {
            return '';
        }

        return substr($hash, 0, self::HASH_LENGTH);
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
     * Get current daily salt (without generating if missing).
     *
     * @return string Daily salt or empty string if not set
     */
    public static function getDailySalt(): string
    {
        $today = gmdate('Y-m-d');
        $existingSalt = get_option('slimstat_daily_salt');
        $saltDate = get_option('slimstat_daily_salt_date');

        // Return salt only if it's for today
        if ($saltDate === $today && !empty($existingSalt)) {
            return $existingSalt;
        }

        return '';
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
