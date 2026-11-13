<?php
declare(strict_types=1);

namespace SlimStat\Services\Privacy;

use SlimStat\Providers\IPHashProvider;
use SlimStat\Tracker\Session;

/**
 * Consent Status Handler for SlimStat
 *
 * Monitors and processes consent status changes received from the client (via JavaScript).
 *
 * In anonymous tracking mode, SlimStat initially logs pageviews with hashed (or anonymized) IPs,
 * without cookies or personally identifiable information (PII). When a user grants consent,
 * this handler upgrades only the current pageview record to include PII:
 *   - The hashed IP is replaced with the user's real or anonymized IP (according to privacy settings)
 *   - A tracking cookie is set to maintain session continuity
 *   - If the user is logged in, username and email are saved
	 *   - Previous anonymous pageviews retain their original privacy protections
 * All subsequent pageviews, once consent is present, are tracked using full PII.
 *
 * If the user revokes consent:
 *   - A request from JavaScript triggers this handler to remove the tracking cookie
 *   - Any future pageview will revert to anonymous tracking (no PII stored)
 *
 * @since 5.4.0
 */
class ConsentHandler
{

	/**
	 * Handle consent revoked - switch to anonymous tracking
	 *
	 * @return void Outputs JSON response
	 */
	public static function handleConsentRevoked()
	{
		check_ajax_referer('wp_rest', 'nonce');

		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('slimstat_consent_state', 'slimstat');
		}

		// Delete tracking cookie
		Session::deleteTrackingCookie();

		// Set consent cookie to denied to prevent further tracking
		$gdpr_service = new \SlimStat\Services\GDPRService(\wp_slimstat::$settings);
		$gdpr_service->setConsent('denied');

		do_action('slimstat_consent_revoked');

		wp_send_json_success([
			'message' => __('Consent revoked and cookie deleted.', 'wp-slimstat'),
		]);
	}

	/**
	 * Register AJAX handlers for consent changes
	 *
	 * @return void
	 */

	public static function registerAjaxHandlers()
	{
		add_action('wp_ajax_slimstat_consent_revoked', [self::class, 'handleConsentRevoked']);
		add_action('wp_ajax_nopriv_slimstat_consent_revoked', [self::class, 'handleConsentRevoked']);

		add_action('wp_ajax_slimstat_gdpr_consent', [self::class, 'handleBannerConsent']);
		add_action('wp_ajax_nopriv_slimstat_gdpr_consent', [self::class, 'handleBannerConsent']);
	}

	/**
	 * Handle GDPR banner consent via AJAX
	 *
	 * @param bool $return_json Whether to return JSON response (default: true)
	 * @param array $data Optional data array with 'nonce', 'consent', 'pageview_id' keys. If empty, reads from $_POST.
	 * @return bool|void Returns true on success, false on error, or outputs JSON if $return_json is true
	 */
	public static function handleBannerConsent(bool $return_json = true, array $data = [])
	{
		// Use provided data or fall back to $_POST
		$nonce = '';
		$consent = '';
		$pageview_id_raw = '';

		if (!empty($data)) {
			$nonce = isset($data['nonce']) ? sanitize_text_field($data['nonce']) : '';
			$consent = isset($data['consent']) ? sanitize_text_field($data['consent']) : '';
			$pageview_id_raw = isset($data['pageview_id']) ? sanitize_text_field($data['pageview_id']) : '';
		} else {
			$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
			$consent = isset($_POST['consent']) ? sanitize_text_field(wp_unslash($_POST['consent'])) : '';
			$pageview_id_raw = isset($_POST['pageview_id']) ? sanitize_text_field(wp_unslash($_POST['pageview_id'])) : '';
		}

		if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
			if ($return_json) {
				wp_send_json_error([
					'message' => __('Invalid security token.', 'wp-slimstat'),
				]);
				return;
			}
			return false;
		}

		if (empty(\wp_slimstat::$settings['use_slimstat_banner']) ||
			'on' !== \wp_slimstat::$settings['use_slimstat_banner']) {
			if ($return_json) {
				wp_send_json_error([
					'message' => __('SlimStat banner is not enabled.', 'wp-slimstat'),
				]);
				return;
			}
			return false;
		}

		if (!in_array($consent, ['accepted', 'denied'], true)) {
			if ($return_json) {
				wp_send_json_error([
					'message' => __('Invalid consent value.', 'wp-slimstat'),
				]);
				return;
			}
			return false;
		}

		$gdpr_service = new \SlimStat\Services\GDPRService(\wp_slimstat::$settings);
		$result = $gdpr_service->setConsent($consent);

		if (!$result) {
			if ($return_json) {
				wp_send_json_error([
					'message' => __('Failed to set consent cookie.', 'wp-slimstat'),
				]);
				return;
			}
			return false;
		}

		do_action('slimstat_gdpr_consent_changed', $consent);

		if ('denied' === $consent) {
			Session::deleteTrackingCookie();
			do_action('slimstat_consent_revoked');
		}

		// If consent granted via banner, upgrade current pageview from anonymous to PII
		if ('accepted' === $consent && \SlimStat\Utils\Consent::piiAllowed()) {
			if (!empty($pageview_id_raw)) {
				$pageview_id = \SlimStat\Tracker\Utils::getValueWithoutChecksum($pageview_id_raw);
				if (false !== $pageview_id && $pageview_id > 0) {
					$pageview_id = intval($pageview_id);

					$stat = IPHashProvider::upgradeToPii([]);

					if (!empty($GLOBALS['current_user']->ID)) {
						$stat['username'] = $GLOBALS['current_user']->data->user_login;
						$stat['email']    = $GLOBALS['current_user']->data->user_email;
						$stat['notes']    = '[user:' . $GLOBALS['current_user']->data->ID . ']';
					}

					$visit_id = Session::upgradePageview($pageview_id, $stat);
					if ($visit_id <= 0) { return false; }
					$stat['visit_id'] = $visit_id;
					\wp_slimstat::set_stat($stat);
					Session::setTrackingCookie($visit_id, 'visit');

					do_action('slimstat_consent_granted', $pageview_id, $stat);
				}
			}
		}

		if ($return_json) {
			wp_send_json_success([
				'success' => true,
				'message' => ('accepted' === $consent)
					? __('Consent granted.', 'wp-slimstat')
					: __('Consent denied.', 'wp-slimstat'),
				'consent' => $consent,
			]);
		}

		return true;
	}
}
