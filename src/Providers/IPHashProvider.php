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

    /** The salt and the UTC day it was minted for, as ONE value — see claimSaltFor(). */
    private const SALT_OPTION = 'slimstat_daily_salt';

    /** Pre-W5 companion option; read during the one-day upgrade window, never written. */
    private const LEGACY_DATE_OPTION = 'slimstat_daily_salt_date';

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
     * @param bool $explicitConsentGiven Optional. Set to true when consent was explicitly granted
     *                                   in the current request (e.g., consent upgrade flow).
     * @return array Modified slimstat array with processed IP
     */
    public static function processIp(array $stat, bool $explicitConsentGiven = false): array
    {
        if (empty($stat['ip'])) {
            return $stat;
        }

        // Store original IP for processing (never modify this variable)
        $originalIp = $stat['ip'];
        $originalOtherIp = $stat['other_ip'] ?? '';

        // Determine mode and consent status
        $isAnonymousTracking = 'on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off');
        $piiAllowed = Consent::piiAllowed($explicitConsentGiven);

        // Handle consent granted in same request (cookie not set yet or invalid)
        $hasCmpConsentButNoCookie = false;
        if ($isAnonymousTracking && !$piiAllowed) {
            $integrationKey = Consent::getIntegrationKey();

            // Check CMP consent only if integration is configured
            if (!empty($integrationKey)) {
                if ('slimstat_banner' === $integrationKey) {
                    if (Consent::bannerHasConsentSafe(\wp_slimstat::$settings)) {
                        $hasCmpConsentButNoCookie = true;
                    }
                    } elseif ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
                    $wpConsentCategory = (string) (\wp_slimstat::$settings['consent_level_integration'] ?? 'statistics');
                    try {
                        if (\SlimStat\Utils\Consent::wpHasConsentSafe($wpConsentCategory)) {
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
                            $sanitized_value = wp_unslash($value);
                            $rawJson = stripslashes($sanitized_value);
                            $data = json_decode($rawJson, true);

                            if (json_last_error() !== JSON_ERROR_NONE) {
                                // If failed, try urldecode first
                                $data = json_decode(stripslashes(urldecode($sanitized_value)), true);
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
        // Consent was granted - but still respect anonymize_ip setting
        // Also handle case where CMP consent exists but tracking cookie hasn't been set yet
        if ($isAnonymousTracking && ($piiAllowed || $hasCmpConsentButNoCookie)) {
            // Check if anonymize_ip setting is enabled
            $shouldAnonymize = 'on' === (\wp_slimstat::$settings['anonymize_ip'] ?? 'off');

            if ($shouldAnonymize) {
                // Anonymize IP even with consent if setting is enabled
                $stat['ip'] = self::anonymizeIP($originalIp);
                if (!empty($originalOtherIp)) {
                    $stat['other_ip'] = self::anonymizeIP($originalOtherIp);
                } else {
                    $stat['other_ip'] = '';
                }
            } else {
                // Keep original IPs if anonymize_ip is not enabled
            }
            // Cookie will be set by ensureVisitId() in the same request
            return $stat;
        }

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
     * Respects anonymize_ip setting even after consent is granted.
     *
     * @param array $stat The slimstat array containing IP data
     * @return array Modified slimstat array with the real IP (or anonymized if setting enabled)
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

        // Check if anonymize_ip setting is enabled - it should always be respected
        $shouldAnonymize = 'on' === (\wp_slimstat::$settings['anonymize_ip'] ?? 'off');
        if ($shouldAnonymize) {
            // Anonymize IP even after consent upgrade if setting is enabled
            $stat['ip'] = self::anonymizeIP($stat['ip']);
            if (!empty($stat['other_ip'])) {
                $stat['other_ip'] = self::anonymizeIP($stat['other_ip']);
            }
        }

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
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $secret = \wp_slimstat::$settings['secret'] ?? wp_hash('slimstat');

        // Get today's salt, minting it if this is the first request of the UTC day.
        // One call: generateDailySalt() returns the stored salt without writing when
        // one already exists, so the old getDailySalt()-then-fall-back pair was an
        // extra get_option() on the tracking path for no behaviour.
        $dailySalt = self::generateDailySalt();
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
        $today  = gmdate('Y-m-d');
        $stored = get_option(self::SALT_OPTION);

        return self::saltFor($stored, $today) ?: self::claimSaltFor($today, $stored);
    }

    /**
     * Today's salt out of a stored value — current shape or pre-W5 — or '' if there
     * is none. One convention ('' means absent) across the whole family, which is the
     * contract getDailySalt() already published and hashIP() already tests with empty().
     */
    private static function saltFor($stored, string $day): string
    {
        if (is_array($stored) && ($stored['date'] ?? '') === $day && !empty($stored['salt'])) {
            return (string) $stored['salt'];
        }

        return self::legacySaltFor($stored, $day);
    }

    /**
     * The pre-W5 shape: a bare string here plus the date in its own option.
     *
     * Honoured for the rest of the day it was minted on. Rotating it on sight would
     * re-hash every visitor halfway through the day, splitting that day's hashes into
     * two uncorrelatable populations — the same damage the race causes, delivered by
     * the upgrade instead. The window closes by itself at the next UTC midnight, and
     * it is a date comparison rather than a version check so it cannot outlive its
     * purpose on an install that upgrades late.
     */
    private static function legacySaltFor($stored, string $day): string
    {
        if (!is_string($stored) || '' === $stored) {
            return '';
        }

        return get_option(self::LEGACY_DATE_OPTION) === $day ? $stored : '';
    }

    /**
     * Mint today's salt, letting exactly one concurrent request win.
     *
     * generateDailySalt() runs on EVERY page load, so at UTC midnight whatever traffic
     * the site has enters this together. The old read-check-write let all of them mint
     * and all of them write, and the last writer won — so requests served in between
     * hashed against a salt that no longer existed a moment later, and the day's
     * hashes stopped being correlatable with each other, which is the one property a
     * daily salt exists to provide.
     *
     * The write is therefore conditional on the exact bytes this request read. Only
     * one UPDATE can match them; the losers affect zero rows and adopt the winner's
     * value. Written through $wpdb rather than update_option() because the options API
     * has no compare-and-swap, and add_option() is not the answer either — it does a
     * PHP-level get_option() pre-check and then INSERT ... ON DUPLICATE KEY UPDATE,
     * which overwrites, so the unique index never rejects anybody.
     */
    private static function claimSaltFor(string $today, $seen): string
    {
        global $wpdb;

        $salt      = wp_generate_password(64, false);
        $candidate = maybe_serialize(['date' => $today, 'salt' => $salt]);

        $suppressed = $wpdb->suppress_errors(true);

        if (false === $seen) {
            // No row yet. A bare INSERT, so the unique index picks the winner.
            // autoload stays 'yes': this is read on every page load, and moving it out
            // of alloptions trades one write a day for a SELECT on every request.
            $won = $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'yes')",
                self::SALT_OPTION,
                $candidate
            ));
        } else {
            $won = $wpdb->query($wpdb->prepare(
                "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $candidate,
                self::SALT_OPTION,
                maybe_serialize($seen)
            ));
        }

        $wpdb->suppress_errors($suppressed);

        // A hard error is not a lost race, and conflating them is expensive: on a
        // read-only replica or a full disk the write can never succeed, and this runs
        // on every page load — so invalidating alloptions here would make every
        // request pay a full rebuild (measured: 2.57 ms) forever. Nobody swapped the
        // row, so nothing is stale and there is nothing to re-read.
        if (false === $won) {
            return $salt;
        }

        // The row moved behind the options API's back. 'alloptions' is the one that
        // actually held it (the row is autoloaded); 'notoptions' matters on the first
        // mint, where the pre-write miss cached its non-existence.
        wp_cache_delete(self::SALT_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');

        if ($won) {
            // The legacy pair is provably retired: this is only reached once
            // legacySaltFor() has declined, so no request can still be using it.
            if (is_string($seen)) {
                delete_option(self::LEGACY_DATE_OPTION);
            }

            return $salt;
        }

        // Lost the race. Take the winner's salt — everyone serving this day has to
        // agree, and "mine is as good as theirs" is precisely the split being fixed.
        // Falls back to this request's own salt rather than '', which callers read as
        // "hashing unavailable".
        return self::saltFor(get_option(self::SALT_OPTION), $today) ?: $salt;
    }

    /**
     * Get current daily salt (without generating if missing).
     *
     * @return string Daily salt or empty string if not set
     */
    public static function getDailySalt(): string
    {
        return self::saltFor(get_option(self::SALT_OPTION), gmdate('Y-m-d'));
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
