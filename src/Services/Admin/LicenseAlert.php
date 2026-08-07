<?php

namespace SlimStat\Services\Admin;

/**
 * Renders the Pro license activation alert in the WordPress admin and loads its
 * assets, only on SlimStat screens for capable users when Pro is installed but
 * unlicensed. It self-clears the moment the license becomes valid.
 *
 * The admin can minimize it to a compact bar (persists, fully reversible). It
 * has no dismiss action on purpose: because it explains why Pro features are
 * paused, the only way to clear it for good is to fix the license, at which
 * point it self-clears. The view state lives in slimstat_options.
 */
class LicenseAlert
{
	/** Asset handle (shared by the stylesheet and the script registries). */
	private const HANDLE = 'wp-slimstat-license-alert';

	/** slimstat_options key for the per-site view state. */
	private const VIEW_KEY = 'license_alert_view';

	/** AJAX action + nonce name for persisting the view state. */
	private const AJAX_ACTION = 'slimstat_license_alert_view';

	/** Memoised shouldShow() result for the current request. */
	private static $show = null;

	/**
	 * Register the admin hooks. The enqueue/render callbacks re-check the same
	 * guard, so this is safe to call on every request; it no-ops outside the admin
	 * and on non-SlimStat screens. The AJAX handler persists the view state.
	 *
	 * @return void
	 */
	public static function register()
	{
		\add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
		\add_action('admin_notices', [self::class, 'render']);
		\add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'ajaxSaveView']);
	}

	/**
	 * Current view state: 'full' (default) or 'min' (collapsed). Any other stored
	 * value (e.g. a legacy 'dismissed') falls back to 'full' so the banner shows.
	 *
	 * @return string
	 */
	private static function view()
	{
		$view = \wp_slimstat::$settings[self::VIEW_KEY] ?? 'full';
		return \in_array($view, ['full', 'min'], true) ? $view : 'full';
	}

	/**
	 * Persist the view state. Re-reads the option fresh from the store that
	 * rendered the banner (site or network) and writes only the view key, so a
	 * concurrent settings edit is never clobbered.
	 *
	 * @return void
	 */
	public static function ajaxSaveView()
	{
		\check_ajax_referer(self::AJAX_ACTION, 'nonce');

		if (!\current_user_can('manage_options')) {
			\wp_send_json_error();
		}

		$view = isset($_POST['view']) ? \sanitize_key(\wp_unslash($_POST['view'])) : '';
		if (!\in_array($view, ['full', 'min'], true)) {
			\wp_send_json_error();
		}

		// Write back to the same store that rendered the banner. The JS echoes the
		// scope resolved at enqueue; network scope is honoured only for users who
		// can manage network options, so a site admin can't touch the network store.
		$network = isset($_POST['scope'])
			&& 'network' === $_POST['scope']
			&& \current_user_can('manage_network_options');

		$options = $network ? \get_site_option('slimstat_options', []) : \get_option('slimstat_options', []);
		if (!\is_array($options)) {
			$options = [];
		}
		$options[self::VIEW_KEY] = $view;

		if ($network) {
			\update_site_option('slimstat_options', $options);
		} else {
			\update_option('slimstat_options', $options);
		}

		// Keep the in-memory copy consistent for the rest of this request.
		\wp_slimstat::$settings[self::VIEW_KEY] = $view;

		\wp_send_json_success();
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

		// Register the design tokens so the banner is genuinely token-driven on
		// every SlimStat screen (not just the ones that already enqueue tokens.css);
		// re-registering an existing handle is a no-op.
		\wp_register_style(
			'wp-slimstat-tokens',
			\plugins_url('/admin/assets/css/tokens.css', SLIMSTAT_FILE),
			[],
			SLIMSTAT_ANALYTICS_VERSION
		);

		\wp_enqueue_style(
			self::HANDLE,
			\plugins_url('/admin/assets/css/license-alert.css', SLIMSTAT_FILE),
			['dashicons', 'wp-slimstat-tokens'],
			SLIMSTAT_ANALYTICS_VERSION
		);

		// Tiny dependency-free script for copy-to-clipboard + minimize/expand.
		\wp_enqueue_script(
			self::HANDLE,
			\plugins_url('/admin/assets/js/license-alert.js', SLIMSTAT_FILE),
			[],
			SLIMSTAT_ANALYTICS_VERSION,
			true
		);

		\wp_localize_script(self::HANDLE, 'SlimStatLicenseAlert', [
			'ajaxUrl' => \admin_url('admin-ajax.php'),
			'action'  => self::AJAX_ACTION,
			'nonce'   => \wp_create_nonce(self::AJAX_ACTION),
			// Tell the save handler which store rendered the banner, so a minimize
			// on a network-admin screen persists to the network option (admin-ajax
			// can't infer this — is_network_admin() is always false there).
			'scope'   => \wp_slimstat::settings_use_network_store() ? 'network' : 'site',
		]);
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
		// inactive/expired (reactivate). The partial reads $state and $minimized.
		$state     = ConditionTagEvaluator::hasNoLicense() ? 'no-key' : 'inactive';
		$minimized = ('min' === self::view());

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

		if (!\wp_slimstat::pro_is_installed() || \wp_slimstat::pro_license_is_valid()) {
			return self::$show = false;
		}

		return self::$show = true;
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
