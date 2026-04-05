<?php
/**
 * Settings REST Controller
 *
 * Provides a WAF-resistant settings save endpoint using JSON body instead
 * of form-encoded POST, avoiding ModSecurity/OWASP CRS false positives.
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
 *
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
                'is_network' => [
                    'required' => false,
                    'type'     => 'boolean',
                    'default'  => false,
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
            return new \WP_REST_Response(
                ['success' => false, 'error' => __('Invalid nonce.', 'wp-slimstat')],
                403
            );
        }

        $tab = (int) $request->get_param('tab');

        // Decode options — support both direct and base64-encoded
        $encoded = $request->get_param('encoded_options');
        if (!empty($encoded) && is_string($encoded)) {
            $decoded = base64_decode($encoded, true);
            if (false === $decoded) {
                return new \WP_REST_Response(
                    ['success' => false, 'error' => __('Invalid encoding.', 'wp-slimstat')],
                    400
                );
            }
            $options = json_decode($decoded, true);
            if (!is_array($options)) {
                return new \WP_REST_Response(
                    ['success' => false, 'error' => __('Invalid options format.', 'wp-slimstat')],
                    400
                );
            }
        } else {
            $options = $request->get_param('options');
            if (!is_array($options)) {
                return new \WP_REST_Response(
                    ['success' => false, 'error' => __('Options must be an object.', 'wp-slimstat')],
                    400
                );
            }
        }

        $is_network = (bool) $request->get_param('is_network');

        // Load settings definitions via the same filter Pro addons use to register fields.
        // This ensures Pro addon fields (heatmap CSS, custom DB, etc.) get proper sanitization.
        // Note: base free plugin definitions (tabs 1-6) are built inline in admin/config/index.php
        // and not available here. SettingsSaveService handles free fields via built-in constants
        // (RICH_TEXT_FIELDS, CODE_EDITOR_FIELDS). If new special field types are added to the
        // free plugin, update those constants or extract base definitions into a shared builder.
        $settings_defs = apply_filters('slimstat_options_on_page', []);

        $result = SettingsSaveService::save($tab, $options, $settings_defs, $is_network);

        return new \WP_REST_Response($result);
    }
}
