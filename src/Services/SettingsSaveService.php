<?php
/**
 * Settings Save Service
 *
 * Extracted from admin/config/index.php to allow both traditional form POST
 * and REST API endpoints to share the same settings save logic.
 *
 * @package   SlimStat\Services
 * @author    Jason Jebbink
 * @license   GPL-2.0-or-later
 * @link      https://wp-slimstat.com
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @since     5.4.10
 */

declare(strict_types=1);

namespace SlimStat\Services;

if (!defined('ABSPATH')) {
    exit;
}

class SettingsSaveService
{
    /**
     * Fields that use wp_kses_post() for sanitization (allow safe HTML).
     */
    private const RICH_TEXT_FIELDS = ['opt_out_message'];

    /**
     * Fields that use wp_strip_all_tags() (code editor content).
     */
    private const CODE_EDITOR_FIELDS = ['custom_css'];

    /**
     * Fields that are read-only or handled by special handlers (skip in generic loop).
     */
    private const SKIP_FIELDS = ['enable_maxmind', 'enable_browscap'];

    /**
     * Save settings for a given tab.
     *
     * @param int   $tab           The settings tab number (1-6).
     * @param array $options       The options array (field_name => value).
     * @param array $settings_defs The settings definitions array (from the filter).
     *                             When empty, falls back to built-in field type knowledge.
     * @param bool  $is_network    Whether saving from network admin context (multisite).
     *                             Callers must pass this explicitly since is_network_admin()
     *                             returns false in REST API context.
     * @return array Result with 'success', 'messages', and optional 'warning'.
     */
    public static function save(int $tab, array $options, array $settings_defs = [], bool $is_network = false): array
    {
        $messages = [];

        // DB Indexes
        // Note: sprintf is used instead of $wpdb->prepare() because these are SQL
        // identifiers (table/index names), not values. $wpdb->prepare() cannot bind
        // identifiers. $GLOBALS['wpdb']->prefix is a trusted WordPress-controlled value
        // set during bootstrap, not user input.
        if (!empty($options['db_indexes'])) {
            if ('on' == $options['db_indexes'] && 'no' == \wp_slimstat::$settings['db_indexes']) {
                \wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats ADD INDEX %sstats_resource_idx( resource( 20 ) )', $GLOBALS['wpdb']->prefix, $GLOBALS['wpdb']->prefix));
                \wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats ADD INDEX %sstats_browser_idx( browser( 10 ) )', $GLOBALS['wpdb']->prefix, $GLOBALS['wpdb']->prefix));
                \wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats ADD INDEX %sstats_searchterms_idx( searchterms( 15 ) )', $GLOBALS['wpdb']->prefix, $GLOBALS['wpdb']->prefix));
                \wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats ADD INDEX %sstats_fingerprint_idx( fingerprint( 20 ) )', $GLOBALS['wpdb']->prefix, $GLOBALS['wpdb']->prefix));
                $messages[] = __('Congratulations! Slimstat Analytics is now optimized for <a href="https://www.youtube.com/watch?v=ygE01sOhzz0" target="_blank">ludicrous speed</a>.', 'wp-slimstat');
                \wp_slimstat::$settings['db_indexes'] = 'on';
            } elseif ('no' == $options['db_indexes'] && 'on' == \wp_slimstat::$settings['db_indexes']) {
                \wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats DROP INDEX %sstats_resource_idx', $GLOBALS['wpdb']->prefix, $GLOBALS['wpdb']->prefix));
                \wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats DROP INDEX %sstats_browser_idx', $GLOBALS['wpdb']->prefix, $GLOBALS['wpdb']->prefix));
                \wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats DROP INDEX %sstats_searchterms_idx', $GLOBALS['wpdb']->prefix, $GLOBALS['wpdb']->prefix));
                \wp_slimstat::$wpdb->query(sprintf('ALTER TABLE %sslim_stats DROP INDEX %sstats_fingerprint_idx', $GLOBALS['wpdb']->prefix, $GLOBALS['wpdb']->prefix));
                $messages[] = __('Table indexes have been disabled. Enjoy the extra database space!', 'wp-slimstat');
                \wp_slimstat::$settings['db_indexes'] = 'no';
            }
        }

        // Geolocation settings save (provider-based)
        if (isset($options['geolocation_country']) || isset($options['geolocation_provider']) || isset($options['maxmind_license_key'])) {
            $resolved_prev = \wp_slimstat::resolve_geolocation_provider();
            $prevProvider  = false !== $resolved_prev ? $resolved_prev : 'disable';
            $provider     = sanitize_text_field($options['geolocation_provider'] ?? $prevProvider);
            $precision    = ('on' === ($options['geolocation_country'] ?? (\wp_slimstat::$settings['geolocation_country'] ?? 'on'))) ? 'country' : 'city';
            $license      = sanitize_text_field($options['maxmind_license_key'] ?? (\wp_slimstat::$settings['maxmind_license_key'] ?? ''));

            \wp_slimstat::$settings['geolocation_provider'] = $provider;
            \wp_slimstat::$settings['geolocation_country']  = 'country' === $precision ? 'on' : 'no';
            \wp_slimstat::$settings['maxmind_license_key']  = $license;

            // Sync legacy flag
            if ('maxmind' === $provider) {
                \wp_slimstat::$settings['enable_maxmind'] = 'on';
            } elseif ('dbip' === $provider || 'cloudflare' === $provider) {
                \wp_slimstat::$settings['enable_maxmind'] = 'no';
            } elseif ('disable' === $provider) {
                \wp_slimstat::$settings['enable_maxmind'] = 'disable';
            }

            // Schedule background DB update if needed
            if (in_array($provider, \SlimStat\Services\GeoService::DB_PROVIDERS, true)) {
                try {
                    $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, [
                        'dbPath'    => \wp_slimstat::$upload_dir,
                        'license'   => $license,
                        'precision' => $precision,
                    ]);
                    $dbExists = file_exists($service->getProvider()->getDbPath());

                    if (!$dbExists || $provider !== $prevProvider) {
                        if (!wp_next_scheduled('wp_slimstat_update_geoip_database')) {
                            wp_schedule_single_event(time() + 10, 'wp_slimstat_update_geoip_database');
                        }
                        $messages[] = __('The geolocation database update has been scheduled in the background. You can also use the Update Database button below to start it now.', 'wp-slimstat');
                    }
                } catch (\Exception $e) {
                    $messages[] = $e->getMessage();
                }
            }
        }

        // Browscap Library
        if (!empty($options['enable_browscap'])) {
            if ('on' == $options['enable_browscap'] && 'no' == \wp_slimstat::$settings['enable_browscap']) {
                $error = \SlimStat\Services\Browscap::update_browscap_database(true);
                if (0 == $error[0]) {
                    \wp_slimstat::$settings['enable_browscap'] = 'on';
                }
                $messages[] = $error[1];
            } elseif ('no' == $options['enable_browscap'] && 'on' == \wp_slimstat::$settings['enable_browscap']) {
                if (\wp_slimstat_admin::rmdir(\wp_slimstat::$upload_dir . '/browscap-cache-master')) {
                    $messages[] = __('The Browscap data file has been uninstalled from your server.', 'wp-slimstat');
                    \wp_slimstat::$settings['enable_browscap'] = 'no';
                } else {
                    $messages[] = __('There was an error deleting the Browscap data folder on your server. Please check your permissions.', 'wp-slimstat');
                }
            }
        }

        // Refresh WP permalinks if tracking method changed
        if (isset($options['tracking_request_method']) && \wp_slimstat::$settings['tracking_request_method'] != $options['tracking_request_method']) {
            update_option('slimstat_permalink_structure_updated', true);
        }

        // Generic field loop with per-type sanitization
        $current_tab_rows = $settings_defs[$tab]['rows'] ?? [];
        foreach ($options as $slug => $value) {
            // Skip special, readonly, and non-savable fields
            if (in_array($slug, self::SKIP_FIELDS, true)) {
                continue;
            }

            // Skip network override metadata fields — handled in the network block below
            if (strpos($slug, 'addon_network_settings_') === 0) {
                continue;
            }

            // When settings definitions are available, use them for validation
            if (!empty($current_tab_rows)) {
                if (empty($current_tab_rows[$slug]) || !empty($current_tab_rows[$slug]['readonly']) || in_array($current_tab_rows[$slug]['type'] ?? '', ['section_header', 'plain-text'])) {
                    continue;
                }
            }

            if (isset($value)) {
                // Determine sanitization method: settings_defs take priority, then built-in knowledge
                $is_rich_text    = false;
                $is_code_editor  = false;

                if (!empty($current_tab_rows[$slug])) {
                    $is_rich_text   = 'rich_text' === ($current_tab_rows[$slug]['type'] ?? '');
                    $is_code_editor = !empty($current_tab_rows[$slug]['use_code_editor']);
                } else {
                    // Fallback to built-in field type knowledge (for REST API calls)
                    $is_rich_text   = in_array($slug, self::RICH_TEXT_FIELDS, true);
                    $is_code_editor = in_array($slug, self::CODE_EDITOR_FIELDS, true);
                }

                if ($is_rich_text) {
                    \wp_slimstat::$settings[$slug] = wp_kses_post($value);
                } elseif ($is_code_editor) {
                    \wp_slimstat::$settings[$slug] = wp_strip_all_tags($value);
                } else {
                    \wp_slimstat::$settings[$slug] = sanitize_text_field($value);
                }
            }

            // Network admin override flags
            if ($is_network) {
                if ('on' == ($options['addon_network_settings_' . $slug] ?? 'no')) {
                    \wp_slimstat::$settings['addon_network_settings_' . $slug] = 'on';
                } else {
                    \wp_slimstat::$settings['addon_network_settings_' . $slug] = 'no';
                }
            } elseif (isset(\wp_slimstat::$settings['addon_network_settings_' . $slug])) {
                unset(\wp_slimstat::$settings['addon_network_settings_' . $slug]);
            }
        }

        // Keep legacy banner toggle in sync
        $current_consent_integration = \wp_slimstat::$settings['consent_integration'] ?? '';
        if ('slimstat_banner' === $current_consent_integration) {
            \wp_slimstat::$settings['use_slimstat_banner'] = 'on';
        } else {
            \wp_slimstat::$settings['use_slimstat_banner'] = 'off';
        }

        // Set save context for third-party filters (e.g., Pro license validation)
        // that may need to know the tab and source without relying on get_current_screen()
        \wp_slimstat::$save_context = [
            'tab'        => $tab,
            'is_network' => $is_network,
            'via'        => !empty($settings_defs) ? 'admin_form' : 'rest_api',
        ];

        // Third-party filter hook
        \wp_slimstat::$settings = apply_filters('slimstat_save_options', \wp_slimstat::$settings);

        // Persist — use network option when saving from network admin context
        \wp_slimstat::update_option('slimstat_options', \wp_slimstat::$settings, $is_network);

        // Note: save_context remains set after save completes so callers
        // and late-firing hooks can still access it. It is reset at the
        // start of the next save() call.

        // Register GDPR banner strings for WPML/Polylang translation
        $gdpr_translatable = [
            'opt_out_message'          => \wp_slimstat::$settings['opt_out_message'] ?? '',
            'gdpr_accept_button_text'  => \wp_slimstat::$settings['gdpr_accept_button_text'] ?? '',
            'gdpr_decline_button_text' => \wp_slimstat::$settings['gdpr_decline_button_text'] ?? '',
        ];

        foreach ($gdpr_translatable as $name => $value) {
            if (empty($value)) {
                continue;
            }
            do_action('wpml_register_single_string', 'wp-slimstat', $name, $value);
            if (function_exists('pll_register_string')) {
                pll_register_string($name, $value, 'wp-slimstat', ($name === 'opt_out_message'));
            }
        }

        return [
            'success'  => true,
            'messages' => $messages,
        ];
    }
}
