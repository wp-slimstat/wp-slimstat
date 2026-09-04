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
				$hasCmpConsent = Consent::bannerHasConsentSafe(\wp_slimstat::$settings);
			} elseif ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
				$wpConsentCategory = (string) (\wp_slimstat::$settings['consent_level_integration'] ?? 'statistics');
				try {
					$hasCmpConsent = Consent::wpHasConsentSafe($wpConsentCategory);
				} catch (\Throwable $e) {
					// Ignore errors
				}
			}

			if ($hasCmpConsent) {
				$forceAssign = true;
				$is_new_session = true;
			}
		}

		// In anonymous mode without consent, identity is server-side only (D68 / P2).
		//
		// The identity is `vid_hash` — the full-width HMAC, stamped on EVERY hit — and
		// `visit_id` carries ONLY session semantics: found via the vid_hash probe within
		// the session window, or minted from the sequential counter. The old shape put
		// identity INTO visit_id as a 32-bit truncation with a 5-minute bucket folded in,
		// which is both the stranger-merging collision X3 measured and the new-id-per-
		// bucket churn D68 names.
		$piiAllowed = Consent::piiAllowed($isConsentUpgrade);
		if ($isAnonymousTracking && !$piiAllowed && !$hasCmpConsent) {
			$stat = \wp_slimstat::get_stat();

			// Hex, not raw bytes: $stat travels through sanitize_text_field() at both
			// write terminals, filters, and degradation messages — 32 hex chars survive
			// all of them, raw bytes survive none. Storage packs it to BINARY(16).
			$vid_hash = self::generateAnonymousVidHash($stat);
			$stat['vid_hash'] = $vid_hash;

			$current_timestamp = !empty($stat['dt']) ? intval($stat['dt']) : intval(\wp_slimstat::date_i18n('U'));
			$existing_visit_id = self::findExistingAnonymousVisitId($vid_hash, $current_timestamp);

			if ($existing_visit_id > 0) {
				$stat['visit_id'] = $existing_visit_id;
				\wp_slimstat::set_stat($stat);
				return false; // Same person within the session window: same visit.
			}

			// New session: a sequential id, exactly like the cookie branch. Deriving the
			// id from the identity hash is what made two strangers one visitor (32 bits)
			// and seeded VisitIdGenerator's counter next to the INT UNSIGNED ceiling
			// (it re-seeds from MAX(visit_id)).
			$next_visit_id = VisitIdGenerator::generateNextVisitId();
			if ($next_visit_id <= 0) {
				$next_visit_id = time();
			}

			$stat['visit_id'] = intval($next_visit_id);
			\wp_slimstat::set_stat($stat);
			return true;
		}

		if (isset($_COOKIE['slimstat_tracking_code'])) {
			$identifier = Utils::getValueWithoutChecksum(sanitize_text_field(wp_unslash($_COOKIE['slimstat_tracking_code'])));
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

			// Use atomic counter for thread-safe visit ID generation (O(1) instead of O(n))
			$next_visit_id = VisitIdGenerator::generateNextVisitId();
			if ($next_visit_id <= 0) {
				$next_visit_id = time();
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
	public static function setTrackingCookie($value, $value_type = 'visit', ?int $expires = null, bool $force = false)
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
	 * Find the visit this anonymous person is already in, by identity, within the window.
	 *
	 * The old probe matched request ATTRIBUTES — hashed ip (unindexed), the exact
	 * `resource` being viewed, browser, fingerprint-when-present — so a visitor who
	 * NAVIGATED could never match their own session, and every anonymous pageview paid
	 * an uncached range scan over the 30-minute window. Matching the identity itself
	 * needs one predicate, is served by `idx_vid_hash_dt`, and is immune to the
	 * fingerprint-present-vs-absent asymmetry that made the Ajax and Processor paths
	 * issue differently-shaped queries for the same person.
	 *
	 * `visit_id > 0` is load-bearing: the newest matching row can carry the column
	 * default (a row written by a programmatic hit, or before anonymous mode was on),
	 * and LIMIT 1 without the filter would let that single row hide older good ones.
	 *
	 * @param string $vid_hash          32 hex chars, as generateAnonymousVidHash() returns.
	 * @param int    $current_timestamp The hit's own dt.
	 * @return int Visit ID if found, 0 otherwise
	 */
	private static function findExistingAnonymousVisitId(string $vid_hash, int $current_timestamp): int
	{
		if ('' === $vid_hash) {
			return 0;
		}

		$session_duration = !empty(\wp_slimstat::$settings['session_duration'])
			? intval(\wp_slimstat::$settings['session_duration'])
			: 1800;

		// REACTIVE, NEVER PREEMPTIVE. This runs on every anonymous pageview, so a
		// "does vid_hash exist?" probe here would be one extra SHOW COLUMNS per hit,
		// forever, on healthy installs too — the budget rule Storage.php states. So the
		// question is asked and the failure is caught, rather than the schema being
		// checked first. Memoising the check inside Schema is not the escape either:
		// AddVisitIdentity::shouldRun() asks the same question before and after its own
		// ALTER in one request, and a memo would freeze the migration screen.
		$probe = Query::select('visit_id')
			->from($GLOBALS['wpdb']->prefix . 'slim_stats')
			// UNHEX in SQL rather than hex2bin in PHP so the comparison stays on the
			// BINARY(16) column and the index — HEX(vid_hash) = %s would be a scan.
			->whereRaw('vid_hash = UNHEX(%s)', [$vid_hash])
			->where('visit_id', '>', 0)
			->where('dt', '>=', $current_timestamp - $session_duration)
			->where('dt', '<=', $current_timestamp)
			->orderBy('dt', 'DESC')
			->limit(1);

		$existing_visit_id = $probe->probeVar();

		if ($probe->probeFailed()) {
			// Before AddVisitIdentity runs, vid_hash does not exist and this SELECT errors.
			// Read as "no previous hit", which is what a bare null meant, every anonymous
			// pageview mints a fresh visit_id — so visits inflate to roughly pageviews for
			// the whole pre-migration window, silently. Say so once per request instead.
			self::recordIdentityProbeFailure();

			return 0;
		}

		return $existing_visit_id > 0 ? intval($existing_visit_id) : 0;
	}

	/**
	 * Say once, per request, that the anonymous-identity probe could not run.
	 *
	 * Once, because this sits on the path that runs for EVERY anonymous pageview and
	 * record_degradation() reads the degradations option before it decides whether to write —
	 * so an unguarded call would put a wp_options READ on every hit of a tracked page, which is
	 * the budget rule Storage.php:52-54 forbids. The static is what keeps it to one.
	 *
	 * The visitor sees nothing and tracking continues; the row still lands, it simply opens a
	 * new visit. That degradation is the honest description of what is happening, and it names
	 * the migration that ends it.
	 */
	private static function recordIdentityProbeFailure(): void
	{
		static $recorded = false;

		if ($recorded) {
			return;
		}

		$recorded = true;

		// Under 200 characters, because record_degradation() stores substr($message, 0, 200)
		// and the notice renders what was STORED. The first draft was 236 and lost its last
		// clause — the one naming the migration that ends this — while the docblock above went
		// on claiming it named it.
		\wp_slimstat::record_degradation(
			'anonymous visit reuse',
			'cookieless visitors count as a new visit on every pageview: the identity column '
				. 'could not be read. Tracking still works; visit counts are inflated until the '
				. '"add visit identity" migration has run.',
			\wp_slimstat::DEGRADATION_OPERATIONAL
		);
	}

	/**
	 * The cookieless visitor's identity: 16 raw bytes of HMAC, returned as 32 hex chars.
	 *
	 * Full-width per P2 — the predecessor cut the same HMAC to 32 bits, which is ~50%
	 * collision odds at ~77k cookieless visitors and deterministic collisions by
	 * construction, i.e. two strangers sharing one identity (X3). BINARY(16) of a
	 * SHA-256 HMAC leaves collisions at the 2^-64 birthday bound.
	 *
	 * What is deliberately NOT in the identity:
	 *   - the 5-minute bucket the old formula folded in (`floor(now/300)*300`) — that is
	 *     what minted a new id at every boundary, and it existed to bound reuse of an id
	 *     that was too narrow to be trusted as identity. Session bounds are the dt range
	 *     on the rows themselves now (P2: "session boundaries expressed as a dt range").
	 *   - nothing beyond the day: the DAILY salt stays, on purpose. No cross-day
	 *     identity for non-consenting visitors is a privacy property, not a limitation.
	 *
	 * One derivation serves probe and mint alike (the probe matches this value), which
	 * closes the old split where the probe hashed REMOTE_ADDR and the mint hashed the
	 * forwarded address — two different people according to two halves of one function.
	 *
	 * @param array $stat Current stat array (fingerprint is read when present).
	 * @return string 32 lowercase hex characters; Storage packs them to BINARY(16).
	 */
	public static function generateAnonymousVidHash(array $stat): string
	{
		// One call: generateDailySalt() is get-or-mint, so the old
		// getDailySalt()-then-fall-back pair read the option twice for no behaviour.
		$daily_salt = \SlimStat\Providers\IPHashProvider::generateDailySalt();

		if (empty($daily_salt)) {
			$daily_salt = gmdate('Y-m-d') . self::getSecureKey();
		}

		$fingerprint = $stat['fingerprint'] ?? '';

		if (!empty($fingerprint)) {
			$identity = $daily_salt . '|' . $fingerprint;
		} else {
			[$ip, $other_ip] = Utils::getRemoteIp();
			$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
			$client_ip  = !empty($other_ip) ? $other_ip : $ip;

			$identity = $daily_salt . '|' . $client_ip . '|' . $user_agent;
		}

		return bin2hex(substr(hash_hmac('sha256', $identity, self::getSecureKey(), true), 0, 16));
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
