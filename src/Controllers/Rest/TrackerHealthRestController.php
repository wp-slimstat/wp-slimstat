<?php

declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;

if (!defined('ABSPATH')) {
    exit;
}

class TrackerHealthRestController implements RestControllerInterface
{
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

        // Ensure i18n strings are loaded (REST requests may not trigger admin init)
        if ($errorCode !== null && method_exists('\wp_slimstat_i18n', 'init_dynamic_strings')) {
            \wp_slimstat_i18n::init_dynamic_strings();
        }
        $errorLabel    = $errorCode !== null ? \wp_slimstat_i18n::get_string('e-' . $errorCode) : '';
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
