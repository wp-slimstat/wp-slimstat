<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Consent;
use SlimStat\Utils\Query;

/**
 * Session Management for SlimStat Tracking
 *
 * Handles visit ID assignment and cookie management for session tracking.
 * All cookie operations are centralized here for GDPR compliance.
 *
 * Cookie Policy:
 * ==============
 * - Cookies are ONLY set when PII is allowed (Consent::piiAllowed())
 * - Anonymous tracking mode: NO cookies until explicit consent
 * - Standard mode: Cookies set if set_tracker_cookie=on AND consent granted
 * - All cookies use Secure, HttpOnly, and SameSite=Lax flags for security
 *
 * @since 5.4.0
 */
class Session
{
	/**
	 * Ensure a visit ID is assigned to the current pageview.
	 *
	 * This method handles:
	 * - Reading existing visit ID from cookie (if present and valid)
	 * - Generating new visit ID for new sessions
	 * - In anonymous mode: uses server-side visit ID (hash of IP+UA+daily salt)
	 * - Setting tracking cookie (if consent allows)
	 * - Updating pageview records with visit ID
	 *
	 * @param bool $forceAssign Force assignment of new visit ID even if not in JS mode
	 * @return bool True if a new visit ID was assigned, false if using existing visit ID
	 */
	public static function ensureVisitId($forceAssign = false)
	{
		$is_new_session = true;
		$identifier     = 0;
		$isAnonymousTracking = ('on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off'));

		// Check if we need to upgrade from anonymous to PII tracking
		// This happens when consent was just granted in anonymous mode
		$hasCmpConsent = false;
		$hasTrackingCookie = isset($_COOKIE['slimstat_tracking_code']);

		if ($isAnonymousTracking && !$hasTrackingCookie) {
			// Check if CMP consent exists (but tracking cookie doesn't yet)
			$integrationKey = \wp_slimstat::$settings['consent_integration'] ?? '';

			if ('slimstat_banner' === $integrationKey) {
				$gdpr_service = new \SlimStat\Services\GDPRService(\wp_slimstat::$settings);
				$hasCmpConsent = $gdpr_service->hasConsent();
			} elseif ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
				$wpConsentCategory = (string) (\wp_slimstat::$settings['consent_level_integration'] ?? 'statistics');
				try {
					$hasCmpConsent = (bool) \wp_has_consent($wpConsentCategory);
				} catch (\Throwable $e) {
					// Ignore errors
				}
			}

			// If CMP consent exists but tracking cookie doesn't, upgrade to PII tracking
			// This handles the case where consent was just granted but cookie hasn't been set yet
			if ($hasCmpConsent) {
				// Force assign a new visit ID and set tracking cookie
				// This upgrades from anonymous to PII tracking mode
				$forceAssign = true;
				$is_new_session = true; // Force new session to generate visit ID
				// Skip anonymous visit ID generation - we'll generate a real visit ID below
			}
		}

		// In anonymous tracking mode WITHOUT consent, use server-side visit ID
		// This allows visit tracking without cookies (GDPR-compliant)
		// BUT: Skip this if CMP consent exists (we'll upgrade to PII tracking instead)
		if ($isAnonymousTracking && !Consent::piiAllowed() && !$hasCmpConsent) {
			// Generate deterministic visit ID from hashed IP + User Agent + daily salt
			$identifier = self::generateAnonymousVisitId();
			$stat = \wp_slimstat::get_stat();
			$stat['visit_id'] = $identifier;
			\wp_slimstat::set_stat($stat);
			// Return true because we assigned a visit ID (even though no cookie was set)
			return true;
		}

		// Try to read existing visit ID from cookie
		if (isset($_COOKIE['slimstat_tracking_code'])) {
			$identifier = Utils::getValueWithoutChecksum($_COOKIE['slimstat_tracking_code']);
			if (false === $identifier) {
				// Invalid checksum - ignore cookie
				return false;
			}

			// Check if this is a new session (identifier contains 'id') or existing visit
			$is_new_session = (false !== strpos($identifier, 'id'));
			$identifier     = intval($identifier);
		}

		// Generate new visit ID if this is a new session
		if ($is_new_session && ($forceAssign || 'on' == \wp_slimstat::$settings['javascript_mode'])) {
			// Default session duration if not set
			if (empty(\wp_slimstat::$settings['session_duration'])) {
				\wp_slimstat::$settings['session_duration'] = 1800; // 30 minutes
			}

			$table         = $GLOBALS['wpdb']->prefix . 'slim_stats';

			// Try to get next auto-increment value for efficient ID generation
			$next_visit_id = Query::select('AUTO_INCREMENT')
				->from('information_schema.TABLES')
				->whereRaw("TABLE_SCHEMA = DATABASE()")
				->where('TABLE_NAME', '=', $table)
				->getVar();

			// Fallback: get max visit_id + 1 if auto-increment query fails
			if ($next_visit_id === null || $next_visit_id <= 0) {
				$max_visit_id  = Query::select('COALESCE(MAX(visit_id), 0)')->from($table)->getVar();
				$next_visit_id = intval($max_visit_id) + 1;
			}

			// Last resort fallback: use timestamp
			if ($next_visit_id <= 0) {
				$next_visit_id = time();
			}

			// Ensure visit ID is unique (handle race conditions)
			$existing_visit_id = Query::select('visit_id')->from($table)->where('visit_id', '=', $next_visit_id)->getVar();
			if ($existing_visit_id !== null) {
				do {
					$next_visit_id++;
					$existing_visit_id = Query::select('visit_id')->from($table)->where('visit_id', '=', $next_visit_id)->getVar();
				} while ($existing_visit_id !== null);
			}

			// Assign visit ID
			$stat = \wp_slimstat::get_stat();
			$stat['visit_id'] = intval($next_visit_id);
			\wp_slimstat::set_stat($stat);

			// Set cookie ONLY if consent allows - this is the CENTRAL cookie setting point
			self::setTrackingCookie($stat['visit_id'], 'visit');

			// Return true because we assigned a new visit ID (regardless of cookie success)
			return true;
		} elseif ($identifier > 0) {
			// Existing visit - use identifier from cookie
			$stat = \wp_slimstat::get_stat();
			$stat['visit_id'] = $identifier;
			\wp_slimstat::set_stat($stat);
		}

		// Update old pageview records with visit ID (for JS mode upgrade path)
		if ($is_new_session && $identifier > 0) {
			$stat = \wp_slimstat::get_stat();
			Query::update($GLOBALS['wpdb']->prefix . 'slim_stats')
				->set(['visit_id' => $stat['visit_id']])
				->where('id', '=', $identifier)
				->where('visit_id', '=', 0)
				->execute();
		}

		return false;
	}

	/**
	 * Set or extend the tracking cookie.
	 *
	 * This is the CENTRALIZED cookie setting function - all cookie operations go through here.
	 * Cookies are only set when:
	 * - Consent allows PII collection (Consent::piiAllowed())
	 * - Setting is enabled (set_tracker_cookie=on)
	 * - Filter hook allows it (slimstat_set_visit_cookie)
	 *
	 * GDPR Compliance:
	 * - Uses Secure flag when site is HTTPS
	 * - Uses HttpOnly flag to prevent JavaScript access
	 * - Uses SameSite=Lax for CSRF protection
	 *
	 * @param int    $value      The value to store in cookie (visit_id or pageview id)
	 * @param string $value_type Type of value: 'visit' or 'id' (affects checksum)
	 * @param int    $expires    Optional. Expiration time in seconds. If not provided, uses session_duration.
	 * @param bool   $force      Optional. Force setting the cookie even if consent checks fail.
	 *                           Used internally during the consent upgrade flow.
	 * @return bool True if cookie was set, false if not allowed
	 */
	public static function setTrackingCookie($value, $value_type = 'visit', $expires = null, bool $force = false)
	{
		// Check if PII collection is allowed (handles consent, DNT, anonymous mode)
		$piiAllowed = Consent::piiAllowed();

		// Check if cookie setting is enabled
		$cookieEnabled = !empty(\wp_slimstat::$settings['set_tracker_cookie']) && 'on' == \wp_slimstat::$settings['set_tracker_cookie'];

		// Determine if we should set cookie (allow filter override)
		$shouldSetCookie = apply_filters('slimstat_set_visit_cookie', ($force || ($piiAllowed && $cookieEnabled)));

		if (!$shouldSetCookie) {
			return false;
		}

		// Prepare cookie value with checksum
		if ('id' === $value_type) {
			// For pageview ID, append 'id' suffix before checksum
			$cookie_value = Utils::getValueWithChecksum($value . 'id');
		} else {
			// For visit ID, use value directly
			$cookie_value = Utils::getValueWithChecksum($value);
		}

		// Calculate expiration
		if (null === $expires) {
			$expires = !empty(\wp_slimstat::$settings['session_duration']) ? intval(\wp_slimstat::$settings['session_duration']) : 1800;
		}

		// Prepare cookie options for PHP 7.3+ array syntax
		$cookie_options = [
			'expires'  => time() + $expires,
			'path'     => COOKIEPATH,
			'domain'   => '',
			'secure'   => is_ssl(), // Only send over HTTPS when available
			'httponly' => true,      // Prevent JavaScript access (XSS protection)
			'samesite' => 'Lax',     // CSRF protection, allows navigation from external sites
		];

		// Set the cookie
		$result = @setcookie('slimstat_tracking_code', $cookie_value, $cookie_options);

		// Log failure in debug mode
		if (!$result && defined('WP_DEBUG') && WP_DEBUG) {
			error_log('SlimStat: Failed to set tracking cookie for value ' . $value);
		}

		return $result;
	}

	/**
	 * Delete the tracking cookie.
	 *
	 * Used when:
	 * - Consent is revoked
	 * - User opts out
	 * - Session needs to be cleared
	 *
	 * @return bool True if cookie deletion was attempted, false otherwise
	 */
	public static function deleteTrackingCookie()
	{
		if (!isset($_COOKIE['slimstat_tracking_code'])) {
			return false;
		}

		// Set cookie with expiration in the past to delete it
		$cookie_options = [
			'expires'  => time() - 3600,
			'path'     => COOKIEPATH,
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		];

		@setcookie('slimstat_tracking_code', '', $cookie_options);

		// Also unset from current $_COOKIE array
		unset($_COOKIE['slimstat_tracking_code']);

		return true;
	}

	/**
	 * Generate anonymous visit ID for cookie-less tracking.
	 *
	 * Creates a deterministic visit ID from fingerprint (if available) or hashed IP + User Agent + daily salt.
	 * This allows visit tracking in anonymous mode without cookies.
	 *
	 * Priority Strategy:
	 * 1. If fingerprint is available (from JavaScript): Use fingerprint + daily salt for consistent visit ID
	 * 2. If fingerprint not available: Use IP + User Agent + daily salt + timestamp entropy
	 *
	 * IP Selection Strategy (fallback):
	 * - Prefers other_ip (actual client IP from proxy headers like X-Forwarded-For)
	 * - Falls back to primary IP (REMOTE_ADDR) when other_ip is not available
	 * - This ensures unique visit IDs for users behind shared proxies/CDNs
	 *
	 * Properties:
	 * - Same visitor = same visit ID (within same day)
	 * - Changes daily (due to daily salt rotation)
	 * - No PII stored or transmitted (fingerprint is pseudonymous identifier)
	 * - GDPR-compliant (no tracking across days)
	 *
	 * @return int Visit ID (32-bit integer from hash)
	 */
	private static function generateAnonymousVisitId(): int
	{
		// Get or generate daily salt from IPHashProvider
		$daily_salt = \SlimStat\Providers\IPHashProvider::getDailySalt();
		if (empty($daily_salt)) {
			// Salt not found - generate it now
			$daily_salt = \SlimStat\Providers\IPHashProvider::generateDailySalt();
		}

		// Fallback to date-based salt if generation fails
		if (empty($daily_salt)) {
			$daily_salt = gmdate('Y-m-d') . self::getSecureKey();
		}

		// Try to get fingerprint from current stat (sent from JavaScript)
		$stat = \wp_slimstat::get_stat();
		$fingerprint = $stat['fingerprint'] ?? '';

		// If fingerprint is available, use it for more accurate session tracking
		// This allows tracking the same user across pages without cookies
		if (!empty($fingerprint)) {
			// Create deterministic hash using fingerprint + daily salt
			// This gives us a consistent visit ID for the same visitor on the same day
			$hash_input = $daily_salt . '|' . $fingerprint;
			$hash       = hash_hmac('sha256', $hash_input, self::getSecureKey());

			// Convert first 8 characters of hash to integer (32-bit)
			$visit_id = abs((int) hexdec(substr($hash, 0, 8)));

			return $visit_id;
		}

		// Fallback: Use IP + User Agent if fingerprint not available
		// Get visitor's IP addresses
		[$ip, $other_ip] = Utils::getRemoteIp();
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

		// Use other_ip (actual client IP from proxy headers) when available for better uniqueness
		// Fallback to primary IP (REMOTE_ADDR) when not behind a proxy
		// This prevents multiple users behind the same proxy from getting identical visit IDs
		$client_ip = !empty($other_ip) ? $other_ip : $ip;

		// Security & Privacy: Add entropy for better private browser detection
		// Use first request timestamp (rounded to 5-minute intervals) to distinguish
		// different private browser sessions even with same IP + UA
		// This helps detect new private browser sessions while maintaining GDPR compliance
		$current_timestamp = \wp_slimstat::date_i18n('U');
		// Round to 5-minute intervals to balance uniqueness and privacy
		$timestamp_entropy = floor($current_timestamp / 300) * 300;

		// Create deterministic hash using client IP + User Agent + daily salt + timestamp entropy
		// The timestamp entropy helps distinguish private browser sessions
		// while maintaining reasonable uniqueness (same visitor within 5 minutes gets same visit ID)
		$hash_input = $daily_salt . '|' . $client_ip . '|' . $user_agent . '|' . $timestamp_entropy;
		$hash       = hash_hmac('sha256', $hash_input, self::getSecureKey());

		// Convert first 8 characters of hash to integer (32-bit)
		// This gives us a consistent visit ID for the same visitor on the same day
		$visit_id = abs((int) hexdec(substr($hash, 0, 8)));

		return $visit_id;
	}

	/**
	 * Get a secure key for hashing operations.
	 *
	 * Validates that AUTH_KEY exists and has sufficient entropy before using it.
	 * Falls back to WordPress salts or generates a secure random key if needed.
	 *
	 * Security considerations:
	 * - AUTH_KEY must be defined and non-empty
	 * - Must be longer than 32 characters for sufficient entropy
	 * - Must not be a known default/placeholder value
	 * - Logs warnings when weak keys are detected
	 *
	 * @return string Secure key for HMAC operations
	 */
	private static function getSecureKey(): string
	{
		$key = '';

		// Try AUTH_KEY first (WordPress security constant)
		if (defined('AUTH_KEY') && is_string(AUTH_KEY) && '' !== AUTH_KEY) {
			$key = AUTH_KEY;

			// Validate key has sufficient entropy (at least 32 characters)
			if (strlen($key) < 32) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('SlimStat Security Warning: AUTH_KEY is too short (< 32 chars). Using fallback.');
				}
				$key = '';
			}

			// Check for known weak/default values
			$weak_keys = ['put your unique phrase here', 'your-unique-auth-key', 'change-this'];
			foreach ($weak_keys as $weak_key) {
				if (false !== stripos($key, $weak_key)) {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('SlimStat Security Warning: AUTH_KEY contains default placeholder. Using fallback.');
					}
					$key = '';
					break;
				}
			}
		}

		// Fallback 1: Try WordPress wp_salt() function (uses multiple salt constants)
		if (empty($key) && function_exists('wp_salt')) {
			$key = wp_salt('auth');

			// Validate wp_salt result
			if (empty($key) || strlen($key) < 32) {
				$key = '';
			}
		}

		// Fallback 2: Combine multiple WordPress constants
		if (empty($key)) {
			$constants = ['SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT'];
			$combined  = '';

			foreach ($constants as $constant) {
				if (defined($constant) && is_string(constant($constant)) && '' !== constant($constant)) {
					$combined .= constant($constant);
				}
			}

			if (!empty($combined) && strlen($combined) >= 32) {
				$key = $combined;
			}
		}

		// Fallback 3: Generate a random key (last resort)
		// Note: This will change on each request, reducing determinism but maintaining security
		if (empty($key)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('SlimStat Security Warning: No WordPress security keys found. Generating random key. Visit IDs will not be consistent across requests.');
			}

			// Use WordPress random bytes function if available
			if (function_exists('wp_generate_password')) {
				$key = wp_generate_password(64, true, true);
			} else {
				// PHP 7.0+ fallback
				$key = bin2hex(random_bytes(32));
			}
		}

		return $key;
	}

	/**
	 * Get current visit ID for the session.
	 *
	 * @return int Visit ID or 0 if not set
	 */
	public static function getVisitId(): int
	{
		$stat = \wp_slimstat::get_stat();
		return (int) ($stat['visit_id'] ?? 0);
	}
}
