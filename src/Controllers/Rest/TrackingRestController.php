<?php
declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;
use SlimStat\Tracker\Tracker;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class TrackingRestController implements RestControllerInterface
{
    /**
     * Sanitize signed integer REST params without relying on internal PHP functions.
     *
     * WordPress REST passes sanitize callbacks three arguments. Internal functions like
     * intval() fatally error on PHP 8 when called with that signature.
     *
     * @param mixed $value Raw REST parameter value.
     * @return int
     */
    public static function sanitize_integer_param($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public function register_routes(): void
    {
        register_rest_route('slimstat/v1', '/hit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_tracking'],
            'permission_callback' => '__return_true', // Public analytics endpoint - nonce verified for consent operations
            'args'                => [
                'banner_consent' => [
                    'required'          => false,
                    'type'              => 'string',
                    'validate_callback' => function ($param) {
                        return empty($param) || in_array($param, ['accepted', 'denied'], true);
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'banner_consent_nonce' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'id' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'ref' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'res' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'sw' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'sh' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'bw' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'bh' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'sl' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'pp' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'fh' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'tz' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'sanitize_callback' => [self::class, 'sanitize_integer_param'],
                ],
                'pos' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'no' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'ci' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function handle_tracking(\WP_REST_Request $request)
    {
        // Ensure tracking payload is available to the Ajax handler even for REST requests.
        // The tracker reads wp_slimstat::$raw_post_array, which may be empty for REST JSON bodies.
        if (class_exists('\wp_slimstat')) {
            $rest_params = $request->get_params();
            if (!is_array($rest_params)) {
                $rest_params = [];
            }

            // Sanitize known scalar keys from REST params (skip when empty — common for sendBeacon)
            if (!empty($rest_params)) {
                $scalar_keys = ['action', 'n', 'bw', 'bh', 'ref', 'res', 'lt', 'dc', 'ob', 'ss_nonce'];
                foreach ($scalar_keys as $key) {
                    if (isset($rest_params[$key])) {
                        $rest_params[$key] = sanitize_text_field(wp_unslash((string) $rest_params[$key]));
                    }
                }
            }

            // For sendBeacon text/plain requests, php://input was already correctly
            // parsed at plugin init (wp-slimstat.php:1559-1575). REST API cannot parse
            // text/plain bodies, so get_params() returns incomplete data. Merge to
            // preserve init-parsed data while letting REST-sanitized params override.
            if (!empty(\wp_slimstat::$raw_post_array)) {
                $payload = !empty($rest_params)
                    ? array_merge(\wp_slimstat::$raw_post_array, $rest_params)
                    : \wp_slimstat::$raw_post_array;
            } else {
                $payload = $rest_params;
            }

            $payload['action'] = 'slimtrack';
            \wp_slimstat::$raw_post_array = $payload;
        }

        // Check if consent parameters are present (from banner accept)
        // Try get_param first (works for both query and body), then fallback to body_params
        $banner_consent = $request->get_param('banner_consent');
        $banner_consent_nonce = $request->get_param('banner_consent_nonce');

        // If not found in get_param, try body_params (for POST body)
        if (empty($banner_consent)) {
            $body_params = $request->get_body_params();
            $banner_consent = $body_params['banner_consent'] ?? '';
            $banner_consent_nonce = $body_params['banner_consent_nonce'] ?? $banner_consent_nonce;
        }

        if (!empty($banner_consent) && in_array($banner_consent, ['accepted', 'denied'], true)) {
            // Pass consent data directly to handleBannerConsent without manipulating $_POST
            // Security: Nonce verification is performed inside handleBannerConsent via wp_verify_nonce()
            // The nonce must be provided in banner_consent_nonce parameter and verified against 'wp_rest'
            $consent_data = [
                'consent' => sanitize_text_field($banner_consent),
                'nonce'   => !empty($banner_consent_nonce) ? sanitize_text_field($banner_consent_nonce) : '',
            ];

            // Handle banner consent (without JSON response - continue to tracking)
            // If nonce verification fails, handleBannerConsent returns false and consent is not changed
            \SlimStat\Services\Privacy\ConsentHandler::handleBannerConsent(false, $consent_data);
        }

        // Handle tracking hits - process() returns result without exit()
        // Buffer output to prevent stray PHP notices or hook echoes from corrupting the REST response.
        ob_start();
        try {
            $result = Tracker::slimtrack_ajax();
        } finally {
            ob_end_clean();
        }

        // Success: pure numeric ID (rare — most paths return checksum format)
        if (is_numeric($result) && 0 < (int) $result) {
            return rest_ensure_response((string) $result);
        }

        // Success: checksum-formatted string "<id>.<hash>" from Utils::getValueWithChecksum()
        // Return the full checksum string so the JS tracker can send it back for
        // subsequent requests (consent upgrade, events) where getValueWithoutChecksum() validates it.
        if (is_string($result) && preg_match('/^(\d+)\.[0-9a-fA-F]+$/', $result, $matches) && 0 < (int) $matches[1]) {
            return rest_ensure_response($result);
        }

        // If no valid tracking ID detected, return a non-200 status to trigger fallback tracking methods
        return new \WP_Error(
            'slimstat_tracking_failed',
            esc_html__('[REST API] Tracking failed, falling back to alternative methods.', 'wp-slimstat'),
            ['status' => 400]
        );
    }
}
