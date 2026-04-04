<?php
/**
 * Settings REST Controller
 *
 * Provides a WAF-resistant settings save endpoint using JSON body instead
 * of form-encoded POST, avoiding ModSecurity/OWASP CRS false positives.
 *
 * @package   SlimStat\Controllers\Rest
 * @since     5.4.10
 * @see       https://github.com/wp-slimstat/wp-slimstat/issues/285
 */

declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;
use SlimStat\Services\SettingsSaveService;

if (!defined('ABSPATH')) {
    exit;
}

class SettingsRestController implements RestControllerInterface
{
    /**
     * Register REST routes for settings save and WAF probe.
     */
    public function register_routes(): void
    {
        register_rest_route('slimstat/v1', '/settings', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save_settings'],
            'permission_callback' => static function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'tab' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'nonce' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);

        // Lightweight probe endpoint for WAF detection
        register_rest_route('slimstat/v1', '/settings-probe', [
            'methods'             => 'POST',
            'callback'            => static function () {
                return new \WP_REST_Response(['status' => 'ok']);
            },
            'permission_callback' => static function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Handle settings save via REST API.
     *
     * Accepts options as either:
     * - Direct JSON object in 'options' parameter
     * - Base64-encoded JSON string in 'encoded_options' parameter (WAF fallback)
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return \WP_REST_Response
     */
    public function save_settings(\WP_REST_Request $request): \WP_REST_Response
    {
        // Verify SlimStat-specific nonce (defense in depth on top of WP REST cookie auth)
        $nonce = $request->get_param('nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'slimstat_save_settings')) {
            return new \WP_REST_Response(['success' => false, 'error' => 'Invalid nonce.'], 403);
        }

        $tab = (int) $request->get_param('tab');

        // Decode options — support both direct and base64-encoded
        $encoded = $request->get_param('encoded_options');
        if (!empty($encoded) && is_string($encoded)) {
            $decoded = base64_decode($encoded, true);
            if (false === $decoded) {
                return new \WP_REST_Response(['success' => false, 'error' => 'Invalid encoding.'], 400);
            }
            $options = json_decode($decoded, true);
            if (!is_array($options)) {
                return new \WP_REST_Response(['success' => false, 'error' => 'Invalid options format.'], 400);
            }
        } else {
            $options = $request->get_param('options');
            if (!is_array($options)) {
                return new \WP_REST_Response(['success' => false, 'error' => 'Options must be an object.'], 400);
            }
        }

        // Save via the shared service (uses built-in field type knowledge for REST calls)
        $result = SettingsSaveService::save($tab, $options);

        return new \WP_REST_Response($result);
    }
}
