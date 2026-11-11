<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Services;

use SlimStat\Utils\ProLinks;

/**
 * Black Friday 2026 strip for Free admins, Nov 23 to Nov 30 in the site timezone.
 * Delete after 2026-12-01 with its fenced blocks in promotions.css and promotions.js,
 * the header.php and init() call sites, and its tests.
 */
final class BlackFriday2026
{
	const CODE     = 'BFCM2026';
	const DISCOUNT = 30;
	const START    = '2026-11-23 00:00:00';
	const END      = '2026-12-01 00:00:00';
	const COOKIE   = 'slimstat_bf2026_dismissed';

	private static $rendered = false;

	/** Start and end are read in $now's own zone, named or a fixed offset. */
	public static function in_window(\DateTimeImmutable $now): bool
	{
		$zone = $now->getTimezone();
		return new \DateTimeImmutable(self::START, $zone) <= $now && $now < new \DateTimeImmutable(self::END, $zone);
	}

	/** Cheapest gate first; every input is already in memory, so no queries are added. */
	public static function visible(?bool $pro = null, ?bool $migration_owed = null): bool
	{
		// A site can switch the strip off; the filter decides the date only, never who sees it.
		if (!apply_filters('slimstat_black_friday_2026_banner', self::in_window(current_datetime()))) {
			return false;
		}
		if (!empty($_COOKIE[self::COOKIE . '_' . get_current_user_id()]) || 'on' !== (\wp_slimstat::$settings['display_notifications'] ?? 'on')) {
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
		if (self::$rendered || !self::visible($pro, $migration_owed)) {
			return false;
		}
		self::$rendered = true;
		$dashboard      = 'dashboard' === $placement;
		$discount       = number_format_i18n(self::DISCOUNT);
		$last_day       = wp_date(/* translators: date format for the offer's last day, see https://www.php.net/manual/datetime.format.php */ __('F j', 'wp-slimstat'), (new \DateTimeImmutable(self::END, wp_timezone()))->getTimestamp() - 1, wp_timezone());
		?>
<div id="slimstat-bf-2026"<?php echo $dashboard ? ' class="notice"' : ''; ?> role="region" aria-label="<?php echo esc_attr(__('Black Friday offer', 'wp-slimstat')); ?>" data-slimstat-bf26-cookie="<?php echo esc_attr(self::COOKIE . '_' . get_current_user_id()); ?>" data-slimstat-bf26-path="<?php echo esc_attr((string) wp_parse_url(admin_url(), PHP_URL_PATH)); ?>">
	<div class="slimstat-bf26__copy">
		<p class="slimstat-bf26__title"><?php echo esc_html(sprintf(/* translators: %s: discount percentage, e.g. 30 */ __('Black Friday: %s%% off SlimStat Pro', 'wp-slimstat'), $discount)); ?></p>
		<p class="slimstat-bf26__value"><?php echo esc_html(sprintf(/* translators: %s: last day of the offer, e.g. November 30 */ __('Pro adds funnels, heatmaps for every page, email reports and data export. The discount covers your first year. Ends %s.', 'wp-slimstat'), $last_day)); ?></p>
	</div>
	<div class="slimstat-bf26__stub">
		<span class="slimstat-bf26__label"><?php echo esc_html(__('Code', 'wp-slimstat')); ?></span>
		<code class="slimstat-bf26__code" dir="ltr"><?php echo esc_html(self::CODE); ?></code>
		<button type="button" class="slimstat-bf26__copybtn" data-slimstat-bf26-copied="<?php echo esc_attr(__('Copied', 'wp-slimstat')); ?>" data-slimstat-bf26-selected="<?php echo esc_attr(__('Code selected', 'wp-slimstat')); ?>"><?php echo esc_html(__('Copy code', 'wp-slimstat')); ?></button>
		<span class="screen-reader-text" role="status"></span>
	</div>
	<a class="slimstat-bf26__cta" href="<?php echo esc_url(ProLinks::url('bf-2026', $dashboard ? 'dashboard' : 'banner')); ?>" target="_blank" rel="noopener"><?php echo esc_html(__('See Black Friday pricing', 'wp-slimstat')); ?><span class="screen-reader-text"> <?php echo esc_html(__('(opens in a new tab)', 'wp-slimstat')); ?></span></a>
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

	/** @internal PHPUnit runs many requests in one process. */
	public static function reset_for_tests(): void
	{
		self::$rendered = false;
	}
}
