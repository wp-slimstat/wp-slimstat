<?php
declare(strict_types=1);

namespace SlimStat\Utils;

/**
 * Centralized consent utility for tracking eligibility and PII handling.
 *
 * GDPR Compliance Strategy:
 * ========================
 * This class implements a multi-layered consent approach:
 *
 * 1. Anonymous Tracking Mode (anonymous_tracking=on):
 *    - Default: NO PII collection (no cookies, hashed IPs, no username/email)
 *    - After explicit consent: upgrade to full PII tracking
 *    - Use case: GDPR-compliant by default, opt-in for enhanced features
 *
 * 2. Standard Tracking Mode (anonymous_tracking=off):
 *    - Checks if configuration collects PII (cookies OR full IPs)
 *    - If PII collected: requires CMP consent OR falls back to conservative default
 *    - If no PII: tracking allowed (cookie-less + anonymized/hashed IPs)
 *
 * 3. Do Not Track (DNT) Header:
 *    - When enabled (do_not_track=on): blocks ALL tracking when DNT=1 header present
 *    - Supersedes all other consent mechanisms
 *
 * 4. CMP Integration:
 *    - WP Consent API: reads server-side consent status
 *    - Real Cookie Banner / Borlabs: conservative (assume no consent server-side)
 *    - None: allows tracking unless anonymous mode requires consent
 *
 * Filter Hook Integration:
 * =======================
 * External plugins can override consent decisions:
 * - apply_filters('slimstat_can_track', bool) - global tracking permission
 *
 * @since 5.4.0
 */
class Consent
{
	/**
	 * Determine whether SlimStat is allowed to track the current request.
	 *
	 * This is the PRIMARY consent gate. If this returns false, no tracking occurs at all.
	 *
	 * Decision tree:
	 * 1. Check DNT header (if enabled in settings)
	 * 2. Apply 'slimstat_can_track' filter for external override
	 * 3. Return final decision
	 *
	 * @return bool True if tracking is allowed, false otherwise
	 */
	public static function canTrack(): bool
	{
		$settings = \wp_slimstat::$settings;
		$default  = true;

		// Respect Do Not Track if enabled in settings
		$respectDnt = ('on' === ($settings['do_not_track'] ?? 'off'));
		if ($respectDnt) {
			$dntHeader = $_SERVER['HTTP_DNT'] ?? '';
			if ('1' === $dntHeader) {
				$default = false;
			}
		}

		/**
		 * Filter: slimstat_can_track
		 *
		 * Allows third parties (e.g., CMP plugins) to declare if analytics tracking is allowed.
		 * Return true to allow tracking, false to disable it.
		 *
		 * @param bool $default Default decision (DNT-aware)
		 */
		$canTrack = (bool) apply_filters('slimstat_can_track', $default);

		return $canTrack;
	}

	/**
	 * Determine whether PII (Personally Identifiable Information) collection is allowed.
	 *
	 * This is the SECONDARY consent gate. Even if tracking is allowed, PII may be restricted.
	 *
	 * PII includes:
	 * - Cookies (tracking cookies for session management)
	 * - Full IP addresses (not anonymized or hashed)
	 * - Username and email (for logged-in users)
	 * - Any other identifiable data
	 *
	 * Decision tree:
	 * 1. HIGHEST PRIORITY: Anonymous tracking mode
	 *    - If enabled: PII NEVER allowed unless explicit consent given
	 *
	 * 2. Check DNT header (if enabled in settings)
	 *    - If DNT=1: PII NEVER allowed (regardless of other settings)
	 *
	 * 3. Determine if current configuration collects PII:
	 *    - Cookies enabled? → collects PII
	 *    - Full IPs stored (not anonymized AND not hashed)? → collects PII
	 *
	 * 4. If configuration doesn't collect PII:
	 *    - Return true (no PII to protect, operations allowed)
	 *
	 * 5. If configuration collects PII:
	 *    - Check CMP consent status (if CMP integration enabled)
	 *    - WP Consent API: read server-side consent
	 *    - Other CMPs: conservative default (no consent)
	 *    - No CMP: allow (legacy behavior, but not GDPR-safe)
	 *
	 * @param bool $explicitConsentGiven Optional. Set to true when consent was explicitly granted
	 *                                   in the current request (e.g., consent upgrade flow).
	 *                                   Only relevant for anonymous tracking mode.
	 *
	 * @return bool True if PII collection is allowed, false otherwise
	 */
	public static function piiAllowed(bool $explicitConsentGiven = false): bool
	{
		$settings = \wp_slimstat::$settings;

		// PRIORITY 1: Anonymous tracking mode - strictest setting
		// In this mode, PII is BLOCKED by default until explicit consent is granted
		$isAnonymousTracking = ('on' === ($settings['anonymous_tracking'] ?? 'off'));
		if ($isAnonymousTracking) {
			// Only allow PII if we have received an explicit consent signal
			// (e.g., from consent upgrade AJAX handler)
			return $explicitConsentGiven;
		}

		// PRIORITY 2: Do Not Track header - user explicitly requests no tracking
		// This supersedes all consent mechanisms when enabled
		$respectDnt = ('on' === ($settings['do_not_track'] ?? 'off'));
		if ($respectDnt) {
			$dntHeader = $_SERVER['HTTP_DNT'] ?? '';
			if ('1' === $dntHeader) {
				// DNT header present - NEVER allow PII
				return false;
			}
		}

		// PRIORITY 3: Determine if current configuration collects PII
		$setTrackerCookie = ('on' === ($settings['set_tracker_cookie'] ?? 'on'));
		$anonymizeIp      = ('on' === ($settings['anonymize_ip'] ?? 'off'));
		$hashIp           = ('on' === ($settings['hash_ip'] ?? 'off'));

		// We collect PII if:
		// - Cookies are enabled (identifies returning visitors) OR
		// - Full IPs are stored (not anonymized AND not hashed)
		$collectsPii = ($setTrackerCookie || (!$anonymizeIp && !$hashIp));

		// If configuration doesn't collect PII, then PII operations are allowed
		// (because there's no PII to protect in the first place)
		if (!$collectsPii) {
			return true;
		}

		// PRIORITY 4: Configuration DOES collect PII - check consent status
		$integrationKey = $settings['consent_integration'] ?? '';

		// WP Consent API integration - can read consent server-side
		if ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
			$wpConsentCategory = (string) ($settings['consent_level_integration'] ?? 'statistics');
			try {
				return (bool) \wp_has_consent($wpConsentCategory);
			} catch (\Throwable $e) {
				// Consent API error - be conservative, deny PII
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('SlimStat: WP Consent API error - ' . $e->getMessage());
				}
				return false;
			}
		}

		// Real Cookie Banner / Borlabs Cookie - cannot read consent server-side
		// These CMPs block scripts client-side, so server should be conservative
		if (in_array($integrationKey, ['real_cookie_banner', 'borlabs_cookie'], true)) {
			// Conservative: assume no consent on server-side
			// Client-side JS will handle consent gating
			return false;
		}

		// PRIORITY 5: No CMP integration configured
		// Default to ALLOW for backward compatibility
		// WARNING: This is NOT GDPR-compliant if you collect PII!
		// Site admins should either:
		// - Enable a CMP integration, OR
		// - Use anonymous tracking mode, OR
		// - Configure cookie-less + anonymized/hashed IP tracking
		return true;
	}
}
