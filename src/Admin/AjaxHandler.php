<?php

namespace SlimStat\Admin;

use SlimStat\Widgets\TopChannelWidget;
use SlimStat\Widgets\ChannelDistributionWidget;

/**
 * AJAX handler for channel widget refresh.
 *
 * Handles AJAX requests from channel-widgets.js to refresh widget data
 * without full page reload.
 *
 * @package SlimStat\Admin
 * @since 5.1.0
 */
class AjaxHandler
{
    /**
     * Widget instances cache.
     *
     * @var array
     */
    private static $widget_instances = [];

    /**
     * Initialize AJAX handlers.
     *
     * Registers WordPress AJAX actions for widget refresh.
     *
     * @return void
     */
    public static function init(): void
    {
        // AJAX handler for logged-in users
        add_action('wp_ajax_slimstat_refresh_widget', [self::class, 'handle_widget_refresh']);

        // No AJAX handler for non-logged-in users (admin only)
    }

    /**
     * Handle widget refresh AJAX request (T058, T059, T060).
     *
     * Validates nonce, retrieves widget instance, renders fresh data,
     * and returns JSON response.
     *
     * @return void Outputs JSON and exits
     */
    public static function handle_widget_refresh(): void
    {
        // Verify nonce
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'slimstat_channel_widget_refresh')) {
            wp_send_json_error([
                'message' => __('Security check failed. Please refresh the page.', 'wp-slimstat'),
            ], 403);
            return;
        }

        // Check user capabilities
        if (!current_user_can('read')) {
            wp_send_json_error([
                'message' => __('Insufficient permissions.', 'wp-slimstat'),
            ], 403);
            return;
        }

        // Get widget ID
        $widget_id = sanitize_text_field($_POST['widget_id'] ?? '');

        if (empty($widget_id)) {
            wp_send_json_error([
                'message' => __('Widget ID is required.', 'wp-slimstat'),
            ], 400);
            return;
        }

        // Get widget instance
        $widget = self::get_widget_instance($widget_id);

        if (!$widget) {
            wp_send_json_error([
                'message' => __('Invalid widget ID.', 'wp-slimstat'),
            ], 400);
            return;
        }

        // Parse date range from request
        $date_from = isset($_POST['date_from']) ? intval($_POST['date_from']) : null;
        $date_to = isset($_POST['date_to']) ? intval($_POST['date_to']) : null;

        // Build widget arguments
        $widget_args = [];

        if ($date_from && $date_to) {
            $widget_args['date_from'] = $date_from;
            $widget_args['date_to'] = $date_to;
        } else {
            // Default: Last 30 days
            $widget_args['date_from'] = time() - (30 * DAY_IN_SECONDS);
            $widget_args['date_to'] = time();
        }

        // Clear transient cache for this widget to force fresh data
        $cache_key = 'slimstat_widget_' . $widget_id . '_' . md5(serialize($widget_args));
        delete_transient($cache_key);

        // Render widget with fresh data
        try {
            $html = $widget->render($widget_args);

            wp_send_json_success([
                'html' => $html,
                'widget_id' => $widget_id,
                'timestamp' => time(),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: Error message */
                    __('Failed to render widget: %s', 'wp-slimstat'),
                    $e->getMessage()
                ),
            ], 500);
        }
    }

    /**
     * Get widget instance by ID.
     *
     * Factory method for widget instantiation with caching.
     *
     * @param string $widget_id Widget identifier
     * @return mixed|null Widget instance or null if invalid
     */
    private static function get_widget_instance(string $widget_id)
    {
        // Check cache
        if (isset(self::$widget_instances[$widget_id])) {
            return self::$widget_instances[$widget_id];
        }

        // Instantiate widget based on ID
        $widget = null;

        switch ($widget_id) {
            case 'slim_channel_top':
                $widget = new TopChannelWidget();
                break;

            case 'slim_channel_distribution':
                $widget = new ChannelDistributionWidget();
                break;

            default:
                // Allow extensions to register custom widgets
                $widget = apply_filters('slimstat_get_widget_instance', null, $widget_id);
                break;
        }

        // Cache instance
        if ($widget) {
            self::$widget_instances[$widget_id] = $widget;
        }

        return $widget;
    }

    /**
     * Clear widget cache via AJAX.
     *
     * For future use - manual cache clearing from admin UI.
     *
     * @return void Outputs JSON and exits
     */
    public static function handle_clear_widget_cache(): void
    {
        // Verify nonce
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'slimstat_channel_widget_clear_cache')) {
            wp_send_json_error([
                'message' => __('Security check failed.', 'wp-slimstat'),
            ], 403);
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Insufficient permissions.', 'wp-slimstat'),
            ], 403);
            return;
        }

        // Clear all channel widget transients
        global $wpdb;

        $prefix = $wpdb->esc_like('_transient_slimstat_widget_slim_channel_');
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix . '%'
            )
        );

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: Number of cache entries cleared */
                _n('%d cache entry cleared.', '%d cache entries cleared.', $deleted, 'wp-slimstat'),
                $deleted
            ),
            'deleted' => $deleted,
        ]);
    }
}
