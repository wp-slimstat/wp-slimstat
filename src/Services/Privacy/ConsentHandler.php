<?php
declare(strict_types=1);

namespace SlimStat\Services\Privacy;

use SlimStat\Providers\IPHashProvider;
use SlimStat\Tracker\Session;
use SlimStat\Utils\Consent;
use SlimStat\Utils\Query;

/**
 * Consent Change Handler for SlimStat
 *
 * Handles consent status changes from the client (JavaScript).
 * Particularly important for anonymous tracking mode, where initial tracking
 * uses hashed IPs and no cookies, then upgrades to full PII when consent is granted.
 *
 * Upgrade Flow:
 * =============
 * 1. User visits site → Anonymous tracking (hashed IP, no cookies, no username)
 * 2. User grants consent → JavaScript sends AJAX request to this handler
 * 3. Handler updates ONLY the current pageview record with full PII:
 *    - Replaces hashed IP with real IP
 *    - Sets tracking cookie
 *    - Stores username/email if logged in
 *    - Previous pageviews in the same session remain anonymous (GDPR-compliant)
 * 4. Future pageviews use full tracking with PII
 *
 * Revocation Flow:
 * ===============
 * 1. User revokes consent → JavaScript sends AJAX request
 * 2. Handler deletes tracking cookie
 * 3. Future pageviews use anonymous tracking
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
		// Verify nonce for security
		check_ajax_referer('wp_rest', 'nonce');

		// Verify consent is actually granted via CMP (not just client saying so)
		$integrationKey = \wp_slimstat::$settings['consent_integration'] ?? '';
		$consentGranted = false;

		// Check consent via configured CMP
		if ('wp_consent_api' === $integrationKey && function_exists('wp_has_consent')) {
			$wpConsentCategory = (string) (\wp_slimstat::$settings['consent_level_integration'] ?? 'statistics');
			try {
				$consentGranted = (bool) \wp_has_consent($wpConsentCategory);
			} catch (\Throwable $e) {
				// Consent API error - deny upgrade
				wp_send_json_error([
					'message' => __('Consent verification failed.', 'wp-slimstat'),
				]);
				return;
			}
		} elseif (in_array($integrationKey, ['real_cookie_banner_pro', 'borlabs_cookie'], true)) {
			// These CMPs cannot be verified server-side
			// Accept the client's claim (nonce-protected)
			$consentGranted = true;
		} elseif ('' === $integrationKey) {
			// No CMP configured - accept upgrade (but this shouldn't happen in anonymous mode)
			$consentGranted = true;
		}

		if (!$consentGranted) {
			wp_send_json_error([
				'message' => __('Consent not granted or not verified.', 'wp-slimstat'),
			]);
			return;
		}

		// Get current pageview ID from request and validate checksum
		$pageview_id_raw = isset($_POST['pageview_id']) ? sanitize_text_field(wp_unslash($_POST['pageview_id'])) : '';

		// Validate checksum to prevent tampering
		$pageview_id = \SlimStat\Tracker\Utils::getValueWithoutChecksum($pageview_id_raw);

		if (false === $pageview_id || $pageview_id <= 0) {
			wp_send_json_error([
				'message' => __('Invalid or tampered pageview ID.', 'wp-slimstat'),
			]);
			return;
		}

		// Cast to int after validation
		$pageview_id = intval($pageview_id);

		// Upgrade IP from hash to real IP
		// Note: upgradeToPii() only retrieves current real IP and sets cookie,
		// it doesn't need visit_id or any other pageview data
		$stat = IPHashProvider::upgradeToPii([]);

		// Add username and email if logged in
		if (!empty($GLOBALS['current_user']->ID)) {
			$stat['username'] = $GLOBALS['current_user']->data->user_login;
			$stat['email']    = $GLOBALS['current_user']->data->user_email;
			$stat['notes']    = '[user:' . $GLOBALS['current_user']->data->ID . ']';
		}

		// Update the pageview record in database
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

		// Update main PII fields if we have any
		// GDPR-compliant: Only update the CURRENT pageview by ID
		// Previous pageviews remain anonymous as they were collected without consent
		if (!empty($update_data)) {
			$updated = $GLOBALS['wpdb']->update(
				$table,
				$update_data,
				['id' => $pageview_id], // Update only this specific pageview
				array_fill(0, count($update_data), '%s'), // Data types
				['%d'] // Where format
			);

			if (false === $updated) {
				wp_send_json_error([
					'message' => __('Failed to update pageview record.', 'wp-slimstat'),
				]);
				return;
			}
		}

		// Handle notes separately - only for this pageview
		if (!empty($stat['notes'])) {
			// Check if this specific pageview already has this user note
			$existing_note = $GLOBALS['wpdb']->get_var(
				$GLOBALS['wpdb']->prepare(
					"SELECT notes FROM {$table} WHERE id = %d AND notes LIKE %s LIMIT 1",
					$pageview_id,
					'%' . $GLOBALS['wpdb']->esc_like($stat['notes']) . '%'
				)
			);

			// Only append if this note doesn't already exist
			if (empty($existing_note)) {
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

		// Log the consent upgrade
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
		// Verify nonce for security
		check_ajax_referer('wp_rest', 'nonce');

		// Delete tracking cookie
		Session::deleteTrackingCookie();

		// Log the consent revocation
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
		// Public actions (both logged in and logged out users)
		add_action('wp_ajax_slimstat_consent_granted', [self::class, 'handleConsentGranted']);
		add_action('wp_ajax_nopriv_slimstat_consent_granted', [self::class, 'handleConsentGranted']);

		add_action('wp_ajax_slimstat_consent_revoked', [self::class, 'handleConsentRevoked']);
		add_action('wp_ajax_nopriv_slimstat_consent_revoked', [self::class, 'handleConsentRevoked']);
	}
}
