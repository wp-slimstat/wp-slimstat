<?php
declare(strict_types=1);

namespace SlimStat\Providers;

use SlimStat\Tracker\Tracker;
use SlimStat\Controllers\Rest\TrackingRestController;
use SlimStat\Controllers\Rest\GDPRBannerRestController;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class RestApiManager
{
    /** @var array */
    private static $controllers = [];

    /**
     * Runs the service.
     *
     * Hooks into the `rest_api_init` action to register the tracking route.
     *
     * @since 5.4.0
     */
    public static function run(): void
    {
        self::load_controllers();
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('init', [self::class, 'rewriteRuleRequest']);
        add_action('template_redirect', [self::class, 'handleAdblockTracking']);
    }

    /**
     * Loads the REST controllers.
     *
     * @since 5.4.0
     */
    private static function load_controllers(): void
    {
        // Default core controllers
		$controllers = [
			new TrackingRestController(),
			new GDPRBannerRestController(),
		];

        /**
         * Filter: slimstat_rest_controllers
         *
         * Allows third parties or Pro add-ons to register additional REST controllers.
         * Each controller must implement SlimStat\Interfaces\RestControllerInterface.
         *
         * @param array $controllers Array of controller instances
         */
        $controllers = apply_filters('slimstat_rest_controllers', $controllers);

        // Validate instances defensively
        $validated = [];
        foreach ((array) $controllers as $controller) {
            if (is_object($controller) && method_exists($controller, 'register_routes')) {
                $validated[] = $controller;
            }
        }

        self::$controllers = $validated;
    }

    /**
     * Registers the REST API routes.
     *
     * @since 5.4.0
     */
    public static function register_routes(): void
    {
        foreach (self::$controllers as $controller) {
            $controller->register_routes();
        }
    }

    /**
     * Adds a rewrite rule for the request.
     *
     * @since 5.2.14
     */
    public static function rewriteRuleRequest(): void
    {
        if (get_option('slimstat_permalink_structure_updated', false)) {
            // If the permalink structure has been updated, we need to flush rewrite rules
            flush_rewrite_rules();
            delete_option('slimstat_permalink_structure_updated');
        }

        if (isset(\wp_slimstat::$settings['tracking_request_method']) && 'adblock_bypass' === \wp_slimstat::$settings['tracking_request_method']) {
            add_rewrite_tag('%slimstat_request%', '([a-f0-9]{32})');
            add_rewrite_rule(
                '^request/([a-f0-9]{32})/?$',
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
    public static function handleAdblockTracking(): void
    {
        $request_param = get_query_var('slimstat_request');
        if (empty($request_param)) {
            return;
        }

        // Use the safe raw post array, as $_POST may not be populated on template_redirect
        $post_data = \wp_slimstat::$raw_post_array;
        $action = $post_data['action'] ?? '';

        // Handle GDPR banner consent via adblock bypass
        if ('slimstat_gdpr_consent' === $action) {
            $expected_hash = md5(site_url() . 'slimstat_request' . SLIMSTAT_ANALYTICS_VERSION);
            if ($request_param === $expected_hash) {
                \SlimStat\Services\Privacy\ConsentHandler::handleBannerConsent();
                exit;
            }
        }

        // Handle tracking hits if it's not a GDPR action
        $expected_tracking_hash = md5(site_url() . 'slimstat_request' . SLIMSTAT_ANALYTICS_VERSION);
        if ($request_param === $expected_tracking_hash) {
            Tracker::slimtrack_ajax();
        }
    }
}
