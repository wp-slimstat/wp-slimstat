<?php
/**
 * Tracker Health REST Controller
 *
 * Provides a diagnostic REST endpoint for admin users to inspect the current
 * tracker configuration, exclusion settings, and last recorded error.
 *
 * @package   SlimStat\Controllers\Rest
 * @author    Jason Jebbink
 * @license   GPL-2.0-or-later
 * @link      https://wp-slimstat.com
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST controller for the /slimstat/v1/tracker-health endpoint.
 *
 * Returns tracker configuration and the last recorded error so that
 * site admins (or support) can diagnose tracking failures remotely.
 */
class TrackerHealthRestController implements RestControllerInterface
{
    /**
     * Register the tracker-health REST route.
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route('slimstat/v1', '/tracker-health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle'],
            'permission_callback' => static function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Handle a tracker-health request.
     *
     * Collects current tracker settings, exclusion rules, the last recorded
     * tracker error, and GeoIP status into a single diagnostic payload.
     *
     * @param \WP_REST_Request $request The incoming REST request.
     * @return \WP_REST_Response JSON response with diagnostic data.
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $settings = \wp_slimstat::$settings;

        $ignoreKeys = [
            'ignore_ip', 'ignore_resources', 'ignore_referers',
            'ignore_content_types', 'ignore_browsers', 'ignore_platforms',
            'ignore_bots', 'ignore_languages', 'ignore_countries',
            'ignore_users', 'ignore_capabilities', 'ignore_wp_users',
            'ignore_spammers', 'ignore_prefetch',
        ];

        $ignoreSettings = [];
        foreach ($ignoreKeys as $key) {
            $ignoreSettings[$key] = $settings[$key] ?? '';
        }

        $lastError     = get_option('slimstat_tracker_error', []);
        $errorCode     = !empty($lastError[0]) ? (int) $lastError[0] : null;

        // Resolve a human-readable label for the error code via i18n (if available).
        // The i18n class lives in languages/index.php and may not be loaded during
        // REST requests, so we guard with class_exists.
        $errorLabel = '';
        if ($errorCode !== null && class_exists('\wp_slimstat_i18n')) {
            if (method_exists('\wp_slimstat_i18n', 'init_dynamic_strings')) {
                \wp_slimstat_i18n::init_dynamic_strings();
            }
            $lookupKey = 'e-' . $errorCode;
            $rawLabel  = \wp_slimstat_i18n::get_string($lookupKey);
            // If get_string returns the lookup key itself, no translation exists
            if ($rawLabel !== $lookupKey && $rawLabel !== '') {
                $errorLabel = $rawLabel;
            }
        }
        $errorTime     = !empty($lastError[1]) ? (int) $lastError[1] : null;
        $errorDetail   = get_option('slimstat_tracker_error_detail', null);
        $geoipError    = get_option('slimstat_geoip_error', null);
        $geoipProvider = \wp_slimstat::resolve_geolocation_provider();

        $data = [
            'version'                => defined('SLIMSTAT_ANALYTICS_VERSION') ? SLIMSTAT_ANALYTICS_VERSION : '',
            'tracking_request_method' => $settings['tracking_request_method'] ?? 'rest',
            'javascript_mode'        => $settings['javascript_mode'] ?? 'on',
            'gdpr_enabled'           => $settings['gdpr_enabled'] ?? 'on',
            'anonymous_tracking'     => $settings['anonymous_tracking'] ?? 'off',
            'geolocation_provider'   => false !== $geoipProvider ? $geoipProvider : 'disabled',
            'ignore_settings'        => $ignoreSettings,
            'last_tracker_error'     => [
                'code'        => $errorCode,
                'label'       => $errorLabel,
                'recorded_at' => $errorTime ? gmdate('Y-m-d H:i:s', $errorTime) : null,
                'detail'      => ($errorCode === 200 && !empty($errorDetail)) ? $errorDetail : null,
            ],
            'last_geoip_error'       => !empty($geoipError) ? $geoipError : null,
        ];

        return rest_ensure_response($data);
    }
}
