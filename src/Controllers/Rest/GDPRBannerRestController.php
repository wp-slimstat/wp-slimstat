<?php
declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;
use SlimStat\Services\GDPRService;

/**
 * REST API Controller for SlimStat GDPR Banner
 *
 * Handles consent setting/revocation via AJAX/REST for the internal banner.
 *
 * @since 5.4.0
 */
class GDPRBannerRestController implements RestControllerInterface
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
			'/gdpr/consent',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_consent'],
				'permission_callback' => '__return_true', // Public endpoint
				'args'                => [
					'consent' => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ($param) {
							return in_array($param, ['accepted', 'denied'], true);
						},
						'sanitize_callback' => 'sanitize_text_field',
					],
					'nonce'   => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Handle consent setting via REST API
	 *
	 * @param \WP_REST_Request $request REST request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_consent(\WP_REST_Request $request)
	{
		// Verify nonce
		$nonce = $request->get_param('nonce');
		if (!wp_verify_nonce($nonce, 'wp_rest')) {
			return new \WP_Error(
				'rest_forbidden',
				__('Invalid security token.', 'wp-slimstat'),
				['status' => 403]
			);
		}

		// Check if SlimStat banner is enabled
		if (empty(\wp_slimstat::$settings['use_slimstat_banner']) ||
			'on' !== \wp_slimstat::$settings['use_slimstat_banner']) {
			return new \WP_Error(
				'rest_invalid',
				__('SlimStat banner is not enabled.', 'wp-slimstat'),
				['status' => 400]
			);
		}

		$consent = $request->get_param('consent');
		$gdpr_service = new GDPRService(\wp_slimstat::$settings);

		// Set consent cookie
		$result = $gdpr_service->setConsent($consent);

		if (!$result) {
			return new \WP_Error(
				'rest_error',
				__('Failed to set consent cookie.', 'wp-slimstat'),
				['status' => 500]
			);
		}

		// Fire action hook for consent change
		do_action('slimstat_gdpr_consent_changed', $consent);

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => ('accepted' === $consent)
					? __('Consent granted.', 'wp-slimstat')
					: __('Consent denied.', 'wp-slimstat'),
				'consent' => $consent,
			],
			200
		);
	}
}
