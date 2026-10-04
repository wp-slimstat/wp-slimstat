<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by a report/admin rendering method; these are local template variables, not plugin globals.
/** Revenue-first dashboard; calculations live in Ecommerce\Report. @license GPL-2.0-or-later */
if (!defined('ABSPATH')) { exit; }
use SlimStat\Ecommerce\Integration;
use SlimStat\Ecommerce\Report;

$available = $available ?? false;
$data = $data ?? null;
$error = $error ?? '';
$state = $state ?? [];

$setup = static function ($label) {
	if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) { return; }
	?>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="slimstat_ecommerce_setup">
		<?php wp_nonce_field('slimstat_ecommerce_setup'); ?>
		<button type="submit" class="button button-primary"><?php echo esc_html($label); ?></button>
	</form>
	<?php
};
?>
<div class="ss-ec" data-ecommerce>
	<div class="ss-ec-heading">
		<div><h1><?php esc_html_e('Ecommerce', 'wp-slimstat'); ?></h1><p><?php esc_html_e('Sales, orders and the traffic that drove them.', 'wp-slimstat'); ?></p></div>
		<div class="ss-ec-refresh-line"><span class="ss-ec-updated"><?php esc_html_e('Updated just now', 'wp-slimstat'); ?></span> · <a href="#" class="refresh" aria-label="<?php esc_attr_e('Refresh Ecommerce reports', 'wp-slimstat'); ?>"><?php esc_html_e('Refresh', 'wp-slimstat'); ?></a></div>
	</div>
	<p class="ss-ec-feedback screen-reader-text" role="status" aria-live="polite"></p>
	<?php if (!$available) : ?>
		<div class="ss-ec-state"><span class="dashicons dashicons-cart" aria-hidden="true"></span><h2><?php esc_html_e('Bring your store into focus', 'wp-slimstat'); ?></h2><p><?php esc_html_e('Activate WooCommerce 8.3 or later on 64-bit PHP to connect orders with your SlimStat traffic. Existing traffic reports continue to work.', 'wp-slimstat'); ?></p><?php if (current_user_can('activate_plugins')) : ?><a class="button" href="<?php echo esc_url(admin_url('plugins.php')); ?>"><?php esc_html_e('Manage plugins', 'wp-slimstat'); ?></a><?php endif; ?></div>
	<?php elseif (!Integration::ready()) : ?>
		<div class="ss-ec-state"><h2><?php esc_html_e('Understand what drives your sales', 'wp-slimstat'); ?></h2><p><?php esc_html_e('Connect your WooCommerce orders to see net sales, products, acquisition and the observed purchase journey in one place.', 'wp-slimstat'); ?></p><p><?php esc_html_e('Setup builds a reporting table and a visit index, then imports orders in the background. The index can take time on a large site. WooCommerce orders stay unchanged; visit association follows your existing consent and tracking settings.', 'wp-slimstat'); ?></p><?php $setup(__('Set up Ecommerce', 'wp-slimstat')); ?></div>
	<?php elseif ($error) : ?>
		<div class="ss-ec-state" role="alert"><h2><?php esc_html_e('This report needs attention', 'wp-slimstat'); ?></h2><p><?php echo esc_html($error); ?></p><?php $setup(__('Rebuild reports', 'wp-slimstat')); ?></div>
	<?php elseif (is_array($data)) :
		$current = $data['current']; $previous = $data['previous']; $journey = $data['journey'];
		$currency = $data['currency']; $orders = (int) $current['orders'];
		$coverage = $orders ? 100 * (int) $current['matched'] / $orders : null;
		$rate = $journey['visits'] >= 100 ? 100 * $journey['buyers'] / $journey['visits'] : null;
		$provisional = Integration::provisional($state); $failed = !empty($state['error']);
		$retained = Integration::retentionStart() <= $data['previous_range'][0];
		$money = static function ($amount) use ($currency) { return wp_kses_post(Report::money($amount, $currency)); };
		$delta = static function ($value, $before) use ($retained, $provisional) {
			if (!$retained || $provisional) { return __('Comparison incomplete', 'wp-slimstat'); }
			if ((float) $before <= 0) { return __('No positive comparison baseline', 'wp-slimstat'); }
			$change = 100 * ((float) $value - (float) $before) / (float) $before;
			return ($change > 0 ? '+' : '') . number_format_i18n($change, 1) . '%';
		};
		$metrics = [
			'net' => [__('Net sales', 'wp-slimstat'), $money($current['net']), $delta($current['net'], $previous['net']), __('Sales after discounts and refunds, excluding tax and shipping.', 'wp-slimstat')],
			'orders' => [__('Orders', 'wp-slimstat'), number_format_i18n($orders), $delta($orders, $previous['orders']), __('Processing, completed and refunded orders, by creation date.', 'wp-slimstat')],
			'aov' => [__('Average order value', 'wp-slimstat'), $orders ? $money((float) $current['net'] / $orders) : '—', !$orders ? __('No orders in this period', 'wp-slimstat') : ((int) $previous['orders'] ? $delta((float) $current['net'] / $orders, (float) $previous['net'] / (int) $previous['orders']) : __('No orders in the previous period', 'wp-slimstat')), __('Net sales divided by included orders in each interval.', 'wp-slimstat')],
			'rate' => [__('Tracked purchase rate', 'wp-slimstat'), null === $rate ? '—' : number_format_i18n($rate, 2) . '%', sprintf(/* translators: 1: buying visit count, 2: eligible visit count. */ __('%1$s buying / %2$s eligible visits', 'wp-slimstat'), number_format_i18n($journey['buyers']), number_format_i18n($journey['visits'])), __('Buying visits divided by eligible visits in each interval. At least 100 eligible visits are required per point; smaller samples are gaps, not zero.', 'wp-slimstat')],
		];
		$series = $data['series'];
		foreach (['current', 'previous'] as $period) {
			foreach ($series[$period] as &$point) {
				foreach (array_keys($metrics) as $metric) {
					$value = $point[$metric];
					if (null === $value) { $value = __('Not available', 'wp-slimstat'); }
					elseif ('net' === $metric || 'aov' === $metric) { $value = Report::plainMoney($value, $currency); }
					else { $value = 'rate' === $metric ? number_format_i18n($value, 2) . '%' : number_format_i18n($value); }
					$point['formatted'][$metric] = $value;
				}
			}
			unset($point);
		}
		?>
		<div class="ss-ec-toolbar">
			<span><?php esc_html_e('Order creation date', 'wp-slimstat'); ?></span>
			<details class="ss-ec-currency"><summary><?php echo esc_html(sprintf(/* translators: %s: currency code, e.g. USD */ __('Currency: %s', 'wp-slimstat'), $currency)); ?></summary><div><?php foreach ($data['currencies'] as $row) : ?><a class="slimstat-filter-link" href="<?php echo esc_url(wp_slimstat_reports::fs_url(Report::CURRENCY_FILTER . ' equals ' . rawurlencode($row['currency']))); ?>" <?php if ($currency === $row['currency']) { echo 'aria-current="true"'; } ?>><?php echo esc_html($row['currency']); ?></a><?php endforeach; ?></div></details>
			<label class="ss-ec-compare"><input type="checkbox" data-compare checked> <?php esc_html_e('Compare previous period', 'wp-slimstat'); ?></label>
			<a href="#ss-ec-definitions" class="noslimstat"><?php esc_html_e('Metric definitions', 'wp-slimstat'); ?></a>
		</div>
		<details id="ss-ec-quality" class="ss-ec-quality <?php echo $provisional || $failed ? 'is-warning' : ''; ?>">
			<summary><span class="dashicons <?php echo $provisional || $failed ? 'dashicons-warning' : 'dashicons-chart-pie'; ?>" aria-hidden="true"></span><span><strong><?php echo esc_html($provisional ? ($failed ? __('Sync needs attention · figures are provisional', 'wp-slimstat') : __('Importing your orders · figures are provisional', 'wp-slimstat')) : ($failed ? __('Some orders could not be imported', 'wp-slimstat') : __('WooCommerce totals, with tracked visit coverage', 'wp-slimstat'))); ?></strong><span class="ss-ec-quality-summary"><?php echo esc_html($orders ? sprintf(/* translators: 1: linked order count, 2: included order count. */ __('%1$s of %2$s orders linked to a tracked visit', 'wp-slimstat'), number_format_i18n((int) $current['matched']), number_format_i18n($orders)) : __('No included orders in this period', 'wp-slimstat')); ?><?php if (!$retained) { echo ' · ' . esc_html__('Comparison includes unavailable history', 'wp-slimstat'); } ?></span></span><span class="ss-ec-quality-action"><?php esc_html_e('Details', 'wp-slimstat'); ?></span></summary>
			<div class="ss-ec-quality-body">
				<?php if ($provisional || $failed) : ?><p role="status"><?php if ($provisional) { echo esc_html(sprintf(/* translators: %s: number of orders processed. */ __('%s orders processed. Figures are provisional until synchronization completes.', 'wp-slimstat'), number_format_i18n((int) ($state['imported'] ?? 0)))) . ' '; } ?><?php if (!empty($state['failed_order'])) { echo esc_html(sprintf(/* translators: %s: WooCommerce order ID. */ __('Order %s could not be synchronized. Review it in WooCommerce and check database status, then rebuild. Other orders can continue importing.', 'wp-slimstat'), (string) (int) $state['failed_order'])); } elseif ($failed) { esc_html_e('An order change could not be synchronized. Retry the import to reconcile with WooCommerce.', 'wp-slimstat'); } ?></p><?php if ($failed) { $setup(__('Retry import', 'wp-slimstat')); } endif; ?>
				<p><?php esc_html_e('WooCommerce supplies sales totals. Consent, excluded traffic, older orders and deleted visits can leave gaps in traffic attribution.', 'wp-slimstat'); ?> <?php echo esc_html(sprintf(/* translators: %s: number of orders with WooCommerce attribution only. */ __('%s additional orders have WooCommerce acquisition data without a visit link.', 'wp-slimstat'), number_format_i18n((int) $current['wc_attributed']))); ?></p>
				<?php if (!$retained) : ?><p><?php esc_html_e('Part of this range or its comparison is outside your analytics retention window. Older commerce records are unavailable; comparisons may be incomplete.', 'wp-slimstat'); ?></p><?php endif; ?>
				<p><?php esc_html_e('Reports cache for up to one minute. Refresh checks the latest synchronized data.', 'wp-slimstat'); ?></p>
			</div>
		</details>
		<?php if ($data['filtered']) : ?><p class="ss-ec-scope"><?php esc_html_e('Traffic filters are active: only orders linked to matching retained visits are included. Unlinked orders are excluded.', 'wp-slimstat'); ?></p><?php endif; ?>
		<section class="ss-ec-performance" aria-label="<?php esc_attr_e('Store performance', 'wp-slimstat'); ?>" data-series="<?php echo esc_attr(wp_json_encode($series)); ?>" data-currency="<?php echo esc_attr($currency); ?>">
			<div class="ss-ec-kpis" role="group" aria-label="<?php esc_attr_e('Choose a chart metric', 'wp-slimstat'); ?>">
			<?php foreach ($metrics as $key => [$label, $value, $change, $description]) : ?>
				<button type="button" class="ss-ec-kpi" data-chart-metric="<?php echo esc_attr($key); ?>" data-description="<?php echo esc_attr($description); ?>" aria-pressed="<?php echo 'net' === $key ? 'true' : 'false'; ?>" aria-controls="ss-ec-chart"><span><?php echo esc_html($label); ?></span><strong data-metric="<?php echo esc_attr($key); ?>"><?php echo wp_kses_post($value); ?></strong><small><?php echo esc_html($change); ?></small></button>
			<?php endforeach; ?>
			</div>
			<figure class="ss-ec-chart" id="ss-ec-chart">
				<figcaption><div><strong data-chart-title><?php esc_html_e('Net sales', 'wp-slimstat'); ?></strong><span class="ss-ec-chart-unit"><?php echo esc_html($currency); ?></span></div><label><span class="screen-reader-text"><?php esc_html_e('Chart interval', 'wp-slimstat'); ?></span><select data-interval><?php foreach (['daily' => __('Daily', 'wp-slimstat'), 'weekly' => __('Weekly', 'wp-slimstat'), 'monthly' => __('Monthly', 'wp-slimstat')] as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($key, $series['interval']); ?> <?php disabled(('daily' === $key && $data['range'][1] - $data['range'][0] > 366 * DAY_IN_SECONDS) || ('weekly' === $key && $data['range'][1] - $data['range'][0] > 2562 * DAY_IN_SECONDS)); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></figcaption>
				<p class="ss-ec-chart-description" data-chart-description><?php echo esc_html($metrics['net'][3]); ?></p>
				<div class="ss-ec-canvas"><canvas aria-label="<?php esc_attr_e('Selected metric over time. Use the exact values table below for all dates and comparisons.', 'wp-slimstat'); ?>" role="img"></canvas><p data-chart-empty hidden><?php esc_html_e('Not enough observations to draw this trend. Try a wider interval or review tracking coverage.', 'wp-slimstat'); ?></p></div>
				<div class="ss-ec-chart-footer"><span class="ss-ec-legend-current"><?php esc_html_e('Selected period', 'wp-slimstat'); ?></span><span class="ss-ec-legend-previous" data-comparison-content><?php esc_html_e('Previous period', 'wp-slimstat'); ?></span><details class="ss-ec-exact"><summary><?php esc_html_e('View exact values', 'wp-slimstat'); ?></summary><div class="ss-ec-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e('Chart values and comparisons', 'wp-slimstat'); ?>"><table><thead><tr><th scope="col"><?php esc_html_e('Interval (site time)', 'wp-slimstat'); ?></th><th scope="col" data-value-heading><?php esc_html_e('Net sales', 'wp-slimstat'); ?></th><th scope="col" data-comparison-content><?php esc_html_e('Previous interval', 'wp-slimstat'); ?></th><th scope="col" data-comparison-content><?php esc_html_e('Previous value', 'wp-slimstat'); ?></th></tr></thead><tbody data-chart-table><?php foreach ($series['current'] as $index => $point) : ?><tr><th scope="row"><?php echo esc_html($point['label']); ?></th><td><?php echo esc_html($point['formatted']['net']); ?></td><td><?php echo esc_html($series['previous'][$index]['label']); ?></td><td><?php echo esc_html($series['previous'][$index]['formatted']['net']); ?></td></tr><?php endforeach; ?></tbody></table></div></details></div>
				<p class="ss-ec-comparison" data-comparison-content><?php echo esc_html(sprintf(/* translators: 1: previous period start date, 2: previous period end date. */ __('Compared with %1$s to %2$s (site time). Points align by elapsed time; partial intervals are labeled.', 'wp-slimstat'), Report::date('M j, Y H:i', $data['previous_range'][0]), Report::date('M j, Y H:i', $data['previous_range'][1]))); ?></p>
			</figure>
		</section>
		<?php if ($orders || !isset($data['groups']['campaign'])) : // Pro's reports have nothing to show in a range without orders. ?>
		<section class="ss-ec-discover" aria-labelledby="ss-ec-discover-title">
			<?php if (isset($data['groups']['campaign'])) : ?>
				<h2 id="ss-ec-discover-title"><?php esc_html_e('Your Pro reports are ready', 'wp-slimstat'); ?></h2>
				<p><?php esc_html_e('Start with a question. Explore the report, then use View report to see exact values and export your current selection.', 'wp-slimstat'); ?></p>
				<div class="ss-ec-discover-actions">
					<button type="button" class="button" data-open-report="campaign"><?php esc_html_e('Compare campaigns', 'wp-slimstat'); ?></button>
					<button type="button" class="button" data-open-report="device"><?php esc_html_e('Explore devices', 'wp-slimstat'); ?></button>
					<button type="button" class="button" data-open-report="coupon"><?php esc_html_e('Explore coupons', 'wp-slimstat'); ?></button>
				</div>
				<?php if (empty(wp_slimstat::$settings['slimstat_pro_license_status']) && current_user_can('manage_options')) : ?><p><a href="<?php echo esc_url(admin_url('admin.php?page=slimconfig&tab=8')); ?>"><?php esc_html_e('Activate your license', 'wp-slimstat'); ?></a> <?php esc_html_e('to receive Pro updates for this site.', 'wp-slimstat'); ?></p><?php endif; ?>
			<?php elseif (!wp_slimstat::pro_is_installed()) : ?>
				<h2 id="ss-ec-discover-title"><?php esc_html_e('Find your next revenue opportunity', 'wp-slimstat'); ?></h2>
				<p><?php esc_html_e('Pro adds campaign and landing-page revenue, device and customer segments, coupon discounts, product refunds and CSV exports. Your overview, channels, sources and products are included in Free.', 'wp-slimstat'); ?></p>
				<div class="ss-ec-discover-actions"><a class="button button-primary" href="<?php echo esc_url(current_user_can('manage_options') ? admin_url('admin.php?page=slimpro') : 'https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=plugin&utm_campaign=ecommerce'); ?>"><?php esc_html_e('See what Pro adds to Ecommerce', 'wp-slimstat'); ?></a><?php if (current_user_can('activate_plugins')) : ?><a href="<?php echo esc_url(admin_url('plugins.php')); ?>"><?php esc_html_e('Already have Pro? Activate the plugin', 'wp-slimstat'); ?></a><?php endif; ?></div>
			<?php else : ?>
				<h2 id="ss-ec-discover-title"><?php esc_html_e('Update Pro to explore more reports', 'wp-slimstat'); ?></h2>
				<p><?php esc_html_e('Your installed Pro version has not enabled Ecommerce reports. Update both SlimStat plugins to compatible versions.', 'wp-slimstat'); ?></p>
			<?php endif; ?>
		</section>
		<?php endif; ?>
		<div class="ss-ec-section-heading"><h2><?php esc_html_e('Revenue drivers', 'wp-slimstat'); ?></h2><p><?php esc_html_e('Explore the leading contributions to your sales.', 'wp-slimstat'); ?></p></div>
		<?php if (!$orders) : // One line for the section, not one "No results" per card (audit E2). ?>
		<p class="ss-ec-scope"><?php echo esc_html($provisional ? __('Orders are still importing. Revenue drivers fill in when synchronization completes.', 'wp-slimstat') : __('No WooCommerce orders in this period. Revenue drivers fill in after the first order from a tracked visit.', 'wp-slimstat')); ?></p>
		<?php else : ?>
		<div class="ss-ec-grid">
		<?php
		$titles = ['channel' => __('Channels', 'wp-slimstat'), 'source' => __('Sources', 'wp-slimstat'), 'product' => __('Products', 'wp-slimstat'), 'campaign' => __('Campaigns', 'wp-slimstat'), 'landing' => __('Landing pages', 'wp-slimstat'), 'device' => __('Devices', 'wp-slimstat'), 'customer' => __('Customer segments', 'wp-slimstat'), 'coupon' => __('Coupons', 'wp-slimstat')];
		$questions = ['channel' => __('Which channels contribute the most net sales?', 'wp-slimstat'), 'source' => __('Which sources contribute orders and revenue?', 'wp-slimstat'), 'product' => __('Which products contribute the most after refunds?', 'wp-slimstat'), 'campaign' => __('Which tagged campaigns contribute sales?', 'wp-slimstat'), 'landing' => __('Which entry pages are associated with sales?', 'wp-slimstat'), 'device' => __('How do sales differ across tracked devices?', 'wp-slimstat'), 'customer' => __('How do account and guest orders compare?', 'wp-slimstat'), 'coupon' => __('Which coupons account for the most discounts?', 'wp-slimstat')];
		$cards = [__('Acquisition', 'wp-slimstat') => ['channel', 'source', 'campaign'], __('Shopping', 'wp-slimstat') => ['product', 'coupon'], __('Audience', 'wp-slimstat') => ['device', 'customer'], __('Landing pages', 'wp-slimstat') => ['landing']];
		foreach ($cards as $title => $dimensions) :
			$dimensions = array_values(array_intersect($dimensions, array_keys($data['groups'])));
			if (!$dimensions) { continue; }
			?>
			<section class="ss-ec-card" aria-label="<?php echo esc_attr($title); ?>">
				<div class="ss-ec-card-heading"><h3><?php echo esc_html($title); ?></h3><span class="ss-ec-card-hint"><?php esc_html_e('Top 10', 'wp-slimstat'); ?></span></div>
				<div class="ss-ec-tabs" role="tablist" aria-label="<?php echo esc_attr($title); ?>"<?php echo 1 === count($dimensions) ? ' hidden' : ''; ?>><?php foreach ($dimensions as $index => $dimension) : ?><button type="button" role="tab" id="ss-ec-tab-<?php echo esc_attr($dimension); ?>" aria-controls="ss-ec-panel-<?php echo esc_attr($dimension); ?>" aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>" tabindex="<?php echo 0 === $index ? '0' : '-1'; ?>"><?php echo esc_html($titles[$dimension]); ?></button><?php endforeach; ?></div>
				<?php foreach ($dimensions as $index => $dimension) :
					$rows = $data['groups'][$dimension]; $isCoupon = 'coupon' === $dimension;
					$productDetails = 'product' === $dimension && apply_filters('slimstat_ecommerce_product_details', false);
					$measure = $isCoupon ? 'discount' : 'net'; $shown = 0;
					$ceiling = $rows ? max(array_map(static function ($row) use ($measure) { return abs((float) $row[$measure]); }, $rows)) : 0;
					?>
					<div class="ss-ec-report" id="ss-ec-panel-<?php echo esc_attr($dimension); ?>" role="tabpanel" aria-labelledby="ss-ec-tab-<?php echo esc_attr($dimension); ?>" tabindex="0" data-dimension="<?php echo esc_attr($dimension); ?>" <?php if ($index) { echo 'hidden'; } ?>>
						<p class="ss-ec-report-question"><?php echo esc_html($questions[$dimension]); ?></p>
						<div class="ss-ec-rank-heading"><span><?php echo esc_html($isCoupon ? __('Top by discounts', 'wp-slimstat') : __('Top by net sales', 'wp-slimstat')); ?></span><label><span class="screen-reader-text"><?php esc_html_e('Sort these results', 'wp-slimstat'); ?></span><select data-rank-metric><option value="<?php echo esc_attr($measure); ?>"><?php echo esc_html($isCoupon ? __('Discounts', 'wp-slimstat') : __('Net sales', 'wp-slimstat')); ?></option><option value="orders"><?php esc_html_e('Orders', 'wp-slimstat'); ?></option></select><button type="button" class="ss-ec-icon-button" data-sort aria-label="<?php esc_attr_e('Reverse sort order', 'wp-slimstat'); ?>" aria-pressed="false"><span class="dashicons dashicons-arrow-down-alt" aria-hidden="true"></span></button></label></div>
						<?php if (!$rows) : ?><div class="ss-ec-report-empty"><strong><?php esc_html_e('No results for this view', 'wp-slimstat'); ?></strong><?php if ($data['filtered']) : ?><p><?php esc_html_e('Try a different period or remove a filter to explore more activity.', 'wp-slimstat'); ?></p><?php endif; ?></div><?php else : ?>
						<ol class="ss-ec-rankings">
						<?php foreach ($rows as $rowIndex => $row) : $shown += (float) $row[$measure]; ?>
							<li data-rank-index="<?php echo esc_attr($rowIndex); ?>" data-rank-value="<?php echo esc_attr((string) (float) $row[$measure]); ?>" data-rank-orders="<?php echo esc_attr($row['orders']); ?>" data-rank-money="<?php echo esc_attr(Report::plainMoney($row[$measure], $currency)); ?>" <?php if ($rowIndex >= 5) { echo 'hidden'; } ?>>
								<span class="ss-ec-rank-bar" style="--ss-ec-share:<?php echo esc_attr((string) ($ceiling ? 100 * abs((float) $row[$measure]) / $ceiling : 0)); ?>%" aria-hidden="true"></span><span class="ss-ec-rank-name"><?php echo esc_html(Report::label($dimension, $row)); ?></span><strong class="ss-ec-rank-value"><?php echo wp_kses_post($money($row[$measure])); ?></strong>
								<?php $filterColumns = ['channel' => 'traffic_channel', 'source' => 'traffic_source', 'campaign' => 'utm_campaign']; if (isset($filterColumns[$dimension]) && (int) $row['matched'] > 0 && '' !== (string) $row['dimension']) : ?><a class="slimstat-filter-link ss-ec-row-filter" href="<?php echo esc_url(wp_slimstat_reports::fs_url($filterColumns[$dimension] . ' equals ' . rawurlencode($row['dimension']))); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: the acquisition dimension value. */ __('Filter tracked visits by %s; unlinked orders will be excluded', 'wp-slimstat'), Report::label($dimension, $row))); ?>"><span class="dashicons dashicons-filter" aria-hidden="true"></span></a><?php endif; ?>
							</li>
						<?php endforeach; ?>
						</ol>
						<?php endif; ?>
						<?php if (in_array($dimension, ['landing', 'device'], true) && $orders && !(int) $current['matched']) : ?><p class="ss-ec-footnote"><?php esc_html_e('No retained visit links in this period. Sales remain unattributed here.', 'wp-slimstat'); ?></p><?php endif; ?>
						<div class="ss-ec-report-footer"><button type="button" class="ss-ec-quiet" data-expand aria-expanded="false" aria-controls="ss-ec-detail-<?php echo esc_attr($dimension); ?>"><?php esc_html_e('View report', 'wp-slimstat'); ?><span aria-hidden="true"> ↗</span></button><details class="ss-ec-report-info"><summary><?php esc_html_e('About', 'wp-slimstat'); ?></summary><p><?php echo esc_html('product' === $dimension ? __('After item discounts and itemized refunds. Fees and amount-only refunds are unallocated adjustments.', 'wp-slimstat') : ($isCoupon ? __('Applied discounts, not incremental revenue. Orders using multiple coupons appear in multiple rows.', 'wp-slimstat') : ('customer' === $dimension ? __('Guest and registered-account orders; these are not new and returning customer counts.', 'wp-slimstat') : (in_array($dimension, ['landing', 'device'], true) ? __('Based only on retained SlimStat visit entries. Orders without matching traffic stay unattributed.', 'wp-slimstat') : __('Retained SlimStat visit attribution where available; otherwise recorded WooCommerce acquisition. Missing data stays unattributed.', 'wp-slimstat'))))); ?></p></details></div>
						<div class="ss-ec-report-detail" id="ss-ec-detail-<?php echo esc_attr($dimension); ?>" hidden>
							<p class="ss-ec-footnote"><?php esc_html_e('Up to 10 leading results. Sorting changes these results only. Current dates, currency and traffic filters apply.', 'wp-slimstat'); ?></p>
							<div class="ss-ec-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr($titles[$dimension]); ?>"><table><thead><tr><th scope="col"><?php echo esc_html($titles[$dimension]); ?></th><th scope="col"><?php esc_html_e('Orders', 'wp-slimstat'); ?></th><?php if ('product' === $dimension) : ?><th scope="col"><?php esc_html_e('Net units', 'wp-slimstat'); ?></th><?php endif; ?><?php if ($productDetails) : ?><th scope="col"><?php esc_html_e('Item refunds', 'wp-slimstat'); ?></th><?php endif; ?><th scope="col"><?php echo esc_html($isCoupon ? __('Discounts', 'wp-slimstat') : __('Net sales', 'wp-slimstat')); ?></th></tr></thead><tbody><?php foreach ($rows as $rowIndex => $row) : ?><tr data-rank-index="<?php echo esc_attr($rowIndex); ?>"><th scope="row"><?php echo esc_html(Report::label($dimension, $row)); ?></th><td><?php echo esc_html(number_format_i18n((int) $row['orders'])); ?></td><?php if ('product' === $dimension) : ?><td><?php echo esc_html(number_format_i18n((float) $row['quantity'], (float) $row['quantity'] == (int) $row['quantity'] ? 0 : 2)); ?></td><?php endif; ?><?php if ($productDetails) : ?><td><?php echo wp_kses_post($money($row['refund'])); ?></td><?php endif; ?><td><?php echo wp_kses_post($money($row[$measure])); ?></td></tr><?php endforeach; ?></tbody></table></div>
							<?php if (!$isCoupon) : $total = (float) ('product' === $dimension ? $current['product_net'] : $current['net']); if (abs($total - $shown) > 0.000001) : ?><p class="ss-ec-footnote"><?php esc_html_e('Other results:', 'wp-slimstat'); ?> <?php echo wp_kses_post($money($total - $shown)); ?></p><?php endif; endif; ?>
							<?php if ('product' === $dimension) : ?><p class="ss-ec-footnote"><?php esc_html_e('Unallocated adjustments:', 'wp-slimstat'); ?> <?php echo wp_kses_post($money((float) $current['net'] - (float) $current['product_net'])); ?></p><?php endif; ?>
							<?php do_action('slimstat_ecommerce_group_actions', $dimension, $data); ?>
						</div>
					</div>
				<?php endforeach; ?>
			</section>
		<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<section class="ss-ec-journey" id="ss-ec-journey"><div class="ss-ec-section-heading"><h2><?php esc_html_e('Purchase journey', 'wp-slimstat'); ?></h2><p><?php echo esc_html(sprintf(/* translators: 1: eligible visit count, 2: start date, 3: end date. */ __('Observed in %1$s eligible visits · %2$s to %3$s', 'wp-slimstat'), number_format_i18n($journey['visits']), Report::date('M j, Y', $data['range'][0]), Report::date('M j, Y', $data['range'][1]))); ?></p></div>
			<?php if (!$journey['visits'] || !array_sum([$journey['products'], $journey['carts'], $journey['checkouts'], $journey['buyers']])) : ?>
				<div class="ss-ec-journey-empty"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span><div><h3><?php esc_html_e('Journey data is not available yet', 'wp-slimstat'); ?></h3><p><?php esc_html_e('No shopping steps were observed in eligible tracked visits in this period. Order history cannot reconstruct these steps or prove abandonment.', 'wp-slimstat'); ?></p><a href="<?php echo esc_url(admin_url('admin.php?page=slimconfig')); ?>"><?php esc_html_e('Review tracking settings', 'wp-slimstat'); ?></a></div></div>
			<?php else : $prior = null; ?>
				<ol class="ss-ec-steps"><?php foreach (['products' => __('Viewed a product', 'wp-slimstat'), 'carts' => __('Added to cart', 'wp-slimstat'), 'checkouts' => __('Reached checkout', 'wp-slimstat'), 'completed' => __('Placed an included order', 'wp-slimstat')] as $key => $label) : ?><li><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html(number_format_i18n($journey[$key])); ?></strong><meter min="0" max="<?php echo esc_attr((string) max(1, $journey['products'])); ?>" value="<?php echo esc_attr((string) $journey[$key]); ?>" aria-label="<?php echo esc_attr($label); ?>"></meter><small><?php echo esc_html(null === $prior ? __('Observed product visits', 'wp-slimstat') : ($prior ? sprintf(/* translators: %s: percentage of visits that reached this step. */ __('%s%% of previous step', 'wp-slimstat'), number_format_i18n(100 * $journey[$key] / $prior, 1)) : __('No previous-step observations', 'wp-slimstat'))); ?></small></li><?php $prior = $journey[$key]; endforeach; ?></ol>
				<p class="ss-ec-footnote"><?php echo esc_html(sprintf(/* translators: %s: buying visit count. */ _n('%s buying visit did not follow every observed step. Missing events, express checkout or a different path may explain gaps; they do not prove abandonment.', '%s buying visits did not follow every observed step. Missing events, express checkout or a different path may explain gaps; they do not prove abandonment.', max(0, $journey['buyers'] - $journey['completed']), 'wp-slimstat'), number_format_i18n(max(0, $journey['buyers'] - $journey['completed'])))); ?> <button type="button" class="ss-ec-quiet" data-open-report="product"><?php esc_html_e('Explore products', 'wp-slimstat'); ?></button></p>
			<?php endif; ?>
			<details class="ss-ec-journey-context"><summary><?php esc_html_e('Observation window and coverage', 'wp-slimstat'); ?></summary><p><?php esc_html_e('Steps must occur in order within the same tracked visit and selected window. Journey tracking starts after setup; imported orders do not create historical steps. Counts describe only the observed portion of the period.', 'wp-slimstat'); ?> <?php if (null === $rate) { esc_html_e('Purchase rate needs at least 100 eligible visits. This display threshold does not establish statistical significance.', 'wp-slimstat'); } ?></p></details>
		</section>
		<section class="ss-ec-actions"><div class="ss-ec-section-heading"><h2><?php esc_html_e('Your next investigation', 'wp-slimstat'); ?></h2><p><?php esc_html_e('What this period suggests checking, most important first.', 'wp-slimstat'); ?></p></div><ol>
			<?php if ($provisional) : ?><li><span class="ss-ec-action-label"><?php esc_html_e('Data quality', 'wp-slimstat'); ?></span><strong><?php esc_html_e('Complete synchronization first', 'wp-slimstat'); ?></strong><p><?php echo esc_html(sprintf(/* translators: %s: number of orders processed. */ __('%s orders processed. Resolve the import status before comparing revenue drivers.', 'wp-slimstat'), number_format_i18n((int) ($state['imported'] ?? 0)))); ?></p><a href="#ss-ec-quality" class="noslimstat"><?php esc_html_e('Review synchronization', 'wp-slimstat'); ?></a></li><?php else : ?>
			<?php if ($orders && (int) $current['matched'] < $orders) : ?><li><span class="ss-ec-action-label"><?php esc_html_e('Attribution coverage', 'wp-slimstat'); ?></span><strong><?php echo esc_html(sprintf(/* translators: %s: number of orders without a linked visit. */ __('%s orders have no tracked visit', 'wp-slimstat'), number_format_i18n($orders - (int) $current['matched']))); ?></strong><p><?php esc_html_e('Their sales count, but traffic efficiency cannot be inferred. Review consent and excluded traffic before comparing acquisition performance.', 'wp-slimstat'); ?></p><a href="#ss-ec-quality" class="noslimstat"><?php esc_html_e('Inspect coverage', 'wp-slimstat'); ?></a></li><?php endif; ?>
			<?php if ((float) $current['refund'] > 0) : ?><li><span class="ss-ec-action-label"><?php esc_html_e('Refunds', 'wp-slimstat'); ?></span><strong><?php echo wp_kses_post($money($current['refund'])); ?> <?php esc_html_e('refunded on these orders', 'wp-slimstat'); ?></strong><p><?php esc_html_e('Includes refunded tax and shipping. Review product patterns before changing offers or messaging; amount-only refunds may be unallocated.', 'wp-slimstat'); ?></p><button type="button" class="ss-ec-quiet" data-open-report="product"><?php esc_html_e('Explore product contribution', 'wp-slimstat'); ?></button></li><?php endif; ?>
			<?php if ($journey['products'] >= 100 && $journey['products'] > $journey['completed']) : $counts = [$journey['products'], $journey['carts'], $journey['checkouts'], $journey['completed']]; $drops = [$counts[0] - $counts[1], $counts[1] - $counts[2], $counts[2] - $counts[3]]; $step = array_search(max($drops), $drops, true); $names = [__('product to cart', 'wp-slimstat'), __('cart to checkout', 'wp-slimstat'), __('checkout to order', 'wp-slimstat')]; ?><li><span class="ss-ec-action-label"><?php esc_html_e('Observed journey', 'wp-slimstat'); ?></span><strong><?php echo esc_html(sprintf(/* translators: %s: journey transition, such as product to cart. */ __('Inspect %s', 'wp-slimstat'), $names[$step])); ?></strong><p><?php echo esc_html(sprintf(/* translators: 1: visits without the next step, 2: visits at the current step. */ __('%1$s of %2$s observed visits did not reach the next step in this window. Test this path on your main devices; friction is a hypothesis, not a measured cause.', 'wp-slimstat'), number_format_i18n($drops[$step]), number_format_i18n($counts[$step]))); ?></p><a href="#ss-ec-journey" class="noslimstat"><?php esc_html_e('Review observed steps', 'wp-slimstat'); ?></a></li><?php elseif (!$orders) : ?><li><span class="ss-ec-action-label"><?php esc_html_e('First observations', 'wp-slimstat'); ?></span><strong><?php esc_html_e('Verify the first complete journey', 'wp-slimstat'); ?></strong><p><?php esc_html_e('Check a consented product-to-checkout visit, then confirm an included order appears after background synchronization. Pending and on-hold orders do not count as sales.', 'wp-slimstat'); ?></p><a href="<?php echo esc_url(admin_url('admin.php?page=slimconfig')); ?>"><?php esc_html_e('Review tracking settings', 'wp-slimstat'); ?></a></li><?php else : $leader = $data['groups']['channel'][0] ?? null; if ($leader) : ?><li><span class="ss-ec-action-label"><?php esc_html_e('Revenue contribution', 'wp-slimstat'); ?></span><strong><?php echo esc_html(Report::label('channel', $leader)); ?> · <?php echo wp_kses_post($money($leader['net'])); ?></strong><p><?php echo esc_html(sprintf(/* translators: %s: order count in the leading channel. */ __('%s included orders in the leading channel. Explore its sources before deciding what to test next; contribution alone does not measure efficiency.', 'wp-slimstat'), number_format_i18n((int) $leader['orders']))); ?></p><button type="button" class="ss-ec-quiet" data-open-report="source"><?php esc_html_e('Explore sources', 'wp-slimstat'); ?></button></li><?php endif; endif; ?>
			<?php endif; ?>
		</ol></section>
		<details id="ss-ec-definitions" class="ss-ec-definitions"><summary><?php esc_html_e('Definitions, coverage and reporting setup', 'wp-slimstat'); ?></summary><dl>
			<dt><?php esc_html_e('Revenue basis', 'wp-slimstat'); ?></dt><dd><?php esc_html_e('Net sales = discounted order total minus tax and shipping, minus recorded refunds excluding their explicitly refunded tax and shipping. Fees remain included. Refunds revise the original order period, even when refunded later. This is not cash flow or profit.', 'wp-slimstat'); ?></dd>
			<dt><?php esc_html_e('Included orders', 'wp-slimstat'); ?></dt><dd><?php esc_html_e('Processing, completed and refunded standard orders, grouped by creation date in the site timezone. Fully refunded and zero-value orders remain in the order count. Pending, on-hold, failed, cancelled, draft, trash and custom statuses are excluded. Processing does not guarantee gateway settlement.', 'wp-slimstat'); ?></dd>
			<dt><?php esc_html_e('Average order value', 'wp-slimstat'); ?></dt><dd><?php esc_html_e('Net sales divided by included orders. Discounts are already in the order total and are never subtracted twice.', 'wp-slimstat'); ?></dd>
			<dt><?php esc_html_e('Tracked purchase rate', 'wp-slimstat'); ?></dt><dd><?php esc_html_e('Distinct eligible visits associated with an included order created in this window, divided by eligible visits active in the same window. Multiple orders in one visit count once. Shared traffic filters apply to both. Visits have no currency: the numerator uses the selected order currency. This rate does not describe untracked shoppers.', 'wp-slimstat'); ?></dd>
			<dt><?php esc_html_e('Attribution and privacy', 'wp-slimstat'); ?></dt><dd><?php esc_html_e('Existing consented session cookies connect checkout to a retained SlimStat visit. No email/IP matching or cross-device inference. The session entry supplies traffic dimensions; existing WooCommerce order attribution is a fallback. Historical order imports cannot recreate visitor associations. Retention and privacy erasure remove those associations.', 'wp-slimstat'); ?></dd>
			<dt><?php esc_html_e('Freshness and comparisons', 'wp-slimstat'); ?></dt><dd><?php esc_html_e('Background jobs update orders and refunds. Reports cache for up to one minute and invalidate when synchronized. Comparisons use the immediately preceding equal-length window in the site calendar. Current periods end at the current time. Late refunds and status changes can revise previous periods.', 'wp-slimstat'); ?></dd>
		</dl><p><?php esc_html_e('Rebuild the reporting copy after a timezone change or an interrupted import. This does not alter orders or fabricate historical traffic.', 'wp-slimstat'); ?></p><?php $setup(__('Rebuild reports', 'wp-slimstat')); ?></details>
	<?php endif; ?>
</div>
