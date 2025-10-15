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
        $fallback = \wp_slimstat::$settings['consent_fallback'] ?? 'allow';
        $default  = ('deny' !== $fallback);

        /**
         * Filter: slimstat_can_track
         *
         * Allows third parties (e.g., CMP plugins) to declare if analytics tracking is allowed.
         *
         * Return true to allow tracking, false to disable it.
         *
         * @param bool $default Default decision derived from SlimStat fallback option
         */
        $canTrack = (bool) apply_filters('slimstat_can_track', $default);

        return $canTrack;
    }
}
