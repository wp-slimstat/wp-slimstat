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
 *
 * IP Processing Pipeline:
 * =======================
 * This class processes IP addresses according to privacy settings and consent status.
 * The order of operations is critical for GDPR compliance:
 *
 * 1. Store original IP (needed for GeoIP lookup before hashing)
 * 2. Determine privacy requirements based on:
 *    - Anonymous tracking mode
 *    - Consent status (via Consent::piiAllowed())
 *    - Individual anonymize_ip and hash_ip settings
 * 3. Apply IP processing in correct order:
 *    a. If hashing required: hash using ORIGINAL IP (for consistency)
 *    b. If anonymization required: anonymize the IP after hashing
 *
 * Why hash uses original IP:
 * - Hashing anonymized IPs reduces uniqueness (many users share same anonymized IP)
 * - Original IP provides better visitor counting while maintaining privacy
 * - Hash is one-way, so original IP cannot be recovered
 *
 * Features:
 * - IP anonymization using WordPress core functions
 * - Salted hash generation with daily salt rotation
 * - Fallback to Privacy service for hash computation
 * - Automatic privacy enforcement in anonymous mode
 * - PII upgrade capability when consent granted
 *
 * @since 5.4.0
 */
class IPHashProvider
{
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

	// MODE 1: Anonymous tracking mode WITHOUT consent
	// STRICTEST mode: MUST protect PII - hash IP only (no anonymization needed after hash)
	if ($isAnonymousTracking && !$piiAllowed) {
		// Hash using original IP for consistency, result replaces IP field
		$stat = self::hashIP($stat, $originalIp, $originalOtherIp);

		// Ensure hash succeeded - if not, anonymize as minimum protection (GDPR requirement)
		$hashSucceeded = !empty($stat['ip']) && strlen($stat['ip']) === 64;
		if (!$hashSucceeded) {
			// Hash failed - must anonymize to protect PII
			$anonymizedIp = self::anonymizeIP($originalIp);

			// Validate anonymization succeeded (result not empty and different from original)
			if (!empty($anonymizedIp) && $anonymizedIp !== $originalIp) {
				$stat['ip'] = $anonymizedIp;
			} else {
				// Critical failure: both hash and anonymization failed
				// In strictest mode, we MUST NOT store original IP - use empty string as ultimate fallback
				$stat['ip'] = '';
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('SlimStat: CRITICAL - Both hash and anonymization failed for IP in anonymous mode');
				}
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
		if ($isAnonymousTracking && $piiAllowed) {
			// Keep original IPs, no processing needed
			return $stat;
		}

		// MODE 3+: Standard tracking mode (not anonymous)
		// Respect individual privacy settings and consent status

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

		// Check if hashing succeeded (hash should be 64 chars for sha256)
		$hashSucceeded = !empty($stat['ip']) && strlen($stat['ip']) === 64;

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
            \SlimStat\Tracker\Session::setTrackingCookie($anonymousVisitId, 'visit');
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
	 * Fallback behavior:
	 * - If daily salt fails: use Privacy service (date-based hash)
	 * - If all hashing fails: returns original IPs (caller handles privacy fallback)
	 *
	 * @param array  $stat          The slimstat array
	 * @param string $originalIp    The original IP address (BEFORE any processing)
	 * @param string $originalOtherIp The original other_ip address (if proxy detected)
	 * @return array Modified slimstat array with hashed IP (or original if hash failed)
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
		// Clear other_ip when hashing (hash represents the unique visitor)
		$stat['other_ip'] = '';
	} else {
		// Hash generation failed - log error and return original stat
		// Caller (processIp) will handle fallback to anonymization if configured
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('SlimStat: IP hash generation failed for IP ' . $originalIp);
		}

		// Keep original IPs in stat - caller will handle privacy fallback
		$stat['ip'] = $originalIp;
		$stat['other_ip'] = $originalOtherIp;
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
        // Use start of day timestamp to ensure hash consistency throughout the day
        $todayTimestamp = strtotime(gmdate('Y-m-d 00:00:00'));
        return Privacy::computeVisitorId($ip, $userAgent, $todayTimestamp, $secret);
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
