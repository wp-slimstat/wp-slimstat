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
                    'sanitize_callback' => 'intval',
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
            $payload = $request->get_params();
            if (!is_array($payload)) {
                $payload = [];
            }

            // Sanitize known scalar keys explicitly; preserve all other keys for extension compatibility
            $scalar_keys = ['action', 'n', 'bw', 'bh', 'ref', 'res', 'lt', 'dc', 'ob', 'ss_nonce'];
            foreach ($scalar_keys as $key) {
                if (isset($payload[$key])) {
                    $payload[$key] = sanitize_text_field(wp_unslash((string) $payload[$key]));
                }
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

        // Handle tracking hits
        $result = null;
        if (function_exists('ob_start')) {
            ob_start();
            $maybe = Tracker::slimtrack_ajax();
            $output = ob_get_clean();
            $result = $maybe ?? $output;
        } else {
            $result = Tracker::slimtrack_ajax();
        }

        // Normalize to string numeric id if possible
        if (is_numeric($result) && (int) $result > 0) {
            return rest_ensure_response((string) $result);
        }

        // If no numeric id detected, return a non-200 status to trigger fallback tracking methods
        return new \WP_Error(
            'slimstat_tracking_failed',
            esc_html__('[REST API] Tracking failed, falling back to alternative methods.', 'wp-slimstat'),
            ['status' => 400]
        );
    }
}
