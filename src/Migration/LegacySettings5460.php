<?php
/** Legacy consent mapping; upgrades preserve existing configuration, including inherited defaults. */

declare(strict_types=1);

namespace SlimStat\Migration;

final class LegacySettings5460
{
    public const FLAG = '_migration_5460';

    /**
     * Apply the migration to a settings array. Pure: no options written, no transients set — the
     * caller does that from the returned verdict, so this can be exercised without WordPress.
     *
     * @param array<string, mixed> $settings            The merged settings array.
     * @param string               $current             SLIMSTAT_ANALYTICS_VERSION.
     * @param bool                 $freshInstall        True if no slimstat_options row existed
     *                                                  before this request.
     * @param bool                 $consentApiAvailable function_exists('wp_has_consent').
     *
     * @return array{settings: array<string, mixed>, ran: bool, ip_notice: bool}
     */
    public static function apply(array $settings, string $current, bool $freshInstall, bool $consentApiAvailable, ?array $stored = null): array
    {
        $stored = $stored ?? $settings;
        if (!$freshInstall && !array_key_exists('ignore_wp_users', $stored) && array_key_exists('track_users', $stored)) {
            $settings['ignore_wp_users'] = 'no' === $stored['track_users'] ? 'on' : 'no';
        }
        $ran       = $settings[self::FLAG] ?? '0';
        $ip_notice = false;

        // Legacy consent mapping applies only to installations predating 5.4.7.
        $pre_547 = version_compare((string) $ran, '5.4.7', '<');

        if ('0' !== $ran && !(is_string($ran) && version_compare($ran, $current, '<'))) {
            return ['settings' => $settings, 'ran' => false, 'ip_notice' => false];
        }

        // --- Consent intent detection (pre-5.4.7 installs only) ---
        //
        // Maps legacy v5.3.x privacy settings onto the GDPR system. Bounded to installs that
        // predate 5.4.7: unbounded, it re-ran on every version bump and rewrote a site's consent
        // configuration from 5.3.x evidence that no longer described its choices, in both
        // directions. Modern saved choices and fresh installations bypass this legacy mapping.
        if ($pre_547 && !$freshInstall && !array_key_exists('gdpr_enabled', $stored) && !array_key_exists('consent_integration', $stored) && !array_key_exists('use_slimstat_banner', $stored)) {
            $had_opt_out_banner  = ('on' === ($settings['display_opt_out'] ?? 'no'));
            $had_opt_out_cookies = '' !== trim((string) ($settings['opt_out_cookie_names'] ?? ''));
            $had_opt_in_cookies  = '' !== trim((string) ($settings['opt_in_cookie_names'] ?? ''));
            $integration         = $settings['consent_integration'] ?? '';
            $has_third_party_cmp = in_array($integration, ['wp_consent_api', 'real_cookie_banner'], true);

            if ($has_third_party_cmp) {
                $settings['gdpr_enabled'] = 'on';
            } elseif ($had_opt_out_banner || $had_opt_out_cookies || $had_opt_in_cookies) {
                $settings['gdpr_enabled']        = 'on';
                $settings['use_slimstat_banner'] = 'on';
                $settings['consent_integration'] = ($had_opt_in_cookies && $consentApiAvailable)
                    ? 'wp_consent_api'
                    : 'slimstat_banner';
            } else {
                $settings['gdpr_enabled']        = 'off';
                $settings['consent_integration'] = '';
                $settings['use_slimstat_banner'] = 'off';
            }
        }

        // Store the version that ran, so a downgrade→re-upgrade can re-trigger.
        $settings[self::FLAG] = $current;

        return ['settings' => $settings, 'ran' => true, 'ip_notice' => $ip_notice];
    }
}
