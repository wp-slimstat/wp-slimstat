<?php

namespace SlimStat\Providers;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class RESTService
{
    /**
     * Runs the service.
     *
     * Hooks into the `rest_api_init` action to register the tracking route.
     *
     * @since 5.2.14
     */
    public static function run()
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        add_action('init', [self::class, 'rewriteRuleRequest']);
        add_action('template_redirect', [self::class, 'handleAdblockTracking']);
    }

    /**
     * Registers the REST API routes.
     *
     * Registers the `/hit` endpoint for tracking hits and GDPR endpoints.
     *
     * @since 5.2.14
     */
    public static function registerRoutes()
    {
        register_rest_route('slimstat/v1', '/hit', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handleTracking'],
            'permission_callback' => '__return_true',
        ]);

        // GDPR endpoints are now centralized in the GDPR factory and handled via AJAX/RESTService
        $gdpr_provider = \SlimStat\GDPR\Factories\GDPRFactory::create(\wp_slimstat::$settings);
        $controller = $gdpr_provider->getController();

        register_rest_route('slimstat/v1', '/gdpr/banner', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handleBannerRequest'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('slimstat/v1', '/gdpr/consent', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handleConsentRequest'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handles the tracking request.
     *
     * @since 5.2.14
     *
     * @param WP_REST_Request $request The request object.
     *
     * @return WP_REST_Response The response object.
     */
    public static function handleTracking(\WP_REST_Request $request)
    {
        \SlimStat\Tracker\Tracker::slimtrack_ajax();
    }

    /**
     * Adds a rewrite rule for the request.
     *
     * @since 5.2.14
     */
    public static function rewriteRuleRequest()
    {
        if (get_option('slimstat_permalink_structure_updated', false)) {
            // If the permalink structure has been updated, we need to flush rewrite rules
            flush_rewrite_rules();
            delete_option('slimstat_permalink_structure_updated');
        }

        if (isset(\wp_slimstat::$settings['tracking_request_method']) && 'adblock_bypass' === \wp_slimstat::$settings['tracking_request_method']) {
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
    public static function handleAdblockTracking()
    {
        $request_param = get_query_var('slimstat_request');
        if (empty($request_param)) {
            return;
        }

        // Use the safe raw post array, as $_POST may not be populated on template_redirect
        $post_data = \wp_slimstat::$raw_post_array;
        $action = $post_data['action'] ?? '';

        // Route GDPR actions to the central GDPR controller
        if (in_array($action, ['slimstat_gdpr_banner', 'slimstat_gdpr_consent'], true)) {
            $gdpr_provider = \SlimStat\GDPR\Factories\GDPRFactory::create(\wp_slimstat::$settings);
            $controller = $gdpr_provider->getController();

            if ('slimstat_gdpr_banner' === $action) {
                $controller->handleBannerRequest();
            }
            elseif ('slimstat_gdpr_consent' === $action) {
                $controller->handleConsentRequest();
            }
            exit;
        }

        // Handle tracking hits if it's not a GDPR action
        $expected_tracking_hash = md5(site_url() . 'slimstat_request' . SLIMSTAT_ANALYTICS_VERSION);
        if ($request_param === $expected_tracking_hash) {
            \SlimStat\Tracker\Tracker::slimtrack_ajax();
        }
    }
}
