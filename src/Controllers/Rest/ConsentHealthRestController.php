<?php
declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;
use SlimStat\Utils\Consent;

/**
 * REST API Controller for Consent Health Check
 *
 * Provides health check endpoint to verify consent system status and CMP integrations.
 *
 * @since 5.4.0
 */
class ConsentHealthRestController implements RestControllerInterface
{
	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public function register_routes(): void
	{
		register_rest_route(
			'slimstat/v1',
			'/consent-health',
			[
				'methods'             => 'GET',
				'callback'            => [$this, 'handle_health_check'],
				'permission_callback' => function () {
					return current_user_can('manage_options');
				},
			]
		);
	}

	/**
	 * Handle health check request
	 *
	 * @param \WP_REST_Request $request REST request object
	 * @return \WP_REST_Response
	 */
	public function handle_health_check(\WP_REST_Request $request): \WP_REST_Response
	{
		$health = [
			'status'        => 'ok',
			'timestamp'    => time(),
			'integrations' => [],
		];

		$integration_key = Consent::getIntegrationKey();
		$settings = \wp_slimstat::$settings;

		$health['gdpr_enabled'] = ('on' === ($settings['gdpr_enabled'] ?? 'on'));
		$health['anonymous_tracking'] = ('on' === ($settings['anonymous_tracking'] ?? 'off'));
		$health['configured_integration'] = $integration_key;

		if ('wp_consent_api' === $integration_key) {
			$health['integrations']['wp_consent_api'] = [
				'available' => function_exists('wp_has_consent'),
				'category'  => $settings['consent_level_integration'] ?? 'statistics',
				'status'    => function_exists('wp_has_consent') ? 'connected' : 'not_available',
			];

			if (function_exists('wp_has_consent')) {
				$category = $settings['consent_level_integration'] ?? 'statistics';
				try {
					$has_consent = wp_has_consent($category);
					$health['integrations']['wp_consent_api']['current_consent'] = $has_consent;
				} catch (\Throwable $e) {
					$health['integrations']['wp_consent_api']['error'] = $e->getMessage();
					$health['status'] = 'warning';
				}
			}
		}

		if ('real_cookie_banner' === $integration_key) {
			$rcb_cookies = ['real_cookie_banner', 'rcb_consent', 'rcb_acceptance', 'real_cookie_consent', 'rcb-consent'];
			$found_cookie = false;
			$cookie_name = '';

			foreach ($rcb_cookies as $cookie_name_check) {
				foreach ($_COOKIE as $name => $value) {
					if (strpos($name, $cookie_name_check) === 0) {
						$found_cookie = true;
						$cookie_name = $name;
						break 2;
					}
				}
			}

			$health['integrations']['real_cookie_banner'] = [
				'available'   => $found_cookie,
				'cookie_name' => $cookie_name,
				'status'      => $found_cookie ? 'cookie_detected' : 'no_cookie',
				'note'        => 'Real Cookie Banner uses client-side script blocking. Server-side detection is limited.',
			];

			if (!$found_cookie) {
				$health['status'] = 'warning';
			}
		}

		if ('slimstat_banner' === $integration_key) {
			$gdpr_service = new \SlimStat\Services\GDPRService($settings);
			$has_consent = $gdpr_service->hasConsent();

			$health['integrations']['slimstat_banner'] = [
				'available'      => true,
				'has_consent'     => $has_consent,
				'status'          => 'active',
				'banner_enabled'  => $gdpr_service->isBannerEnabled(),
			];
		}

		$health['can_track'] = Consent::canTrack();
		$health['pii_allowed'] = Consent::piiAllowed();

		return new \WP_REST_Response($health, 200);
	}
}
