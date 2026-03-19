<?php
declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;
use SlimStat\Providers\IPHashProvider;
use SlimStat\Services\Privacy\ConsentHandler;
use SlimStat\Tracker\Session;
use SlimStat\Utils\Consent;

// Don't load directly.
if (!defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

/**
 * REST API Controller for Consent Change Management
 *
 * Handles unified consent change notifications from various CMPs (WP Consent API, Real Cookie Banner, etc.)
 * and performs upgrade/downgrade operations on tracking.
 *
 * @since 5.4.0
 */
class ConsentChangeRestController implements RestControllerInterface
{
	/** @var \WP_REST_Request|null */
	private $currentRequest = null;

	/** @var string|null Memoized cache key; empty string = no stable identifier (skip caching). */
	private $consentCacheKey = null;

	/**
	 * Register REST API routes
	 *
	 * @return void
	 */
	public function register_routes(): void
	{
		register_rest_route(
			'slimstat/v1',
			'/consent-change',
			[
				'methods'             => 'POST',
				'callback'            => [$this, 'handle_consent_change'],
				// Security: Public endpoint with nonce verification in handle_consent_change().
				// Nonce is required and verified via wp_verify_nonce() before any state changes.
				// This endpoint only modifies the current user's own consent state (cookie-based).
				'permission_callback' => '__return_true',
				'args'                => [
					'source' => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ($param) {
							return in_array($param, ['wp_consent_api', 'real_cookie_banner', 'slimstat_banner', 'cookie', 'cmp_missing'], true);
						},
						'sanitize_callback' => 'sanitize_text_field',
					],
					'parsed' => [
						'required'          => true,
						'type'              => 'array',
						'validate_callback' => function ($param) {
							if (!is_array($param)) {
								return false;
							}
							$allowed_keys = ['functional', 'statistics', 'statistics_anonymous', 'marketing', 'preferences'];
							foreach (array_keys($param) as $key) {
								if (!in_array($key, $allowed_keys, true)) {
									return false;
								}
							}
							return true;
						},
						'sanitize_callback' => function ($param) {
							if (!is_array($param)) {
								return [];
							}
							$sanitized = [];
							$allowed_keys = ['functional', 'statistics', 'statistics_anonymous', 'marketing', 'preferences'];
							foreach ($param as $key => $value) {
								if (in_array($key, $allowed_keys, true)) {
									$sanitized[sanitize_key($key)] = sanitize_text_field($value);
								}
							}
							return $sanitized;
						},
					],
					'ts'     => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'mode'   => [
						'required'          => false,
						'type'              => 'array',
						'validate_callback' => function ($param) {
							if (!is_array($param)) {
								return false;
							}
							$allowed_keys = ['anonymous_tracking', 'hash_ip', 'anonymize_ip'];
							foreach (array_keys($param) as $key) {
								if (!in_array($key, $allowed_keys, true)) {
									return false;
								}
							}
							return true;
						},
						'sanitize_callback' => function ($param) {
							if (!is_array($param)) {
								return [];
							}
							$sanitized = [];
							$allowed_keys = ['anonymous_tracking', 'hash_ip', 'anonymize_ip'];
							foreach ($param as $key => $value) {
								if (in_array($key, $allowed_keys, true)) {
									$sanitized[sanitize_key($key)] = sanitize_text_field($value);
								}
							}
							return $sanitized;
						},
					],
					'pageview_id' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'nonce'  => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Handle consent change request
	 *
	 * @param \WP_REST_Request $request REST request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_consent_change(\WP_REST_Request $request)
	{
		$this->currentRequest = $request;

		// Only verify nonce for logged-in users. Anonymous users on cached pages
		// don't have a valid nonce (wp_rest_nonce is only generated for logged-in
		// users). This endpoint only modifies the caller's own consent state
		// (cookie-based), so there is no CSRF attack surface for anonymous users.
		$user_id = get_current_user_id();
		if ($user_id > 0) {
			$nonce = $request->get_param('nonce');
			if (!wp_verify_nonce($nonce, 'wp_rest')) {
				return new \WP_Error(
					'rest_forbidden',
					__('Invalid security token.', 'wp-slimstat'),
					['status' => 403]
				);
			}
		}

		$source = $request->get_param('source');
		$parsed = $request->get_param('parsed');
		$pageview_id_raw = $request->get_param('pageview_id');

		if (!is_array($parsed)) {
			return new \WP_Error(
				'rest_invalid',
				__('Invalid consent data format.', 'wp-slimstat'),
				['status' => 400]
			);
		}

		$parsed = Consent::normalizeConsent($parsed);

		$previous_consent = $this->getPreviousConsentState();
		$action = $this->determineAction($previous_consent, $parsed);

		if ('no_change' === $action) {
			return new \WP_REST_Response(
				[
					'ok'     => true,
					'action' => 'no_change',
				],
				200
			);
		}

		$pageview_id = null;
		if (!empty($pageview_id_raw)) {
			$pageview_id_clean = \SlimStat\Tracker\Utils::getValueWithoutChecksum($pageview_id_raw);
			if (false !== $pageview_id_clean && $pageview_id_clean > 0) {
				$pageview_id = intval($pageview_id_clean);
			}
		}

		if ('upgraded' === $action || 'grant' === $action) {
			$this->upgradeTracking($parsed, $source, $pageview_id);
		} elseif ('downgraded' === $action || 'revoke' === $action) {
			$this->downgradeOrRevokeTracking($parsed, $source);
		}

		return new \WP_REST_Response(
			[
				'ok'     => true,
				'action' => $action,
			],
			200
		);
	}

	/**
	 * Resolve a stable, per-visitor cache key for consent state.
	 * Memoized: key is generated once per request and reused for all cache operations.
	 * Returns empty string when no stable visitor identifier is available (caching skipped).
	 *
	 * @return string
	 */
	private function getConsentCacheKey(): string
	{
		if ($this->consentCacheKey !== null) {
			return $this->consentCacheKey;
		}

		// 1. Tracking cookie — most stable, persists across pageviews for the same visitor
		$tracking_cookie = wp_unslash( $_COOKIE['slimstat_tracking_code'] ?? '' );
		if (!empty($tracking_cookie)) {
			$this->consentCacheKey = 'slimstat_consent_state_' . md5($tracking_cookie);
			return $this->consentCacheKey;
		}

		// 2. pageview_id from request — visitor-scoped for this page load
		$pageview_id_raw = $this->currentRequest
			? ($this->currentRequest->get_param('pageview_id') ?? '')
			: '';
		if (!empty($pageview_id_raw)) {
			$this->consentCacheKey = 'slimstat_consent_state_' . md5($pageview_id_raw);
			return $this->consentCacheKey;
		}

		// 3. No stable identifier — sentinel empty string (caching skipped)
		$this->consentCacheKey = '';
		return $this->consentCacheKey;
	}

	/**
	 * Get previous consent state from cache or database
	 *
	 * @return array Previous consent state
	 */
	private function getPreviousConsentState(): array
	{
		$key = $this->getConsentCacheKey();
		if (!empty($key)) {
			$cached = wp_cache_get($key, 'slimstat');
			if (false !== $cached && is_array($cached)) {
				return $cached;
			}
		}

		$integration_key = Consent::getIntegrationKey();
		$default = [
			'functional'            => 'deny',
			'statistics'            => 'deny',
			'statistics_anonymous' => 'deny',
			'marketing'             => 'deny',
		];

		if ('slimstat_banner' === $integration_key) {
			$gdpr_service = new \SlimStat\Services\GDPRService(\wp_slimstat::$settings);
			if ($gdpr_service->hasConsent()) {
				$default['statistics'] = 'allow';
			}
		} elseif ('wp_consent_api' === $integration_key && function_exists('wp_has_consent')) {
			$category = \wp_slimstat::$settings['consent_level_integration'] ?? 'statistics';
			try {
				if (\SlimStat\Utils\Consent::wpHasConsentSafe($category)) {
					$default['statistics'] = 'allow';
				}
			} catch (\Throwable $e) {
				// Consent status unknown — leave as default deny
			}
		}

		return $default;
	}

	/**
	 * Determine action based on consent change
	 *
	 * @param array $previous Previous consent state
	 * @param array $current Current consent state
	 * @return string Action taken (upgraded, downgraded, grant, revoke, no_change)
	 */
	private function determineAction(array $previous, array $current): string
	{
		$previous_statistics = $previous['statistics'] ?? 'deny';
		$current_statistics = $current['statistics'] ?? 'deny';

		if ('allow' === $current_statistics && 'deny' === $previous_statistics) {
			return 'upgraded';
		}

		if ('deny' === $current_statistics && 'allow' === $previous_statistics) {
			return 'downgraded';
		}

		if ('allow' === $current_statistics && 'allow' === $previous_statistics) {
			return 'grant';
		}

		if ('deny' === $current_statistics && 'deny' === $previous_statistics) {
			return 'revoke';
		}

		return 'no_change';
	}

	/**
	 * Upgrade tracking from anonymous to full PII tracking
	 *
	 * @param array $parsed_consent Normalized consent data
	 * @param string $source Source of consent
	 * @param int|null $pageview_id Optional pageview ID to upgrade
	 * @return void
	 */
	private function upgradeTracking(array $parsed_consent, string $source, ?int $pageview_id = null): void
	{
		$settings = \wp_slimstat::$settings;
		$is_anonymous = ('on' === ($settings['anonymous_tracking'] ?? 'off'));

		if (!$is_anonymous) {
			return;
		}

		if (null !== $pageview_id && $pageview_id > 0) {
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

		$visit_id = \SlimStat\Tracker\Session::getVisitId();
		if ($visit_id > 0) {
			Session::setTrackingCookie($visit_id, 'visit', null, true);
		}

		$key = $this->getConsentCacheKey();
		if (!empty($key)) {
			wp_cache_set($key, $parsed_consent, 'slimstat', HOUR_IN_SECONDS);
		}

		do_action('slimstat_consent_granted', $pageview_id, $parsed_consent);
	}

	/**
	 * Downgrade or revoke tracking (remove PII, invalidate cookies)
	 *
	 * @param array $parsed_consent Normalized consent data
	 * @param string $source Source of consent
	 * @return void
	 */
	private function downgradeOrRevokeTracking(array $parsed_consent, string $source): void
	{
		Session::deleteTrackingCookie();

		$key = $this->getConsentCacheKey();
		if (!empty($key)) {
			wp_cache_delete($key, 'slimstat');
			wp_cache_set($key, $parsed_consent, 'slimstat', HOUR_IN_SECONDS);
		}

		do_action('slimstat_consent_revoked', $parsed_consent, $source);
	}
}
