<?php
/**
 * Pro license activation alert.
 *
 * Rendered by SlimStat\Services\Admin\LicenseAlert on SlimStat admin screens
 * when Pro is installed but the license is missing, inactive or expired.
 *
 * @var string $state 'no-key' (finish setup) or 'inactive' (reactivate).
 */

if (!defined('ABSPATH')) {
	exit;
}

$slimstat_la_state = (isset($state) && 'no-key' === $state) ? 'no-key' : 'inactive';

$slimstat_la_utm = [
	'utm_source'   => 'wp-slimstat',
	'utm_medium'   => 'license-alert',
	'utm_campaign' => 'reactivate',
	'utm_content'  => ('no-key' === $slimstat_la_state) ? 'state-a' : 'state-b',
];

$slimstat_la_pricing_url     = add_query_arg($slimstat_la_utm, 'https://wp-slimstat.com/pricing/');
$slimstat_la_account_url     = add_query_arg($slimstat_la_utm, 'https://wp-slimstat.com/my-account/');
$slimstat_la_license_tab_url = admin_url('admin.php?page=slimconfig&tab=8');
$slimstat_la_support_email   = 'support@wp-slimstat.com';
$slimstat_la_coupon          = 'REACTIVATE';

// Visually-hidden cue appended to links that open a new tab.
$slimstat_la_newtab = '<span class="screen-reader-text">' . esc_html__(' (opens in a new tab)', 'wp-slimstat') . '</span>';

if ('no-key' === $slimstat_la_state) {
	$slimstat_la_heading   = __('SlimStat Pro features need a license', 'wp-slimstat');
	$slimstat_la_body      = __('You are running SlimStat Pro, but no license key is set, so Pro reports and addons are paused. Add a key to switch them back on. Your tracking and existing data keep working in the meantime.', 'wp-slimstat');
	$slimstat_la_primary   = __('Get SlimStat Pro', 'wp-slimstat');
	$slimstat_la_secondary = __('I already have a key', 'wp-slimstat');
} else {
	$slimstat_la_heading   = __('SlimStat Pro features are turned off', 'wp-slimstat');
	$slimstat_la_body      = __('Your license is inactive or expired, so Pro reports and addons are paused. Reactivate it to turn them back on. Your tracking and existing data are unaffected.', 'wp-slimstat');
	$slimstat_la_primary   = __('Reactivate your license', 'wp-slimstat');
	$slimstat_la_secondary = __('Find your license key', 'wp-slimstat');
}
$slimstat_la_minimized = !empty($minimized);
?>
<div class="notice slimstat-notice notice-warning slimstat-license-alert<?php echo $slimstat_la_minimized ? ' is-minimized' : ''; ?>" role="region" aria-label="<?php esc_attr_e('SlimStat Pro license', 'wp-slimstat'); ?>">
	<div class="slimstat-license-alert__controls">
		<button type="button" class="slimstat-license-alert__control slimstat-license-alert__minimize" data-slimstat-alert-toggle aria-expanded="<?php echo $slimstat_la_minimized ? 'false' : 'true'; ?>" data-label-minimize="<?php esc_attr_e('Minimize this notice', 'wp-slimstat'); ?>" data-label-expand="<?php esc_attr_e('Expand this notice', 'wp-slimstat'); ?>" aria-label="<?php echo $slimstat_la_minimized ? esc_attr__('Expand this notice', 'wp-slimstat') : esc_attr__('Minimize this notice', 'wp-slimstat'); ?>">
			<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
		</button>
	</div>
	<div class="slimstat-license-alert__icon" aria-hidden="true">
		<span class="dashicons dashicons-lock"></span>
	</div>
	<div class="slimstat-license-alert__body">
		<h2 class="slimstat-license-alert__title"><?php echo esc_html($slimstat_la_heading); ?></h2>
		<p class="slimstat-license-alert__text"><?php echo esc_html($slimstat_la_body); ?></p>

		<div class="slimstat-license-alert__actions">
			<a class="button button-primary slimstat-license-alert__cta" href="<?php echo esc_url($slimstat_la_pricing_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($slimstat_la_primary) . $slimstat_la_newtab; ?></a>
			<a class="slimstat-license-alert__link" href="<?php echo esc_url($slimstat_la_account_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($slimstat_la_secondary) . $slimstat_la_newtab; ?></a>
			<a class="slimstat-license-alert__link" href="<?php echo esc_url($slimstat_la_license_tab_url); ?>"><?php esc_html_e('Enter your license key', 'wp-slimstat'); ?></a>
		</div>

		<p class="slimstat-license-alert__meta">
			<?php
			$slimstat_la_copy_aria = sprintf(
				/* translators: %s: coupon code */
				__('Copy coupon code %s to the clipboard', 'wp-slimstat'),
				$slimstat_la_coupon
			);
			$slimstat_la_coupon_btn =
				'<button type="button" class="slimstat-license-alert__coupon"'
				. ' data-slimstat-copy="' . esc_attr($slimstat_la_coupon) . '"'
				. ' data-copied-label="' . esc_attr__('Copied to clipboard', 'wp-slimstat') . '"'
				. ' aria-label="' . esc_attr($slimstat_la_copy_aria) . '">'
				. '<span class="slimstat-license-alert__coupon-code">' . esc_html($slimstat_la_coupon) . '</span>'
				. '<span class="slimstat-license-alert__coupon-icon slimstat-license-alert__coupon-icon--copy dashicons dashicons-clipboard" aria-hidden="true"></span>'
				. '<span class="slimstat-license-alert__coupon-icon slimstat-license-alert__coupon-icon--done dashicons dashicons-yes" aria-hidden="true"></span>'
				. '</button>';
			/* translators: %s: discount coupon code, shown as a click-to-copy chip. */
			printf(esc_html__('New or renewing? Use code %s at checkout.', 'wp-slimstat'), $slimstat_la_coupon_btn);
			?>
			<span class="screen-reader-text" aria-live="polite" data-slimstat-copy-live></span>
		</p>

		<p class="slimstat-license-alert__support">
			<?php
			$slimstat_la_support_link = '<a href="' . esc_url('mailto:' . antispambot($slimstat_la_support_email)) . '">' . esc_html(antispambot($slimstat_la_support_email)) . '</a>';
			printf(
				/* translators: %s: support email address link. */
				esc_html__('Have a valid key but cannot find it? Email %s.', 'wp-slimstat'),
				$slimstat_la_support_link
			);
			?>
		</p>
	</div>
</div>
