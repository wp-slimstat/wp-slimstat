<?php

namespace SlimStat\Services\Admin;

/**
 * Version-independent Pro-feature enforcement, driven entirely by the free
 * plugin so it works even when the user keeps an OLD Pro build that has no
 * license gate of its own.
 *
 * Active only when Pro is installed and the license is missing/inactive/expired.
 * It suppresses the *visible* Pro surface through the hooks free controls:
 *   - clamps the goal/funnel limits back to the free tier
 *   - strips Pro-contributed reports (the slim_p8_* User Overview family — the
 *     only reports an addon adds via slimstat_reports_info)
 *   - soft-disables the front-end Heatmap overlay by blanking its toggle in
 *     memory (front-end only, never persisted)
 *
 * It deliberately does NOT touch the Custom DB connection (data continuity) and
 * cannot stop an old Pro's already-registered background endpoints (email cron,
 * AJAX handlers, login-note capture); the new Pro addon gate stops those fully.
 */
class LicenseEnforcement
{
	/**
	 * Register the enforcement hooks when the site is an unlicensed Pro install.
	 * Cheap early-returns keep valid-license and free-only sites untouched.
	 *
	 * Must run at plugins_loaded:20 (from wp_slimstat::init), before any
	 * 'init'-hooked Pro addon reads its settings.
	 *
	 * @return void
	 */
	public static function maybeRegister()
	{
		// Enforce only when Pro is installed but the license is missing/invalid.
		// pro_is_installed() is settings-independent (it reads active_plugins), so
		// this works even on a fresh Pro install whose license keys are not yet in
		// wp_slimstat::$settings — Pro injects those later, on the 'init' hook, which
		// fires after this runs at plugins_loaded:20. Gating on key presence here
		// would leave the no-key (State A) install fully unlocked.
		if (!\wp_slimstat::pro_is_installed() || \wp_slimstat::pro_license_is_valid()) {
			return;
		}

		// Revert goal/funnel capabilities to the free tier. The whole Goals &
		// Funnels UI derives "is Pro" from these limits, so clamping them flips
		// the cards, CTAs and funnel visibility back to free without touching data.
		\add_filter('slimstat_max_goals', [self::class, 'freeGoalLimit'], PHP_INT_MAX);
		\add_filter('slimstat_max_funnels', [self::class, 'freeFunnelLimit'], PHP_INT_MAX);

		// Hide Pro reports contributed via the filter (User Overview slim_p8_*).
		// Runs last so it removes them after every other contributor has added.
		\add_filter('slimstat_reports_info', [self::class, 'stripProReports'], PHP_INT_MAX);

		// Soft-disable the front-end Heatmap overlay for OLD Pro builds by
		// blanking the toggle in memory. FRONT-END ONLY: never on admin requests,
		// where a settings save persists the whole $settings array and would lose
		// the user's real choice. New Pro's addon gate stops it outright.
		if (!\is_admin() && isset(\wp_slimstat::$settings['addon_heatmap_enable'])) {
			\wp_slimstat::$settings['addon_heatmap_enable'] = '';
		}
	}

	/**
	 * @return int Free-tier active-goal limit.
	 */
	public static function freeGoalLimit()
	{
		return 1;
	}

	/**
	 * @return int Free-tier funnel limit (funnels are Pro-only).
	 */
	public static function freeFunnelLimit()
	{
		return 0;
	}

	/**
	 * Remove the Pro User Overview reports (slim_p8_*) from the report registry.
	 *
	 * Deliberate prefix match rather than the plan's baseline-capture-and-diff: free's
	 * own LegacyReportAdapter ALSO injects report keys via slimstat_reports_info (at
	 * priority 999), so a "strip everything not in a priority-0 snapshot" approach
	 * would wrongly blank free's registry reports. The slim_p8_* family is the ONLY
	 * report set any Pro addon contributes through this filter (UserOverviewAddon
	 * adds exactly slim_p8_01/02), so this prefix leaves free reports (slim_p2_*,
	 * slim_p7_*, slim_p9_*) and any third-party report untouched. New Pro builds
	 * never reach this path for a different prefix because their addon gate simply
	 * does not boot the addon; this lever only covers OLD Pro, whose report set is
	 * fixed. If a future Pro report ships under a new prefix in an OLD build, add it here.
	 *
	 * @param array $reports
	 * @return array
	 */
	public static function stripProReports($reports)
	{
		if (!\is_array($reports)) {
			return $reports;
		}

		foreach (\array_keys($reports) as $id) {
			if (\is_string($id) && 0 === \strpos($id, 'slim_p8_')) {
				unset($reports[$id]);
			}
		}

		return $reports;
	}
}
