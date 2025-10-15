<?php
declare(strict_types=1);

namespace SlimStat\Utils;

/**
 * Centralized consent utility for tracking eligibility.
 *
 * This class delegates consent management to external Consent Management Platforms (CMPs)
 * via the `slimstat_can_track` filter. The plugin itself no longer handles consent
 * storage, banners, or cookies.
 *
 * Fallback behavior can be configured via the `consent_fallback` option:
 *  - "allow": track unless a hook denies it
 *  - "deny": do not track unless a hook allows it
 *
 * Developers can override consent using:
 *  - `apply_filters( 'slimstat_can_track', $default )` in PHP
 *  - Front-end CMP integrations should set JS globals; the tracker JS will gate sends accordingly.
 */
class Consent
{

    /**
     * Determine whether SlimStat is allowed to track the current request.
     */
    public static function canTrack(): bool
    {
        $settings = \wp_slimstat::$settings;
        $default  = true;

        // Determine if configuration may collect PII
        $setTrackerCookie = ('on' === ($settings['set_tracker_cookie'] ?? 'on'));
        $anonymizeIp      = ('on' === ($settings['anonymize_ip'] ?? 'no'));
        $hashIp           = ('on' === ($settings['hash_ip'] ?? 'no'));

        // Heuristic: collecting PII if cookies enabled OR IP not anonymized and not hashed
        $collectsPii = $setTrackerCookie || (! $anonymizeIp && ! $hashIp);

        // WP Consent API integration (optional)
        $integrationKey    = $settings['consent_integration'] ?? '';
        $wpConsentEnabled  = ($integrationKey === 'wp_consent_api');
        $wpConsentCategory = (string) ($settings['consent_level_integration'] ?? 'functional');

        $wpConsentAllows = null;
        if ($wpConsentEnabled && function_exists('wp_has_consent')) {
            // Category values commonly: functional, statistics-anonymous, statistics, marketing
            try {
                $wpConsentAllows = (bool) \wp_has_consent($wpConsentCategory);
            } catch (\Throwable $e) {
                $wpConsentAllows = null; // fall back below
            }
        }

        // Real Cookie Banner PRO integration (basic): if selected, treat consent as granted only if consentApi reports opt-in
        if ($integrationKey === 'real_cookie_banner_pro' && $collectsPii) {
            // Server-side cannot access consentApi (browser). Default to deny; JS will send only when allowed.
            $default = false;
        }

        // Borlabs Cookie integration: assume front-end will be blocked or allowed by their script injection. Keep conservative default when PII.
        if ($integrationKey === 'borlabs_cookie' && $collectsPii) {
            $default = false;
        }

        // If site owner requires consent for PII-like features, enforce CMP decision when available
        if ($collectsPii) {
            // If no CMP decision available, do NOT track.
            if ($wpConsentEnabled && $wpConsentAllows === true) {
                $default = true;
            } else {
                $default = false;
            }
        } else {
            // No PII collected → allow tracking without consent (privacy-friendly path)
            $default = true;
        }

        // Respect Do Not Track if enabled in settings
        $respectDnt = ('on' === ($settings['do_not_track'] ?? 'off'));
        if ($respectDnt) {
            $dntHeader = $_SERVER['HTTP_DNT'] ?? '';
            if ($dntHeader === '1') {
                $default = false;
            }
        }

        /**
         * Filter: slimstat_can_track
         *
         * Allows third parties (e.g., CMP plugins) to declare if analytics tracking is allowed.
         * Return true to allow tracking, false to disable it.
         *
         * @param bool $default Default decision derived from WP Consent API + fallback
         */
        $canTrack = (bool) apply_filters('slimstat_can_track', $default);

        return $canTrack;
    }
}
