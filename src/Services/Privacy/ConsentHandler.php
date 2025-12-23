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
 *   - Previous anonymous pageviews remain non-identifiable (ensuring ongoing GDPR compliance)
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

		Session::deleteTrackingCookie();

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
	 * @return bool|void Returns true on success, false on error, or outputs JSON if $return_json is true
	 */
	public static function handleBannerConsent(bool $return_json = true)
	{
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
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

		$consent = isset($_POST['consent']) ? sanitize_text_field(wp_unslash($_POST['consent'])) : '';

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

		$parsed_consent = \SlimStat\Utils\Consent::normalizeConsent($consent);
		$action = ('accepted' === $consent) ? 'grant' : 'revoke';

		// If consent granted via banner, upgrade current pageview from anonymous to PII
		if ('accepted' === $consent) {
			$pageview_id_raw = isset($_POST['pageview_id']) ? sanitize_text_field(wp_unslash($_POST['pageview_id'])) : '';

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

					$table = $GLOBALS['wpdb']->prefix . 'slim_stats';
					$update_data = [];

					if (!empty($stat['ip'])) {
						$update_data['ip'] = $stat['ip'];
					}

					if (!empty($stat['other_ip'])) {
						$update_data['other_ip'] = $stat['other_ip'];
					}

					if (!empty($stat['username'])) {
						$update_data['username'] = $stat['username'];
					}

					if (!empty($stat['email'])) {
						$update_data['email'] = $stat['email'];
					}

					if (!empty($update_data)) {
						$GLOBALS['wpdb']->update(
							$table,
							$update_data,
							['id' => $pageview_id],
							array_fill(0, count($update_data), '%s'),
							['%d']
						);
					}

					// Append user note if not already present
					if (!empty($stat['notes'])) {
						$existing_note = $GLOBALS['wpdb']->get_var(
							$GLOBALS['wpdb']->prepare(
								"SELECT notes FROM {$table} WHERE id = %d AND notes LIKE %s LIMIT 1",
								$pageview_id,
								'%' . $GLOBALS['wpdb']->esc_like($stat['notes']) . '%'
							)
						);

						if (empty($GLOBALS['wpdb']->last_error) && null === $existing_note) {
							$GLOBALS['wpdb']->query(
								$GLOBALS['wpdb']->prepare(
									"UPDATE {$table} SET notes = CONCAT(IFNULL(notes, ''), %s) WHERE id = %d",
									$stat['notes'],
									$pageview_id
								)
							);
						}
					}

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
