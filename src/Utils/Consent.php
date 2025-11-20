<?php
declare(strict_types=1);

namespace SlimStat\Utils;

/**
 * Centralized consent utility for tracking eligibility and PII handling.
 *
 * Implements multi-layered consent: Anonymous mode (no PII by default), Standard mode (CMP-based),
 * DNT header support, and CMP integrations (WP Consent API, Real Cookie Banner).
 * External plugins can override via 'slimstat_can_track' filter.
 *
 * @since 5.4.0
 */
class Consent
{
	/**
	 * Retrieve the configured consent integration, falling back to SlimStat's banner when enabled.
	 *
	 * @return string
	 */
	public static function getIntegrationKey(): string
	{
		$settings = \wp_slimstat::$settings;
		$integrationKey = $settings['consent_integration'] ?? '';

		if ('' === $integrationKey && 'on' === ($settings['use_slimstat_banner'] ?? 'off')) {
			$integrationKey = 'slimstat_banner';
		}

		return $integrationKey;
	}

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
				$integrationKey = self::getIntegrationKey();

				// SlimStat Banner integration - check consent cookie
				if ('slimstat_banner' === $integrationKey) {
					$gdpr_service = new \SlimStat\Services\GDPRService($settings);
					if (!$gdpr_service->hasConsent()) {
						$default = false;
					}
				}

				// Real Cookie Banner - cannot reliably read consent server-side
				// Allow anonymous tracking (no PII) but block PII collection
				// Client-side JS will upgrade to full tracking after consent is verified
				// This provides better user experience while maintaining GDPR compliance
				if ('real_cookie_banner' === $integrationKey) {
					// Allow anonymous tracking, PII will be blocked separately in piiAllowed()
					// This ensures basic analytics work while respecting consent for enhanced features
					$default = true;
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

			// In anonymous mode, consent is determined by two factors:
			// 1. A tracking cookie MUST exist, proving the consent upgrade happened in this browser.
			// 2. The CMP must report that consent is active.

			// Check for tracking cookie (proof of upgrade in this browser)
			$hasTrackingCookie = false;
			if (isset($_COOKIE['slimstat_tracking_code'])) {
				$cookieValue = \SlimStat\Tracker\Utils::getValueWithoutChecksum($_COOKIE['slimstat_tracking_code']);
				if (false !== $cookieValue) {
					$hasTrackingCookie = true;
				}
			}

			// Check for consent signal from the configured CMP
			$hasCmpConsent = false;
			$integrationKey = self::getIntegrationKey();

			if ('slimstat_banner' === $integrationKey) {
				$gdpr_service = new \SlimStat\Services\GDPRService($settings);
				if ($gdpr_service->hasConsent()) {
					$hasCmpConsent = true;
				}
			} elseif ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
				$wpConsentCategory = (string) ($settings['consent_level_integration'] ?? 'statistics');
				try {
					if ((bool) \wp_has_consent($wpConsentCategory)) {
						$hasCmpConsent = true;
					}
				} catch (\Throwable $e) {
					if (defined('WP_DEBUG') && WP_DEBUG) {
						error_log('SlimStat: WP Consent API error in piiAllowed() - ' . $e->getMessage());
					}
				}
			} elseif ('real_cookie_banner' === $integrationKey) {
				// Real Cookie Banner: consent is managed client-side and verified via AJAX upgrade.
				// If a SlimStat tracking cookie exists in anonymous mode, it means the browser
				// already completed a consent upgrade flow. Treat this as active consent for PII.
				if ($hasTrackingCookie) {
					$hasCmpConsent = true;
				}
			}

			// PII is allowed only if both the tracking cookie and the CMP consent are present.
			return $hasTrackingCookie && $hasCmpConsent;
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
		$integrationKey = self::getIntegrationKey();

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
			// Conservative: assume no consent on server-side for PII collection
			// Client-side JavaScript will handle consent verification and upgrade
			// Anonymous tracking is allowed, but PII requires explicit consent
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
