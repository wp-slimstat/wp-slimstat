<?php
/**
 * Unit test for SlimStat\Services\Admin\LicenseValidator — the free-side daily
 * Pro-license revalidation.
 *
 * Verifies the tri-state classification (valid / invalid / unknown) and that:
 *  - no key → no external call (free-only sites never call out)
 *  - a recent check is throttled (no external call)
 *  - a 'valid' verdict persists status=true; 'invalid' persists false
 *  - an 'unknown' verdict (outage/timeout/5xx) NEVER downgrades a valid customer
 *
 * Run: php tests/license-validator-test.php
 */

declare(strict_types=1);

namespace {
	$failures = 0;
	function lv_check(bool $ok, string $msg): void
	{
		global $failures;
		if (!$ok) {
			fwrite(STDERR, "FAIL: {$msg}\n");
			$failures++;
		}
	}

	if (!defined('DAY_IN_SECONDS')) {
		define('DAY_IN_SECONDS', 86400);
	}

	// --- WP shims ---
	$GLOBALS['lv_response']     = null; // WP_Error instance OR ['response'=>['code'=>int],'body'=>string]
	$GLOBALS['lv_remote_calls'] = 0;
	$GLOBALS['lv_options']      = [];

	class WP_Error
	{
	}
	function is_wp_error($x)
	{
		return $x instanceof WP_Error;
	}
	function add_query_arg($args, $url)
	{
		return $url . '?' . http_build_query($args);
	}
	function get_bloginfo($k)
	{
		return 'https://example.test';
	}
	function wp_remote_get($url, $args = [])
	{
		$GLOBALS['lv_remote_calls']++;
		return $GLOBALS['lv_response'];
	}
	function wp_remote_retrieve_response_code($r)
	{
		return is_array($r) ? ($r['response']['code'] ?? 0) : 0;
	}
	function wp_remote_retrieve_body($r)
	{
		return is_array($r) ? ($r['body'] ?? '') : '';
	}
	function get_option($k, $d = false)
	{
		return $GLOBALS['lv_options'][$k] ?? $d;
	}
	function update_option($k, $v)
	{
		$GLOBALS['lv_options'][$k] = $v;
		return true;
	}
	function get_site_option($k, $d = false)
	{
		return $GLOBALS['lv_network_options'][$k] ?? $d;
	}
	function update_site_option($k, $v)
	{
		$GLOBALS['lv_network_options'][$k] = $v;
		return true;
	}
	function is_multisite()
	{
		return !empty($GLOBALS['lv_multisite']);
	}

	class wp_slimstat
	{
		public static $settings = [];
	}

	require_once __DIR__ . '/../src/Services/Admin/LicenseValidator.php';

	use SlimStat\Services\Admin\LicenseValidator;

	$body = static function (int $status): string {
		return json_encode(['status' => $status]);
	};

	// --- remoteVerify() classification ---
	$GLOBALS['lv_response'] = ['response' => ['code' => 200], 'body' => $body(200)];
	lv_check(LicenseValidator::remoteVerify('k') === 'valid', 'HTTP 200 + body status 200 → valid');

	$GLOBALS['lv_response'] = ['response' => ['code' => 200], 'body' => $body(403)];
	lv_check(LicenseValidator::remoteVerify('k') === 'invalid', 'body status 403 → invalid');

	$GLOBALS['lv_response'] = new WP_Error();
	lv_check(LicenseValidator::remoteVerify('k') === 'unknown', 'WP_Error → unknown');

	$GLOBALS['lv_response'] = ['response' => ['code' => 503], 'body' => $body(200)];
	lv_check(LicenseValidator::remoteVerify('k') === 'unknown', 'HTTP 5xx → unknown');

	$GLOBALS['lv_response'] = ['response' => ['code' => 200], 'body' => 'not-json'];
	lv_check(LicenseValidator::remoteVerify('k') === 'valid', 'HTTP 200 + unparseable body → valid (HTTP code decides)');

	$GLOBALS['lv_response'] = ['response' => ['code' => 200], 'body' => json_encode(['ok' => true])];
	lv_check(LicenseValidator::remoteVerify('k') === 'valid', 'HTTP 200 + body without status → valid (HTTP code decides)');

	// Regression: a 404 for an unknown key, whose body carries no top-level
	// status (here nested under `data`, the WP_Error shape), used to be "unknown"
	// so the daily check never downgraded a revoked key.
	$GLOBALS['lv_response'] = ['response' => ['code' => 404], 'body' => json_encode(['code' => 'not_found', 'data' => ['status' => 404]])];
	lv_check(LicenseValidator::remoteVerify('k') === 'invalid', 'HTTP 404 + nested data.status → invalid');

	$GLOBALS['lv_response'] = ['response' => ['code' => 404], 'body' => ''];
	lv_check(LicenseValidator::remoteVerify('k') === 'invalid', 'HTTP 404 + empty body → invalid (HTTP code decides)');

	// --- maybeRevalidate() ---
	// No key → no external call.
	$GLOBALS['lv_remote_calls'] = 0;
	wp_slimstat::$settings      = ['slimstat_pro_license_key' => ''];
	LicenseValidator::maybeRevalidate();
	lv_check($GLOBALS['lv_remote_calls'] === 0, 'no license key → no remote call');

	// Recent check → throttled.
	$GLOBALS['lv_remote_calls'] = 0;
	wp_slimstat::$settings      = ['slimstat_pro_license_key' => 'k', 'slimstat_pro_license_last_verified_at' => time()];
	LicenseValidator::maybeRevalidate();
	lv_check($GLOBALS['lv_remote_calls'] === 0, 'a check within the last day is throttled');

	// Valid → persists status=true (DB + in-memory).
	$GLOBALS['lv_options']['slimstat_options'] = ['slimstat_pro_license_key' => 'k', 'slimstat_pro_license_status' => false];
	wp_slimstat::$settings                     = ['slimstat_pro_license_key' => 'k', 'slimstat_pro_license_status' => false, 'slimstat_pro_license_last_verified_at' => 0];
	$GLOBALS['lv_response']                     = ['response' => ['code' => 200], 'body' => $body(200)];
	LicenseValidator::maybeRevalidate();
	lv_check($GLOBALS['lv_options']['slimstat_options']['slimstat_pro_license_status'] === true, 'valid verdict persists status=true to the option store');
	lv_check(wp_slimstat::$settings['slimstat_pro_license_status'] === true, 'valid verdict updates the in-memory status');

	// Invalid → persists status=false.
	$GLOBALS['lv_options']['slimstat_options'] = ['slimstat_pro_license_key' => 'k', 'slimstat_pro_license_status' => true];
	wp_slimstat::$settings                     = ['slimstat_pro_license_key' => 'k', 'slimstat_pro_license_status' => true, 'slimstat_pro_license_last_verified_at' => 0];
	$GLOBALS['lv_response']                     = ['response' => ['code' => 200], 'body' => $body(410)];
	LicenseValidator::maybeRevalidate();
	lv_check($GLOBALS['lv_options']['slimstat_options']['slimstat_pro_license_status'] === false, 'invalid verdict persists status=false');

	// Unknown → status unchanged (keep last-known-good).
	$GLOBALS['lv_options']['slimstat_options'] = ['slimstat_pro_license_key' => 'k', 'slimstat_pro_license_status' => true];
	wp_slimstat::$settings                     = ['slimstat_pro_license_key' => 'k', 'slimstat_pro_license_status' => true, 'slimstat_pro_license_last_verified_at' => 0];
	$GLOBALS['lv_response']                     = new WP_Error();
	LicenseValidator::maybeRevalidate();
	lv_check($GLOBALS['lv_options']['slimstat_options']['slimstat_pro_license_status'] === true, 'unknown verdict keeps status=true (never downgrades a valid customer)');

	// Multisite: when the license lives in the NETWORK option, the write targets
	// the network store (not the per-site option).
	$GLOBALS['lv_multisite']       = true;
	$GLOBALS['lv_network_options'] = ['slimstat_options' => ['slimstat_pro_license_key' => 'k', 'slimstat_pro_license_status' => false]];
	$GLOBALS['lv_options']         = ['slimstat_options' => []]; // per-site store has no license
	wp_slimstat::$settings         = ['slimstat_pro_license_key' => 'k', 'slimstat_pro_license_status' => false, 'slimstat_pro_license_last_verified_at' => 0];
	$GLOBALS['lv_response']         = ['response' => ['code' => 200], 'body' => $body(200)];
	LicenseValidator::maybeRevalidate();
	lv_check(($GLOBALS['lv_network_options']['slimstat_options']['slimstat_pro_license_status'] ?? null) === true, 'multisite: valid verdict writes status=true to the network option store');
	lv_check(empty($GLOBALS['lv_options']['slimstat_options']['slimstat_pro_license_status']), 'multisite: the per-site option is not written when the license lives in the network store');
	$GLOBALS['lv_multisite'] = false;

	if ($failures > 0) {
		fwrite(STDERR, "{$failures} check(s) failed in license-validator-test.php\n");
		exit(1);
	}

	echo "OK: LicenseValidator tri-state, throttle and store writes behave correctly\n";
}
