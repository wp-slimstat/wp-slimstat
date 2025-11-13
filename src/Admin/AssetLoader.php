<?php

namespace SlimStat\Admin;

/**
 * Asset loader for Traffic Channel Report.
 *
 * Handles enqueuing of CSS and JavaScript assets for channel widgets.
 *
 * @package SlimStat\Admin
 * @since 5.1.0
 */
class AssetLoader
{
    /**
     * Initialize asset loading.
     *
     * Hooks into WordPress admin_enqueue_scripts action.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    /**
     * Enqueue channel widget assets (T056, T057).
     *
     * Enqueues CSS (compiled from SCSS) and JavaScript for AJAX refresh.
     * Assets are only loaded on SlimStat admin pages.
     *
     * @param string $hook Current admin page hook
     * @return void
     */
    public static function enqueue_assets(string $hook): void
    {
        // Only load on SlimStat pages
        $current_screen = get_current_screen();
        if (!$current_screen || !str_contains($current_screen->id ?? '', 'slim')) {
            return;
        }

        // CSS is already included in admin.css via @import in admin.scss
        // No need to enqueue separately as it compiles into admin.css

        // Enqueue channel widgets JavaScript
        wp_enqueue_script(
            'slimstat-channel-widgets',
            plugins_url('/admin/assets/js/channel-widgets.js', dirname(__DIR__)),
            ['jquery'], // Dependencies
            SLIMSTAT_ANALYTICS_VERSION,
            true // Load in footer
        );

        // Localize script with AJAX parameters
        $params = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'widget_nonce' => wp_create_nonce('slimstat_channel_widget_refresh'),
            'channel_auto_refresh' => false, // Default: no auto-refresh
            'channel_refresh_interval' => 300000, // 5 minutes in milliseconds
        ];

        wp_localize_script('slimstat-channel-widgets', 'SlimStatChannelParams', $params);
    }

    /**
     * Enqueue dashboard-specific assets.
     *
     * For future use when widgets are added to WordPress dashboard.
     *
     * @return void
     */
    public static function enqueue_dashboard_assets(): void
    {
        if (!get_current_screen() || get_current_screen()->id !== 'dashboard') {
            return;
        }

        // Enqueue channel widgets JavaScript for dashboard
        wp_enqueue_script(
            'slimstat-channel-widgets',
            plugins_url('/admin/assets/js/channel-widgets.js', dirname(__DIR__)),
            ['jquery'],
            SLIMSTAT_ANALYTICS_VERSION,
            true
        );

        $params = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'widget_nonce' => wp_create_nonce('slimstat_channel_widget_refresh'),
            'channel_auto_refresh' => false,
            'channel_refresh_interval' => 300000,
        ];

        wp_localize_script('slimstat-channel-widgets', 'SlimStatChannelParams', $params);
    }
}
