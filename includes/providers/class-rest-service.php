<?php
namespace Slimstat\Core\Providers;

// don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class REST_Service {

    /**
     * Runs the service.
     *
     * Hooks into the `rest_api_init` action to register the tracking route.
     *
     * @since 5.2.14
     */
    public static function run() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('init', array(__CLASS__, 'rewrite_rule_request'));
        add_action('template_redirect', array(__CLASS__, 'handle_adblock_tracking'));
    }

    /**
     * Registers the REST API routes.
     *
     * Registers the `/hit` endpoint for tracking hits.
     *
     * @since 5.2.14
     */
    public static function register_routes() {
        register_rest_route('slimstat/v1', '/hit', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_tracking'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Handles the tracking request.
     *
     * @since 5.2.14
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response The response object.
     */
    public static function handle_tracking(\WP_REST_Request $request) {
        \wp_slimstat::slimtrack_ajax($request->get_json_params());
    }

    /**
     * Adds a rewrite rule for the request.
     *
     * @since 5.2.14
     */
    public static function rewrite_rule_request()
    {
        if(get_option('slimstat_permalink_structure_updated', false)) {
            // If the permalink structure has been updated, we need to flush rewrite rules
            flush_rewrite_rules();
            delete_option('slimstat_permalink_structure_updated');
        }

        if(\wp_slimstat::$settings['tracking_request_method'] === 'adblock_bypass') {
            add_rewrite_tag('%slimstat_request%', '([a-f0-9]{32})');
            add_rewrite_rule(
                '^request/([a-f0-9]{32})$',
                'index.php?slimstat_request=$matches[1]',
                'top'
            );
        }
    }

    /**
     * Handles the tracking request, for the adblocker bypass.
     *
     * @since 5.2.14
     */
    public static function handle_adblock_tracking()
    {
        // Always handle adblock bypass endpoint for fallback
        $request_hash = get_query_var('slimstat_request');
        if ($request_hash && $request_hash === md5(site_url() . 'slimstat_request' . SLIMSTAT_ANALYTICS_VERSION)) {
            \wp_slimstat::slimtrack_ajax();
        }
    }
}
