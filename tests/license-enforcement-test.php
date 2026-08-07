<?php
/**
 * Unit test for SlimStat\Services\Admin\LicenseEnforcement — the free-side,
 * version-independent Pro gate.
 *
 * Verifies:
 *  - stripProReports() removes only the Pro User Overview family (slim_p8_*) and
 *    never touches free reports (slim_p2_*, slim_p7_02, slim_p9_*) or third-party.
 *  - the free-tier limit clamps return 1 goal / 0 funnels.
 *  - maybeRegister() registers NOTHING for free-only and valid-license sites, and
 *    registers the three filters only for an unlicensed Pro install.
 *  - the heatmap toggle is blanked in memory on the FRONT-END only (never on
 *    admin, where a settings save would persist and clobber the user's choice).
 *
 * Run: php tests/license-enforcement-test.php
 */

declare(strict_types=1);

namespace {
	$failures = 0;
	function le_check(bool $ok, string $msg): void
	{
		global $failures;
		if (!$ok) {
			fwrite(STDERR, "FAIL: {$msg}\n");
			$failures++;
		}
	}

	// --- WP shims ---
	$GLOBALS['le_filters']  = [];
	$GLOBALS['le_is_admin'] = false;

	function add_filter($tag, $cb, $priority = 10, $args = 1)
	{
		$GLOBALS['le_filters'][] = ['tag' => $tag, 'priority' => $priority];
		return true;
	}
	function is_admin()
	{
		return $GLOBALS['le_is_admin'];
	}

	class wp_slimstat
	{
		public static $settings     = [];
		public static $proInstalled = false;
		public static $licenseValid = false;

		public static function pro_is_installed()
		{
			return self::$proInstalled;
		}
		public static function pro_license_is_valid()
		{
			return self::$licenseValid;
		}
	}

	require_once __DIR__ . '/../src/Services/Admin/LicenseEnforcement.php';

	use SlimStat\Services\Admin\LicenseEnforcement;

	$reset = static function (): void {
		$GLOBALS['le_filters'] = [];
	};
	$hasFilter = static function (string $tag): bool {
		foreach ($GLOBALS['le_filters'] as $f) {
			if ($f['tag'] === $tag) {
				return true;
			}
		}
		return false;
	};

	// 1) stripProReports keeps free + third-party reports, removes slim_p8_*.
	$reports = [
		'slim_p1_01'          => [],
		'slim_p2_23'          => [],
		'slim_p7_02'          => [],
		'slim_p8_01'          => [],
		'slim_p8_02'          => [],
		'slim_p9_01'          => [],
		'slim_p9_02'          => [],
		'slim_live_analytics' => [],
		'thirdparty_report'   => [],
	];
	$out = LicenseEnforcement::stripProReports($reports);
	le_check(!isset($out['slim_p8_01']) && !isset($out['slim_p8_02']), 'stripProReports removes the Pro slim_p8_* reports');
	foreach (['slim_p1_01', 'slim_p2_23', 'slim_p7_02', 'slim_p9_01', 'slim_p9_02', 'slim_live_analytics', 'thirdparty_report'] as $keep) {
		le_check(isset($out[$keep]), "stripProReports keeps free/third-party report {$keep}");
	}

	// 2) Free-tier limit clamps.
	le_check(LicenseEnforcement::freeGoalLimit() === 1, 'freeGoalLimit() returns 1');
	le_check(LicenseEnforcement::freeFunnelLimit() === 0, 'freeFunnelLimit() returns 0');

	// 3) Free-only site (Pro keys absent from settings): nothing registered.
	$reset();
	wp_slimstat::$settings     = ['some_other' => 'x'];
	wp_slimstat::$proInstalled = false;
	wp_slimstat::$licenseValid = false;
	LicenseEnforcement::maybeRegister();
	le_check(count($GLOBALS['le_filters']) === 0, 'free-only site registers no enforcement filters');

	// 4) Valid license: nothing registered.
	$reset();
	wp_slimstat::$settings     = ['slimstat_pro_license_key' => 'abc', 'slimstat_pro_license_status' => true];
	wp_slimstat::$proInstalled = true;
	wp_slimstat::$licenseValid = true;
	LicenseEnforcement::maybeRegister();
	le_check(count($GLOBALS['le_filters']) === 0, 'valid-license site registers no enforcement filters');

	// 5) Unlicensed Pro on an ADMIN request: filters register, heatmap NOT clobbered.
	$reset();
	wp_slimstat::$settings     = ['slimstat_pro_license_key' => 'abc', 'slimstat_pro_license_status' => false, 'addon_heatmap_enable' => 'on'];
	wp_slimstat::$proInstalled = true;
	wp_slimstat::$licenseValid = false;
	$GLOBALS['le_is_admin']    = true;
	LicenseEnforcement::maybeRegister();
	le_check($hasFilter('slimstat_max_goals'), 'unlicensed Pro clamps slimstat_max_goals');
	le_check($hasFilter('slimstat_max_funnels'), 'unlicensed Pro clamps slimstat_max_funnels');
	le_check($hasFilter('slimstat_reports_info'), 'unlicensed Pro strips Pro reports');
	le_check(wp_slimstat::$settings['addon_heatmap_enable'] === 'on', 'heatmap toggle is NOT overridden on admin (no settings clobber)');

	// 6) Unlicensed Pro on a FRONT-END request: heatmap blanked in memory.
	$reset();
	wp_slimstat::$settings     = ['slimstat_pro_license_key' => 'abc', 'slimstat_pro_license_status' => false, 'addon_heatmap_enable' => 'on'];
	wp_slimstat::$proInstalled = true;
	wp_slimstat::$licenseValid = false;
	$GLOBALS['le_is_admin']    = false;
	LicenseEnforcement::maybeRegister();
	le_check(wp_slimstat::$settings['addon_heatmap_enable'] === '', 'heatmap toggle is blanked in memory on the front-end');

	// 7) Fresh Pro install, NO license keys in settings (State A): enforcement must
	//    STILL register. The keys are injected later (on 'init'), so the gate must
	//    rely on pro_is_installed(), not on key presence — a no-key install is unlicensed.
	$reset();
	wp_slimstat::$settings     = ['some_other' => 'x']; // license keys absent
	wp_slimstat::$proInstalled = true;
	wp_slimstat::$licenseValid = false;
	$GLOBALS['le_is_admin']    = true;
	LicenseEnforcement::maybeRegister();
	le_check($hasFilter('slimstat_max_goals'), 'no-key Pro install still clamps slimstat_max_goals (State A enforced)');
	le_check($hasFilter('slimstat_reports_info'), 'no-key Pro install still strips Pro reports (State A enforced)');

	if ($failures > 0) {
		fwrite(STDERR, "{$failures} check(s) failed in license-enforcement-test.php\n");
		exit(1);
	}

	echo "OK: LicenseEnforcement gate, report strip and heatmap override behave correctly\n";
}
