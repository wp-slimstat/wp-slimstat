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
     * Whether PII-level operations (cookies, full IP) are allowed right now.
     * Returns true when either we don't collect PII by configuration, or
     * a consent integration explicitly grants consent for the selected category.
     */
    public static function piiAllowed(): bool
    {
        $settings = \wp_slimstat::$settings;

        $setTrackerCookie = ('on' === ($settings['set_tracker_cookie'] ?? 'on'));
        $anonymizeIp      = ('on' === ($settings['anonymize_ip'] ?? 'no'));
        $hashIp           = ('on' === ($settings['hash_ip'] ?? 'no'));

        // Collecting PII when cookies enabled OR storing full IP (not anonymized and not hashed)
        $collectsPii = ($setTrackerCookie || (!$anonymizeIp && !$hashIp));

        // If configuration avoids PII already, then PII-like ops are effectively allowed (nothing sensitive to do)
        if (!$collectsPii) {
            return true;
        }

        $integrationKey    = $settings['consent_integration'] ?? '';
        $wpConsentEnabled  = ($integrationKey === 'wp_consent_api');
        $wpConsentCategory = (string) ($settings['consent_level_integration'] ?? 'functional');

        if ($wpConsentEnabled && function_exists('wp_has_consent')) {
            try {
                return (bool) \wp_has_consent($wpConsentCategory);
            } catch (\Throwable $e) {
                return false;
            }
        }

        // For other CMPs where server cannot read browser state, be conservative
        if (in_array($integrationKey, ['real_cookie_banner_pro', 'borlabs_cookie'], true)) {
            return false;
        }

        // No CMP configured → treat as allowed
        return true;
    }

    /**
     * Determine whether SlimStat is allowed to track the current request.
     */
    public static function canTrack(): bool
    {
        $settings = \wp_slimstat::$settings;
        $default  = true;

        // Respect Do Not Track if enabled in settings
        $respectDnt = ('on' === ($settings['do_not_track'] ?? 'off'));
        if ($respectDnt) {
            $dntHeader = $_SERVER['HTTP_DNT'] ?? '';
            if ($dntHeader === '1') {
                $default = false;
            }
        }

        // Always allow tracking; when consent isn't granted for PII, data will be anonymized and hashed server-side.
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
}
