<?php
/**
 * The one-shot settings migration for installs that ran v5.4.0–v5.4.5.
 *
 * ── WHY THIS IS A CLASS AND NOT A BLOCK IN init() ─────────────────────────────────────────
 *
 * Until 2026-09-05 this logic lived inline in wp_slimstat::init(), and its only test was
 * tests/v5460-settings-migration-test.php — which could not call init(), so it carried its OWN
 * COPY of the block and asserted against that. The copy drifted. On the day the first uncapped
 * E2E census ran, production and the copy disagreed in three places, and the copy was the one
 * that passed:
 *
 *   - production gated the resets on `'0' !== $_migration_ran` ("skip fresh installs");
 *     the copy had no such guard;
 *   - production restores set_tracker_cookie unconditionally; the copy only when GDPR is off;
 *   - production resets javascript_mode unconditionally; the copy only when the banner was on.
 *
 * A test that mirrors the code it tests is two parsers of one format, and PITFALLS records what
 * happens next. This class is the single implementation: init() calls it, the test calls it.
 *
 * ── THE DEFECT THE FIRST DIVERGENCE HID ─────────────────────────────────────────────────────
 *
 * `_migration_5460` is absent from every install that predates 5.4.6, so array_merge fills it
 * with '0'. A fresh install ALSO reads '0'. The guard `'0' !== $_migration_ran`, added on
 * 2026-03-25 to skip the resets on fresh installs, therefore also skipped them on every site
 * upgrading from 5.4.0–5.4.5 directly — the exact cohort the resets exist for — and v5.5.0 in
 * the field does not carry that guard, so this was a 6.0.0 regression. The census found it via
 * two E2E specs that force the flag to '0' on a populated install and expect the resets.
 *
 * The discriminator is whether the options row EXISTED before this request, which init() knows
 * and the flag cannot express. See $freshInstall.
 *
 * @package SlimStat\Migration
 */

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
    public static function apply(array $settings, string $current, bool $freshInstall, bool $consentApiAvailable): array
    {
        $ran       = $settings[self::FLAG] ?? '0';
        $ip_notice = false;

        // One boundary, derived once. Both the consent-intent mapping and the one-time resets
        // are 5.3.x/5.4.x-era migrations; spelling the comparison twice invited drift.
        $pre_547 = version_compare((string) $ran, '5.4.7', '<');

        if ('0' !== $ran && !(is_string($ran) && version_compare($ran, $current, '<'))) {
            return ['settings' => $settings, 'ran' => false, 'ip_notice' => false];
        }

        // --- Consent intent detection (pre-5.4.7 installs only) ---
        //
        // Maps legacy v5.3.x privacy settings onto the GDPR system. Bounded to installs that
        // predate 5.4.7: unbounded, it re-ran on every version bump and rewrote a site's consent
        // configuration from 5.3.x evidence that no longer described its choices, in both
        // directions. Fresh installs enter here and the else branch writes the shipped defaults.
        if ($pre_547) {
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

        // One-time resets for settings broken by v5.4.0–v5.4.5 defaults. Gated on < 5.4.7 so
        // later upgrades do not override admin choices, and skipped on a FRESH install, which has
        // no broken settings to fix. Fresh means "no options row existed", never "flag is '0'":
        // every pre-5.4.6 upgrader also reads '0', and they are exactly who this is for.
        if ($pre_547 && !$freshInstall) {
            // Restore the session cookie — Consent::piiAllowed() gates the actual setcookie()
            // at runtime, not this setting.
            if ('off' === ($settings['set_tracker_cookie'] ?? 'on')) {
                $settings['set_tracker_cookie'] = 'on';
            }

            // javascript_mode='off' baked a stale per-visitor stat ID into cached HTML. Always
            // reset — server-side mode was a v5.4.0 default, not a user choice.
            if ('off' === ($settings['javascript_mode'] ?? 'on')) {
                $settings['javascript_mode'] = 'on';
            }

            // anonymize_ip='on' and hash_ip='on' were v5.4.1 defaults that changed IP storage.
            $was_anonymized = ('on' === ($settings['anonymize_ip'] ?? 'off'));
            $was_hashed     = ('on' === ($settings['hash_ip'] ?? 'off'));
            if ($was_anonymized) {
                $settings['anonymize_ip'] = 'off';
            }
            if ($was_hashed) {
                $settings['hash_ip'] = 'off';
            }
            $ip_notice = $was_anonymized || $was_hashed;
        }

        // Store the version that ran, so a downgrade→re-upgrade can re-trigger.
        $settings[self::FLAG] = $current;

        return ['settings' => $settings, 'ran' => true, 'ip_notice' => $ip_notice];
    }
}
