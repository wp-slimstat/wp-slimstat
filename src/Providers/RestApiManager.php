<?php
declare(strict_types=1);

namespace SlimStat\Providers;

use SlimStat\Tracker\Tracker;
use SlimStat\Controllers\Rest\ConsentChangeRestController;
use SlimStat\Controllers\Rest\ConsentHealthRestController;
use SlimStat\Controllers\Rest\GDPRBannerRestController;
use SlimStat\Controllers\Rest\TrackerHealthRestController;
use SlimStat\Controllers\Rest\TrackingRestController;

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
        add_action('parse_request', [self::class, 'handleAdblockTracking']);
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
			new ConsentChangeRestController(),
			new ConsentHealthRestController(),
			new TrackerHealthRestController(),
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
     * Generates a secure hash for adblock bypass requests.
     * Uses hash_hmac with WordPress salt for security.
     *
     * @since 5.4.0
     * @return string The secure hash (32 hex characters)
     */
    public static function getSecureAdblockHash(): string
    {
        // Do NOT include SLIMSTAT_ANALYTICS_VERSION — cached pages (WP Rocket, W3TC)
        // bake this hash into HTML. A version change would invalidate all cached bypass URLs.
        $data = site_url() . 'slimstat_request';
        // Use hash_hmac with WordPress auth salt for unpredictable hash
        // Truncate to 32 chars to match the rewrite rule pattern
        return substr(hash_hmac('sha256', $data, wp_salt('auth')), 0, 32);
    }

    /**
     * Handles the tracking request, for the adblocker bypass.
     *
     * @since 5.2.14
     */
    private static function prepareAdblockTrackingResponse(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }

        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }

        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    public static function handleAdblockTracking($wp = null): void
    {
        $request_param = '';
        if (isset($wp->query_vars) && is_array($wp->query_vars) && !empty($wp->query_vars['slimstat_request'])) {
            $request_param = sanitize_text_field((string) $wp->query_vars['slimstat_request']);
        } else {
            $request_param = get_query_var('slimstat_request');
        }

        if (empty($request_param)) {
            return;
        }

        self::prepareAdblockTrackingResponse();

        if ('POST' !== strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            status_header(405);
            header('Allow: POST');
            exit;
        }

        // Use the safe raw post array, as $_POST may not be populated consistently this early.
        $post_data = \wp_slimstat::$raw_post_array;
        $action = $post_data['action'] ?? '';

        // Get secure hash using HMAC with WordPress salt
        $expected_hash = self::getSecureAdblockHash();

        // Handle GDPR banner consent via adblock bypass (legacy separate request)
        if ('slimstat_gdpr_consent' === $action) {
            if (hash_equals($expected_hash, $request_param)) {
                \SlimStat\Services\Privacy\ConsentHandler::handleBannerConsent();
                exit;
            }
        }

        // Handle tracking hits
        if (hash_equals($expected_hash, $request_param)) {
            // Check if consent parameters are present (from banner accept in tracking request)
            $banner_consent = $post_data['banner_consent'] ?? '';
            $banner_consent_nonce = $post_data['banner_consent_nonce'] ?? '';

            if (!empty($banner_consent) && in_array($banner_consent, ['accepted', 'denied'], true)) {
                // Pass consent data directly to handleBannerConsent instead of modifying $_POST
                $consent_data = [
                    'consent' => sanitize_text_field($banner_consent),
                    'nonce'   => !empty($banner_consent_nonce) ? sanitize_text_field($banner_consent_nonce) : '',
                ];

                // Handle banner consent (without JSON response - continue to tracking)
                \SlimStat\Services\Privacy\ConsentHandler::handleBannerConsent(false, $consent_data);
            }

            $result = Tracker::slimtrack_ajax();
            // Output result and exit for adblock bypass requests
            \SlimStat\Tracker\Utils::sendTrackingHeaders('adblock_bypass', $result);
            echo $result;
            exit;
        }

        status_header(404);
        exit;
    }
}
