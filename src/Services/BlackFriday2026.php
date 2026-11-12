<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Services;

use SlimStat\Utils\ProLinks;

/** Black Friday fallback for sites without a Horizon header campaign. Remove after the sale. */
final class BlackFriday2026
{
	const CODE     = 'BFCM2026';
	const DISCOUNT = 30;
	const START    = '2026-11-20T00:00:00Z';
	const END      = '2026-12-01T23:59:00Z';
	const DISMISSED    = 'slimstat_bf2026_dismissed';
	const ACTION       = 'slimstat_bf2026_dismiss';

	private static $rendered = false;

	/** The offer opens and closes at the same UTC instant for every site. */
	public static function in_window(\DateTimeImmutable $now): bool
	{
		return new \DateTimeImmutable(self::START) <= $now && $now < new \DateTimeImmutable(self::END);
	}

	/** Cheapest gate first; every input is already in memory, so no queries are added. */
	public static function visible(?bool $pro = null, ?bool $migration_owed = null): bool
	{
		// A site can switch the strip off; the filter decides the date only, never who sees it.
		if (!apply_filters('slimstat_black_friday_2026_banner', self::in_window(current_datetime()))) {
			return false;
		}
		// The current user's meta is loaded with their capabilities, so this reads memory, not the database.
		if (get_user_meta(get_current_user_id(), self::DISMISSED, true) || 'on' !== (\wp_slimstat::$settings['display_notifications'] ?? 'on')) {
			return false;
		}
		// can_view_stats() also gates enqueue_promotions(), which carries the strip's CSS and JS.
		if (($pro ?? \wp_slimstat::pro_plugin_active()) || !current_user_can('manage_options') || !\wp_slimstat_admin::can_view_stats()) {
			return false;
		}
		return !($migration_owed ?? (($manager = \SlimStat\Migration\MigrationService::manager()) && $manager->needsMigration()));
	}

	/** Prints the strip at most once per request; true when it printed. */
	public static function render(string $placement = 'bar', ?bool $pro = null, ?bool $migration_owed = null): bool
	{
		if (self::$rendered || !self::visible($pro, $migration_owed)
			|| ('dashboard' !== $placement && \SlimStat\Services\Admin\MarketingCampaign::banner())) {
			return false;
		}
		self::$rendered = true;
		$dashboard      = 'dashboard' === $placement;
		$discount       = number_format_i18n(self::DISCOUNT) . '%';
		$last_day       = wp_date(/* translators: date format for the offer's last day, see https://www.php.net/manual/datetime.format.php */ __('F j', 'wp-slimstat'), (new \DateTimeImmutable(self::END))->getTimestamp(), new \DateTimeZone('UTC'));
		?>
<div id="slimstat-bf-2026"<?php echo $dashboard ? ' class="notice"' : ''; ?> role="region" aria-label="<?php echo esc_attr(__('Black Friday offer', 'wp-slimstat')); ?>" data-slimstat-bf26-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-slimstat-bf26-nonce="<?php echo esc_attr(wp_create_nonce(self::ACTION)); ?>" data-slimstat-bf26-error="<?php echo esc_attr(__('Could not save dismissal. Try again.', 'wp-slimstat')); ?>">
	<div class="slimstat-bf26__copy">
		<p class="slimstat-bf26__title"><?php echo esc_html(sprintf(/* translators: %s: discount with its percent sign, e.g. 30% */ __('Black Friday: %s off SlimStat Pro', 'wp-slimstat'), $discount)); ?></p>
		<p class="slimstat-bf26__value"><?php echo esc_html(sprintf(/* translators: %s: last day of the offer, e.g. December 1 */ __('Pro adds funnels, heatmaps for every page, email reports and data export. The discount covers your first payment. Ends %s at 23:59 UTC.', 'wp-slimstat'), $last_day)); ?></p>
	</div>
	<div class="slimstat-bf26__stub">
		<span class="slimstat-bf26__label"><?php echo esc_html(_x('Code', 'discount code label', 'wp-slimstat')); ?></span>
		<code class="slimstat-bf26__code" dir="ltr"><?php echo esc_html(self::CODE); ?></code>
		<button type="button" class="slimstat-bf26__copybtn" data-slimstat-bf26-copied="<?php echo esc_attr(__('Copied', 'wp-slimstat')); ?>" data-slimstat-bf26-selected="<?php echo esc_attr(__('Code selected', 'wp-slimstat')); ?>"><?php echo esc_html(__('Copy code', 'wp-slimstat')); ?></button>
		<span class="screen-reader-text" role="status"></span>
	</div>
	<a class="slimstat-bf26__cta" href="<?php echo esc_url(ProLinks::tag('https://wp-slimstat.com/black-friday/', 'bfcm2026', $dashboard ? 'dashboard' : 'banner')); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('See Black Friday pricing', 'wp-slimstat')); ?><span class="screen-reader-text"> <?php echo esc_html(__('(opens in a new tab)', 'wp-slimstat')); ?></span></a>
	<button type="button" class="slimstat-bf26__dismiss" aria-label="<?php echo esc_attr(__('Dismiss Black Friday offer', 'wp-slimstat')); ?>"><span aria-hidden="true">&times;</span></button>
</div>
		<?php
		return true;
	}

	/** admin_notices on the main Dashboard only; the strip takes the release notice's slot there. */
	public static function dashboard_notice(): void
	{
		$screen = get_current_screen();
		if ($screen && 'dashboard' === $screen->id && self::render('dashboard')) {
			remove_action('admin_notices', ['wp_slimstat_admin', 'show_latest_news'], 10);
		}
	}

	/** wp_ajax_slimstat_bf2026_dismiss: the dismissal lives on the user, so every browser and device honours it. */
	public static function dismiss(): void
	{
		if (!check_ajax_referer(self::ACTION, 'security', false) || !current_user_can('manage_options')) {
			wp_send_json_error(null, 403);
		}
		$user = get_current_user_id();
		// false also means the value was already there (dismissed in another tab), which is still a dismissal.
		if (!update_user_meta($user, self::DISMISSED, 1) && !get_user_meta($user, self::DISMISSED, true)) {
			wp_send_json_error(null, 500);
		}
		wp_send_json_success();
	}

	/** @internal PHPUnit runs many requests in one process. */
	public static function reset_for_tests(): void
	{
		self::$rendered = false;
	}
}
