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
 * 3. Handler updates the current pageview record with full PII:
 *    - Replaces hashed IP with real IP
 *    - Sets tracking cookie
 *    - Stores username/email if logged in
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

		// Get current pageview ID from request
		$pageview_id = isset($_POST['pageview_id']) ? intval($_POST['pageview_id']) : 0;
		if ($pageview_id <= 0) {
			wp_send_json_error([
				'message' => __('Invalid pageview ID.', 'wp-slimstat'),
			]);
			return;
		}

		// Retrieve the visit_id associated with the current pageview
		$table    = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$visit_id = $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare(
				"SELECT visit_id FROM {$table} WHERE id = %d",
				$pageview_id
			)
		);

		if (empty($visit_id)) {
			wp_send_json_error([
				'message' => __('Could not find the associated visit.', 'wp-slimstat'),
			]);
			return;
		}

		// Build stat array for upgrade
		$stat = [
			'visit_id' => $visit_id,
		];

		// Upgrade IP from hash to real IP
		$stat = IPHashProvider::upgradeToPii($stat);

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

		if (!empty($stat['notes'])) {
			// Append to existing notes for all records in the visit
			$GLOBALS['wpdb']->query(
				$GLOBALS['wpdb']->prepare(
					"UPDATE {$table} SET notes = CONCAT(notes, %s) WHERE visit_id = %d",
					$stat['notes'],
					$visit_id
				)
			);
			unset($update_data['notes']); // Notes are handled separately
		}

		if (!empty($update_data)) {
			$updated = $GLOBALS['wpdb']->update(
				$table,
				$update_data,
				['visit_id' => $visit_id], // Update all records for this visit
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
