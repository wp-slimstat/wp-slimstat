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
 *    - Real Cookie Banner: conservative (blocks server-side tracking; client-side only)
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
	 * 2. Check Anonymous Tracking mode (allows tracking without consent)
	 * 3. Determine if configuration collects PII (cookies OR full IPs)
	 * 4. If collects PII: Check CMP consent (for server-side verifiable CMPs or conservative blocking)
	 * 5. Apply 'slimstat_can_track' filter for external override
	 * 6. Return final decision
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

		// Anonymous Tracking mode - ALWAYS allow tracking (no PII collected by default)
		// This mode is GDPR-safe because it hashes IPs, doesn't set cookies, and doesn't store usernames
		$isAnonymousTracking = ('on' === ($settings['anonymous_tracking'] ?? 'off'));
		if ($isAnonymousTracking) {
			// Allow tracking - server will hash IPs and not store PII
			// Users can still opt-in later for enhanced features (via consent upgrade)
			// Continue to filter below
		} else {
			// Standard tracking mode - check if configuration collects PII
			$setTrackerCookie = ('on' === ($settings['set_tracker_cookie'] ?? 'on'));
			$anonymizeIp      = ('on' === ($settings['anonymize_ip'] ?? 'off'));
			$hashIp           = ('on' === ($settings['hash_ip'] ?? 'off'));

			// We collect PII if:
			// - Cookies are enabled (identifies returning visitors) OR
			// - Full IPs are stored (not anonymized AND not hashed)
			$collectsPii = ($setTrackerCookie || (!$anonymizeIp && !$hashIp));

			// Only check CMP consent if configuration actually collects PII
			if ($collectsPii) {
				// Check CMP integration for consent
				$integrationKey = $settings['consent_integration'] ?? '';

				// SlimStat Banner integration - check consent cookie
				if ('slimstat_banner' === $integrationKey) {
					$gdpr_service = new \SlimStat\Services\GDPRService($settings);
					if (!$gdpr_service->hasConsent()) {
						$default = false;
					}
				}

				// Real Cookie Banner - cannot reliably read consent server-side
				// MUST block server-side tracking to prevent consent bypass
				// Client-side JS will handle tracking after consent is verified
				// This ensures GDPR compliance by respecting user's consent choices
				if ('real_cookie_banner' === $integrationKey) {
					// Conservative: block all server-side tracking
					// Only allow client-side (JavaScript) tracking after consent is verified
					// This is the recommended approach for Real Cookie Banner integration
					$default = false;
				}

				// WP Consent API integration - can read consent server-side
				if ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
					$wpConsentCategory = (string) ($settings['consent_level_integration'] ?? 'statistics');
					try {
						// Check consent status - if not granted, block tracking
						if (!\wp_has_consent($wpConsentCategory)) {
							$default = false;
						}
					} catch (\Throwable $e) {
						// Consent API error - be conservative, deny tracking
						// Only override $default if it was true (tracking was allowed)
						if ($default && defined('WP_DEBUG') && WP_DEBUG) {
							error_log('SlimStat: WP Consent API error in canTrack() - ' . $e->getMessage());
						}
						$default = false;
					}
				}
			}
			// If configuration doesn't collect PII: $default remains true (tracking allowed)
		}

		/**
		 * Filter: slimstat_can_track
		 *
		 * Allows third parties (e.g., CMP plugins) to declare if analytics tracking is allowed.
		 * Return true to allow tracking, false to disable it.
		 *
		 * @param bool $default Default decision (DNT-aware + CMP-aware)
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
			// If explicit consent signal is provided (e.g., from consent upgrade AJAX handler), allow PII
			if ($explicitConsentGiven) {
				return true;
			}

			// Check if consent was granted previously (via cookie or CMP)
			// After consent is granted, a tracking cookie is set, so presence of cookie indicates consent
			// Also check CMP for server-side consent verification
			$integrationKey = $settings['consent_integration'] ?? '';

			// SlimStat Banner integration - check consent cookie
			if ('slimstat_banner' === $integrationKey) {
				$gdpr_service = new \SlimStat\Services\GDPRService($settings);
				if ($gdpr_service->hasConsent()) {
					return true;
				}
			}

			// WP Consent API integration - can read consent server-side
			if ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
				$wpConsentCategory = (string) ($settings['consent_level_integration'] ?? 'statistics');
				try {
					$hasConsent = (bool) \wp_has_consent($wpConsentCategory);
					if ($hasConsent) {
						return true;
					}
				} catch (\Throwable $e) {
					// Consent API error - be conservative, deny PII
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('SlimStat: WP Consent API error - ' . $e->getMessage());
					}
				}
			}

			// Check if tracking cookie exists - if it does, consent was granted previously
			// Cookie is only set after consent is granted in anonymous mode
			if (isset($_COOKIE['slimstat_tracking_code'])) {
				// Cookie exists - verify it's valid (not just a random cookie)
				// Use Utils::getValueWithoutChecksum to validate the cookie format
				$cookieValue = \SlimStat\Tracker\Utils::getValueWithoutChecksum($_COOKIE['slimstat_tracking_code']);
				// If cookie is valid (checksum verified), it means consent was granted and cookie was set
				// This allows PII collection for subsequent pageviews
				if (false !== $cookieValue) {
					return true;
				}
			}

			// No consent found - deny PII
			return false;
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

		// SlimStat Banner integration - check consent cookie
		if ('slimstat_banner' === $integrationKey) {
			$gdpr_service = new \SlimStat\Services\GDPRService($settings);
			return $gdpr_service->hasConsent();
		}

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

		// Real Cookie Banner - cannot reliably read consent server-side
		// This CMP blocks scripts client-side, so server must be conservative
		// to avoid collecting PII before consent is verified
		if ('real_cookie_banner' === $integrationKey) {
			// Conservative: assume no consent on server-side
			// Client-side JavaScript will handle consent gating and tracking
			// Only after explicit user consent will tracking upgrade occur
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
