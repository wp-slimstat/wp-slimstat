<?php
declare(strict_types=1);

namespace SlimStat\Services\Privacy;

use SlimStat\Providers\IPHashProvider;
use SlimStat\Tracker\Session;
use SlimStat\Utils\Consent;

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
	 * Handle consent granted - upgrade from anonymous to PII tracking
	 *
	 * @return void Outputs JSON response
	 */
	public static function handleConsentGranted()
	{
		check_ajax_referer('wp_rest', 'nonce');

		if (function_exists('wp_cache_delete')) {
			wp_cache_delete('slimstat_consent_state', 'slimstat');
		}

		$integrationKey = Consent::getIntegrationKey();
		$consentGranted = false;

		// Verify consent via configured CMP to prevent client-side tampering
		if ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
			$wpConsentCategory = (string) (\wp_slimstat::$settings['consent_level_integration'] ?? 'statistics');
			try {
				$consentGranted = (bool) \wp_has_consent($wpConsentCategory);
			} catch (\Throwable $e) {
				wp_send_json_error([
					'message' => __('Consent verification failed.', 'wp-slimstat'),
				]);
				return;
			}
		} elseif ('slimstat_banner' === $integrationKey) {
			$gdpr_service = new \SlimStat\Services\GDPRService(\wp_slimstat::$settings);
			$consentGranted = $gdpr_service->hasConsent();
		} elseif ('real_cookie_banner' === $integrationKey) {
			$wpConsentCategory = (string) (\wp_slimstat::$settings['consent_level_integration'] ?? 'statistics');

			// Real Cookie Banner supports WP Consent API, prefer that if available
			if (function_exists('wp_has_consent')) {
				try {
					$consentGranted = (bool) \wp_has_consent($wpConsentCategory);
				} catch (\Throwable $e) {
					$consentGranted = false;
				}
			} else {
				// Fallback: verify plugin is active (client-side JS already verified consent)
				if (!function_exists('is_plugin_active')) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				if (function_exists('is_plugin_active') && is_plugin_active('real-cookie-banner/index.php')) {
					$consentGranted = true;
				} else {
					$consentGranted = false;
				}
			}
		} elseif ('' === $integrationKey) {
			$consentGranted = true;
		}

		if (!$consentGranted) {
			wp_send_json_error([
				'message' => __('Consent not granted or not verified.', 'wp-slimstat'),
			]);
			return;
		}

		$pageview_id_raw = isset($_POST['pageview_id']) ? sanitize_text_field(wp_unslash($_POST['pageview_id'])) : '';
		$pageview_id = \SlimStat\Tracker\Utils::getValueWithoutChecksum($pageview_id_raw);

		if (false === $pageview_id || $pageview_id <= 0) {
			wp_send_json_error([
				'message' => __('Invalid or tampered pageview ID.', 'wp-slimstat'),
			]);
			return;
		}

		$pageview_id = intval($pageview_id);

		// Load existing record to preserve non-PII data (fingerprint, etc.)
		$table = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$existing_record = $GLOBALS['wpdb']->get_row(
			$GLOBALS['wpdb']->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$pageview_id
			),
			ARRAY_A
		);

		// Upgrade from hashed IP to real IP and set tracking cookie
		$stat = IPHashProvider::upgradeToPii([]);

		if (!empty($existing_record)) {
			if (!empty($existing_record['fingerprint'])) {
				$stat['fingerprint'] = $existing_record['fingerprint'];
			}
		}

		// Use fingerprint from request if available (FingerprintJS may load after initial pageview)
		$fingerprint_from_request = isset($_POST['fingerprint']) ? sanitize_text_field(wp_unslash($_POST['fingerprint'])) : '';
		if (!empty($fingerprint_from_request)) {
			$fingerprint_from_request = preg_replace('/[^a-zA-Z0-9\-_]/', '', $fingerprint_from_request);
			if (strlen($fingerprint_from_request) > 256) {
				$fingerprint_from_request = substr($fingerprint_from_request, 0, 256);
			}
			if (!empty($fingerprint_from_request)) {
				$stat['fingerprint'] = $fingerprint_from_request;
			}
		}

		if (!empty($GLOBALS['current_user']->ID)) {
			$stat['username'] = $GLOBALS['current_user']->data->user_login;
			$stat['email']    = $GLOBALS['current_user']->data->user_email;
			$stat['notes']    = '[user:' . $GLOBALS['current_user']->data->ID . ']';
		}

		// Update only current pageview with PII (GDPR: previous pageviews remain anonymous)
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

		if (!empty($stat['fingerprint'])) {
			$update_data['fingerprint'] = $stat['fingerprint'];
		}

		if (!empty($update_data)) {
			$updated = $GLOBALS['wpdb']->update(
				$table,
				$update_data,
				['id' => $pageview_id],
				array_fill(0, count($update_data), '%s'),
				['%d']
			);

			if (false === $updated) {
				wp_send_json_error([
					'message' => __('Failed to update pageview record.', 'wp-slimstat'),
				]);
				return;
			}
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

			if (!empty($GLOBALS['wpdb']->last_error)) {
				wp_send_json_error([
					'message' => __('Failed to check existing notes.', 'wp-slimstat'),
				]);
				return;
			}

			if (null === $existing_note) {
				$notes_updated = $GLOBALS['wpdb']->query(
					$GLOBALS['wpdb']->prepare(
						"UPDATE {$table} SET notes = CONCAT(notes, %s) WHERE id = %d",
						$stat['notes'],
						$pageview_id
					)
				);

				if (false === $notes_updated) {
					wp_send_json_error([
						'message' => __('Failed to update pageview notes.', 'wp-slimstat'),
					]);
					return;
				}
			}
		}

		do_action('slimstat_consent_granted', $pageview_id, $stat);

		wp_send_json_success([
			'message' => __('Consent recorded and tracking upgraded.', 'wp-slimstat'),
			'pageview_id' => $pageview_id,
		]);
	}

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
		// Both logged-in and logged-out users can grant/revoke consent
		add_action('wp_ajax_slimstat_consent_granted', [self::class, 'handleConsentGranted']);
		add_action('wp_ajax_nopriv_slimstat_consent_granted', [self::class, 'handleConsentGranted']);

		add_action('wp_ajax_slimstat_consent_revoked', [self::class, 'handleConsentRevoked']);
		add_action('wp_ajax_nopriv_slimstat_consent_revoked', [self::class, 'handleConsentRevoked']);

		add_action('wp_ajax_slimstat_gdpr_consent', [self::class, 'handleBannerConsent']);
		add_action('wp_ajax_nopriv_slimstat_gdpr_consent', [self::class, 'handleBannerConsent']);
	}

	/**
	 * Handle GDPR banner consent via AJAX
	 *
	 * @return void Outputs JSON response
	 */
	public static function handleBannerConsent()
	{
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
			wp_send_json_error([
				'message' => __('Invalid security token.', 'wp-slimstat'),
			]);
			return;
		}

		if (empty(\wp_slimstat::$settings['use_slimstat_banner']) ||
			'on' !== \wp_slimstat::$settings['use_slimstat_banner']) {
			wp_send_json_error([
				'message' => __('SlimStat banner is not enabled.', 'wp-slimstat'),
			]);
			return;
		}

		$consent = isset($_POST['consent']) ? sanitize_text_field(wp_unslash($_POST['consent'])) : '';

		if (!in_array($consent, ['accepted', 'denied'], true)) {
			wp_send_json_error([
				'message' => __('Invalid consent value.', 'wp-slimstat'),
			]);
			return;
		}

		$gdpr_service = new \SlimStat\Services\GDPRService(\wp_slimstat::$settings);
		$result = $gdpr_service->setConsent($consent);

		if (!$result) {
			wp_send_json_error([
				'message' => __('Failed to set consent cookie.', 'wp-slimstat'),
			]);
			return;
		}

		do_action('slimstat_gdpr_consent_changed', $consent);

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

		wp_send_json_success([
			'success' => true,
			'message' => ('accepted' === $consent)
				? __('Consent granted.', 'wp-slimstat')
				: __('Consent denied.', 'wp-slimstat'),
			'consent' => $consent,
		]);
	}
}
