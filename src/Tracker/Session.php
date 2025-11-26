<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Consent;
use SlimStat\Utils\Query;

/**
 * Session Management for SlimStat Tracking
 *
 * Handles visit ID assignment and cookie management.
 * Cookies are only set when PII is allowed and consent is granted.
 *
 * @since 5.4.0
 */
class Session
{
	/**
	 * Ensure a visit ID is assigned to the current pageview.
	 *
	 * @param bool $forceAssign Force assignment of new visit ID even if not in JS mode
	 * @return bool True if a new visit ID was assigned, false if using existing visit ID
	 */
	public static function ensureVisitId($forceAssign = false)
	{
		$is_new_session = true;
		$identifier     = 0;
		$isAnonymousTracking = ('on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off'));

		// Check if this is a consent upgrade request
		$data_js = \wp_slimstat::get_data_js();
		$isConsentUpgrade = !empty($data_js['consent_upgrade']) && '1' === $data_js['consent_upgrade'];

		// Check if we need to upgrade from anonymous to PII tracking
		$hasCmpConsent = false;
		$hasTrackingCookie = isset($_COOKIE['slimstat_tracking_code']);

		if ($isAnonymousTracking && !$hasTrackingCookie) {
			$integrationKey = Consent::getIntegrationKey();

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

			if ($hasCmpConsent) {
				$forceAssign = true;
				$is_new_session = true;
			}
		}

		// In anonymous mode without consent, use server-side visit ID
		$piiAllowed = Consent::piiAllowed($isConsentUpgrade);
		if ($isAnonymousTracking && !$piiAllowed && !$hasCmpConsent) {
			// Try to reuse existing visit_id from recent records to prevent duplicates
			$stat = \wp_slimstat::get_stat();
			$existing_visit_id = self::findExistingAnonymousVisitId($stat);

			if ($existing_visit_id > 0) {
				$stat['visit_id'] = $existing_visit_id;
				\wp_slimstat::set_stat($stat);
				return false; // Not a new session, using existing visit_id
			}

			// No existing record found, generate new visit_id
			$identifier = self::generateAnonymousVisitId();
			$stat['visit_id'] = $identifier;
			\wp_slimstat::set_stat($stat);
			return true;
		}

		if (isset($_COOKIE['slimstat_tracking_code'])) {
			$identifier = Utils::getValueWithoutChecksum($_COOKIE['slimstat_tracking_code']);
			if (false === $identifier) {
				return false;
			}

			$is_new_session = (false !== strpos($identifier, 'id'));
			$identifier     = intval($identifier);
		} else {
			// If no cookie and forceAssign is true (e.g., consent upgrade), create new session
			if ($forceAssign) {
				$is_new_session = true;
			}
		}

		if ($is_new_session && ($forceAssign || 'on' == \wp_slimstat::$settings['javascript_mode'])) {
			if (empty(\wp_slimstat::$settings['session_duration'])) {
				\wp_slimstat::$settings['session_duration'] = 1800;
			}

			$table = $GLOBALS['wpdb']->prefix . 'slim_stats';

			$next_visit_id = Query::select('AUTO_INCREMENT')
				->from('information_schema.TABLES')
				->whereRaw("TABLE_SCHEMA = DATABASE()")
				->where('TABLE_NAME', '=', $table)
				->getVar();

			if ($next_visit_id === null || $next_visit_id <= 0) {
				$max_visit_id  = Query::select('COALESCE(MAX(visit_id), 0)')->from($table)->getVar();
				$next_visit_id = intval($max_visit_id) + 1;
			}

			if ($next_visit_id <= 0) {
				$next_visit_id = time();
			}

			$existing_visit_id = Query::select('visit_id')->from($table)->where('visit_id', '=', $next_visit_id)->getVar();
			if ($existing_visit_id !== null) {
				do {
					$next_visit_id++;
					$existing_visit_id = Query::select('visit_id')->from($table)->where('visit_id', '=', $next_visit_id)->getVar();
				} while ($existing_visit_id !== null);
			}

			$stat = \wp_slimstat::get_stat();
			$stat['visit_id'] = intval($next_visit_id);
			\wp_slimstat::set_stat($stat);

			self::setTrackingCookie($stat['visit_id'], 'visit');

			return true;
		} elseif ($identifier > 0) {
			$stat = \wp_slimstat::get_stat();
			$stat['visit_id'] = $identifier;
			\wp_slimstat::set_stat($stat);
		}

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
	 * Cookies are only set when consent allows PII collection and setting is enabled.
	 *
	 * @param int    $value      The value to store in cookie (visit_id or pageview id)
	 * @param string $value_type Type of value: 'visit' or 'id' (affects checksum)
	 * @param int    $expires    Optional. Expiration time in seconds. If not provided, uses session_duration.
	 * @param bool   $force      Optional. Force setting the cookie even if consent checks fail.
	 * @return bool True if cookie was set, false if not allowed
	 */
	public static function setTrackingCookie($value, $value_type = 'visit', $expires = null, bool $force = false)
	{
		// Check if this is a consent upgrade request
		$data_js = \wp_slimstat::get_data_js();
		$isConsentUpgrade = !empty($data_js['consent_upgrade']) && '1' === $data_js['consent_upgrade'];

		$piiAllowed = Consent::piiAllowed($isConsentUpgrade);
		$cookieEnabled = !empty(\wp_slimstat::$settings['set_tracker_cookie']) && 'on' == \wp_slimstat::$settings['set_tracker_cookie'];
		$shouldSetCookie = apply_filters('slimstat_set_visit_cookie', ($force || ($piiAllowed && $cookieEnabled)));

		if (!$shouldSetCookie) {
			return false;
		}

		if ('id' === $value_type) {
			$cookie_value = Utils::getValueWithChecksum($value . 'id');
		} else {
			$cookie_value = Utils::getValueWithChecksum($value);
		}

		if (null === $expires) {
			$expires = !empty(\wp_slimstat::$settings['session_duration']) ? intval(\wp_slimstat::$settings['session_duration']) : 1800;
		}

		$cookie_options = [
			'expires'  => time() + $expires,
			'path'     => COOKIEPATH,
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		];

		$result = @setcookie('slimstat_tracking_code', $cookie_value, $cookie_options);

		return $result;
	}

	/**
	 * Delete the tracking cookie.
	 *
	 * @return bool True if cookie deletion was attempted, false otherwise
	 */
	public static function deleteTrackingCookie()
	{
		if (!isset($_COOKIE['slimstat_tracking_code'])) {
			return false;
		}

		$cookie_options = [
			'expires'  => time() - 3600,
			'path'     => COOKIEPATH,
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		];

		@setcookie('slimstat_tracking_code', '', $cookie_options);
		unset($_COOKIE['slimstat_tracking_code']);

		return true;
	}

	/**
	 * Find existing visit_id from recent records in anonymous mode.
	 *
	 * Checks if a record exists with the same IP (hashed), User Agent, and resource
	 * within the session duration to prevent duplicate records on page refresh.
	 *
	 * @param array $stat Current stat array
	 * @return int Visit ID if found, 0 otherwise
	 */
	private static function findExistingAnonymousVisitId(array $stat): int
	{
		if (empty($stat['resource'])) {
			return 0;
		}

		// Get original IP before hashing
		[$originalIp, $originalOtherIp] = Utils::getRemoteIp();
		if (empty($originalIp)) {
			return 0;
		}

		// Hash IP the same way IPHashProvider does in anonymous mode
		$hashedStat = ['ip' => $originalIp, 'other_ip' => $originalOtherIp];
		$hashedStat = \SlimStat\Providers\IPHashProvider::hashIP($hashedStat, $originalIp, $originalOtherIp);
		$hashedIp = $hashedStat['ip'] ?? '';

		if (empty($hashedIp)) {
			return 0;
		}

		$session_duration = !empty(\wp_slimstat::$settings['session_duration'])
			? intval(\wp_slimstat::$settings['session_duration'])
			: 1800;

		$current_timestamp = !empty($stat['dt']) ? intval($stat['dt']) : \wp_slimstat::date_i18n('U');
		$min_timestamp = $current_timestamp - $session_duration;

		$table = $GLOBALS['wpdb']->prefix . 'slim_stats';

		// Build query to find existing record with same hashed IP, resource, and within session duration
		$query = Query::select('visit_id')
			->from($table)
			->where('ip', '=', $hashedIp)
			->where('resource', '=', $stat['resource'])
			->where('dt', '>=', $min_timestamp)
			->where('dt', '<=', $current_timestamp);

		// If fingerprint is available, also match by fingerprint for better accuracy
		if (!empty($stat['fingerprint'])) {
			$query->where('fingerprint', '=', $stat['fingerprint']);
		}

		// Also match by user agent if available
		if (!empty($stat['browser'])) {
			$query->where('browser', '=', $stat['browser']);
		}

		$existing_visit_id = $query->orderBy('dt', 'DESC')
			->limit(1)
			->getVar();

		return $existing_visit_id > 0 ? intval($existing_visit_id) : 0;
	}

	/**
	 * Generate anonymous visit ID for cookie-less tracking.
	 *
	 * Uses fingerprint if available, otherwise falls back to IP + User Agent + daily salt.
	 *
	 * @return int Visit ID (32-bit integer from hash)
	 */
	public static function generateAnonymousVisitId(): int
	{
		$daily_salt = \SlimStat\Providers\IPHashProvider::getDailySalt();
		if (empty($daily_salt)) {
			$daily_salt = \SlimStat\Providers\IPHashProvider::generateDailySalt();
		}

		if (empty($daily_salt)) {
			$daily_salt = gmdate('Y-m-d') . self::getSecureKey();
		}

		$stat = \wp_slimstat::get_stat();
		$fingerprint = $stat['fingerprint'] ?? '';

		if (!empty($fingerprint)) {
			$hash_input = $daily_salt . '|' . $fingerprint;
			$hash       = hash_hmac('sha256', $hash_input, self::getSecureKey());
			$visit_id = abs((int) hexdec(substr($hash, 0, 8)));

			return $visit_id;
		}

		[$ip, $other_ip] = Utils::getRemoteIp();
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$client_ip = !empty($other_ip) ? $other_ip : $ip;

		$current_timestamp = \wp_slimstat::date_i18n('U');
		$timestamp_entropy = floor($current_timestamp / 300) * 300;

		$hash_input = $daily_salt . '|' . $client_ip . '|' . $user_agent . '|' . $timestamp_entropy;
		$hash       = hash_hmac('sha256', $hash_input, self::getSecureKey());
		$visit_id = abs((int) hexdec(substr($hash, 0, 8)));

		return $visit_id;
	}

	/**
	 * Get a secure key for hashing operations.
	 *
	 * @return string Secure key for HMAC operations
	 */
	private static function getSecureKey(): string
	{
		$key = '';

		if (defined('AUTH_KEY') && is_string(AUTH_KEY) && '' !== AUTH_KEY) {
			$key = AUTH_KEY;

			if (strlen($key) < 32) {
				$key = '';
			}

			$weak_keys = ['put your unique phrase here', 'your-unique-auth-key', 'change-this'];
			foreach ($weak_keys as $weak_key) {
				if (false !== stripos($key, $weak_key)) {
					$key = '';
					break;
				}
			}
		}

		if (empty($key) && function_exists('wp_salt')) {
			$key = wp_salt('auth');

			if (empty($key) || strlen($key) < 32) {
				$key = '';
			}
		}

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

		if (empty($key)) {
			if (function_exists('wp_generate_password')) {
				$key = wp_generate_password(64, true, true);
			} else {
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
