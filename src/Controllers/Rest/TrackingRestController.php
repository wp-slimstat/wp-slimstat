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
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_tracking(\WP_REST_Request $request)
    {
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
            // Temporarily add consent parameters to $_POST for handleBannerConsent
            $original_post = $_POST;
            $_POST['consent'] = sanitize_text_field($banner_consent);
            if (!empty($banner_consent_nonce)) {
                $_POST['nonce'] = sanitize_text_field($banner_consent_nonce);
            }

            // Update raw_post_array as well
            if (isset(\wp_slimstat::$raw_post_array)) {
                \wp_slimstat::$raw_post_array['consent'] = sanitize_text_field($banner_consent);
                if (!empty($banner_consent_nonce)) {
                    \wp_slimstat::$raw_post_array['nonce'] = sanitize_text_field($banner_consent_nonce);
                }
            }

            // Handle banner consent (without JSON response - continue to tracking)
            \SlimStat\Services\Privacy\ConsentHandler::handleBannerConsent(false);

            // Restore original $_POST
            $_POST = $original_post;
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
        if (is_numeric($result)) {
            return rest_ensure_response((string) $result);
        }

        // If no numeric id detected, still return 200 OK to satisfy queue
        return rest_ensure_response('');
    }
}
