<?php

namespace SlimStat\Services\Admin;

/**
 * Renders the Pro license activation alert in the WordPress admin and loads its
 * stylesheet, only on SlimStat screens for capable users when Pro is installed
 * but unlicensed. Persistent (non-dismissible) because it explains why Pro
 * features are paused; it self-clears the moment the license becomes valid.
 */
class LicenseAlert
{
	/** Stylesheet handle. */
	private const HANDLE = 'wp-slimstat-license-alert';

	/** Memoised shouldShow() result for the current request. */
	private static $show = null;

	/**
	 * Register the admin hooks. Both callbacks re-check the same guard, so this
	 * is safe to call on every request; it no-ops outside the admin and on
	 * non-SlimStat screens.
	 *
	 * @return void
	 */
	public static function register()
	{
		\add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
		\add_action('admin_notices', [self::class, 'render']);
	}

	/**
	 * Enqueue the alert stylesheet only when the alert will actually show.
	 *
	 * @return void
	 */
	public static function enqueue()
	{
		if (!self::shouldShow()) {
			return;
		}

		\wp_enqueue_style(
			self::HANDLE,
			\plugins_url('/admin/assets/css/license-alert.css', SLIMSTAT_FILE),
			['dashicons'],
			SLIMSTAT_ANALYTICS_VERSION
		);
	}

	/**
	 * Render the alert banner.
	 *
	 * @return void
	 */
	public static function render()
	{
		if (!self::shouldShow()) {
			return;
		}

		// State A: no key entered yet (finish setup). State B: key present but
		// inactive/expired (reactivate). The partial reads $state.
		$state = ConditionTagEvaluator::hasNoLicense() ? 'no-key' : 'inactive';

		include \plugin_dir_path(SLIMSTAT_FILE) . 'admin/view/partials/license-alert.php';
	}

	/**
	 * Whether the alert applies to the current request: a capable user, on a
	 * SlimStat admin screen, with Pro installed but not licensed.
	 *
	 * Ordered cheap → expensive so the costly pro_is_installed() (a file include
	 * + option read) runs only for admins on SlimStat screens. Memoised because
	 * enqueue() and render() both ask within the same request.
	 *
	 * @return bool
	 */
	private static function shouldShow()
	{
		if (null !== self::$show) {
			return self::$show;
		}

		if (!\current_user_can('manage_options') || !self::isSlimStatPage()) {
			return self::$show = false;
		}

		return self::$show = (\wp_slimstat::pro_is_installed() && !\wp_slimstat::pro_license_is_valid());
	}

	/**
	 * Is the current admin screen a SlimStat page (reports, settings/License tab,
	 * etc.)? Mirrors the detection used by the migration notice.
	 *
	 * @return bool
	 */
	private static function isSlimStatPage()
	{
		if (isset($_GET['page'])) {
			$page = \sanitize_text_field(\wp_unslash($_GET['page']));
			if (0 === \strpos($page, 'slim')) {
				return true;
			}
		}

		$screen = \function_exists('get_current_screen') ? \get_current_screen() : null;
		if ($screen instanceof \WP_Screen && '' !== $screen->id) {
			foreach (['slimview', 'slimconfig', 'slimlayout', 'slimpro', 'slimstat'] as $pattern) {
				if (false !== \strpos($screen->id, $pattern)) {
					return true;
				}
			}
		}

		return false;
	}
}
