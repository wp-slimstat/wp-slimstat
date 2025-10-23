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

		// In anonymous tracking mode WITHOUT consent, use server-side visit ID
		// This allows visit tracking without cookies (GDPR-compliant)
		if ($isAnonymousTracking && !Consent::piiAllowed()) {
			// Generate deterministic visit ID from hashed IP + User Agent + daily salt
			$identifier = self::generateAnonymousVisitId();
			\wp_slimstat::$stat['visit_id'] = $identifier;
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
			\wp_slimstat::$stat['visit_id'] = intval($next_visit_id);

			// Set cookie ONLY if consent allows - this is the CENTRAL cookie setting point
			self::setTrackingCookie(\wp_slimstat::$stat['visit_id'], 'visit');

			// Return true because we assigned a new visit ID (regardless of cookie success)
			return true;
		} elseif ($identifier > 0) {
			// Existing visit - use identifier from cookie
			\wp_slimstat::$stat['visit_id'] = $identifier;
		}

		// Update old pageview records with visit ID (for JS mode upgrade path)
		if ($is_new_session && $identifier > 0) {
			Query::update($GLOBALS['wpdb']->prefix . 'slim_stats')
				->set(['visit_id' => \wp_slimstat::$stat['visit_id']])
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
	 * @return bool True if cookie was set, false if not allowed
	 */
	public static function setTrackingCookie($value, $value_type = 'visit', $expires = null)
	{
		// Check if PII collection is allowed (handles consent, DNT, anonymous mode)
		$piiAllowed = Consent::piiAllowed();

		// Check if cookie setting is enabled
		$cookieEnabled = !empty(\wp_slimstat::$settings['set_tracker_cookie']) && 'on' == \wp_slimstat::$settings['set_tracker_cookie'];

		// Determine if we should set cookie (allow filter override)
		$shouldSetCookie = apply_filters('slimstat_set_visit_cookie', ($piiAllowed && $cookieEnabled));

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
	 * Creates a deterministic visit ID from hashed IP + User Agent + daily salt.
	 * This allows visit tracking in anonymous mode without cookies.
	 *
	 * IP Selection Strategy:
	 * - Prefers other_ip (actual client IP from proxy headers like X-Forwarded-For)
	 * - Falls back to primary IP (REMOTE_ADDR) when other_ip is not available
	 * - This ensures unique visit IDs for users behind shared proxies/CDNs
	 *
	 * Properties:
	 * - Same visitor = same visit ID (within same day)
	 * - Changes daily (due to daily salt rotation)
	 * - No PII stored or transmitted
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
			$daily_salt = gmdate('Y-m-d') . AUTH_KEY;
		}

		// Get visitor's IP addresses
		[$ip, $other_ip] = Utils::getRemoteIp();
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

		// Use other_ip (actual client IP from proxy headers) when available for better uniqueness
		// Fallback to primary IP (REMOTE_ADDR) when not behind a proxy
		// This prevents multiple users behind the same proxy from getting identical visit IDs
		$client_ip = !empty($other_ip) ? $other_ip : $ip;

		// Create deterministic hash using client IP + User Agent + daily salt
		$hash_input = $daily_salt . '|' . $client_ip . '|' . $user_agent;
		$hash       = hash_hmac('sha256', $hash_input, AUTH_KEY);

		// Convert first 8 characters of hash to integer (32-bit)
		// This gives us a consistent visit ID for the same visitor on the same day
		$visit_id = abs((int) hexdec(substr($hash, 0, 8)));

		return $visit_id;
	}

	/**
	 * Get current visit ID for the session.
	 *
	 * @return int Visit ID or 0 if not set
	 */
	public static function getVisitId(): int
	{
		return (int) (\wp_slimstat::$stat['visit_id'] ?? 0);
	}
}
