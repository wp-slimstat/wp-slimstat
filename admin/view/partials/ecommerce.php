<?php
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
		<div><p class="ss-ec-eyebrow"><?php esc_html_e('YOUR STORE, IN FOCUS', 'wp-slimstat'); ?></p><h1><?php esc_html_e('Ecommerce', 'wp-slimstat'); ?></h1><p><?php esc_html_e('See what earns revenue. Find the next thing to improve.', 'wp-slimstat'); ?></p></div>
		<a href="#" class="button refresh" aria-label="<?php esc_attr_e('Refresh Ecommerce reports', 'wp-slimstat'); ?>"><?php esc_html_e('Refresh', 'wp-slimstat'); ?></a>
	</div>
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
		$money = static function ($amount) use ($currency) { return wp_kses_post(Report::money($amount, $currency)); };
		$delta = static function ($value, $before) {
			if ((float) $before <= 0) { return __('No positive comparison baseline', 'wp-slimstat'); }
			$change = 100 * ((float) $value - (float) $before) / (float) $before;
			return ($change > 0 ? '+' : '') . number_format_i18n($change, 1) . '%';
		};
		?>
		<?php if (empty($state['complete']) || !empty($state['error'])) : ?>
			<div class="ss-ec-notice slimstat-notice" role="status"><strong><?php echo esc_html(!empty($state['error']) ? __('Some Ecommerce data could not be recorded or synchronized.', 'wp-slimstat') : __('Importing your orders…', 'wp-slimstat')); ?></strong> <?php esc_html_e('Figures are provisional until the import finishes. Refresh to check progress.', 'wp-slimstat'); ?> <?php echo esc_html(sprintf(__('%s orders processed.', 'wp-slimstat'), number_format_i18n((int) ($state['imported'] ?? 0)))); ?><?php if (!empty($state['failed_order'])) : ?> <span><?php echo esc_html(sprintf(__('Order %s could not be synchronized. Review it in WooCommerce and check database status, then rebuild. Other orders can continue importing.', 'wp-slimstat'), (string) (int) $state['failed_order'])); ?></span><?php endif; ?><?php if (!empty($state['error'])) { $setup(__('Retry import', 'wp-slimstat')); } ?></div>
		<?php endif; ?>
		<?php if ($data['filtered']) : ?><p class="ss-ec-notice"><?php esc_html_e('Traffic filters are active: commerce figures include only orders associated with retained visits matching these filters. Orders without a matching visit are excluded.', 'wp-slimstat'); ?></p><?php endif; ?>
		<?php if (Integration::retentionStart() > $data['previous_range'][0]) : ?><p class="ss-ec-notice"><?php esc_html_e('Part of this range or its comparison is outside your analytics retention window. Older commerce records are unavailable; comparisons may be incomplete.', 'wp-slimstat'); ?></p><?php endif; ?>
		<div class="ss-ec-toolbar">
			<span><?php esc_html_e('Net sales · order creation date', 'wp-slimstat'); ?></span>
			<details class="ss-ec-currency"><summary><?php echo esc_html($currency); ?> <span class="screen-reader-text"><?php esc_html_e('Choose currency', 'wp-slimstat'); ?></span></summary><div><?php foreach ($data['currencies'] as $row) : ?><a class="slimstat-filter-link" href="<?php echo esc_url(wp_slimstat_reports::fs_url(Report::CURRENCY_FILTER . ' equals ' . rawurlencode($row['currency']))); ?>" <?php if ($currency === $row['currency']) { echo 'aria-current="true"'; } ?>><?php echo esc_html($row['currency']); ?></a><?php endforeach; ?></div></details>
			<a href="#ss-ec-definitions"><?php esc_html_e('How these numbers work', 'wp-slimstat'); ?></a>
		</div>
		<section class="ss-ec-performance" aria-label="<?php esc_attr_e('Store performance', 'wp-slimstat'); ?>">
			<div class="ss-ec-kpis">
				<div class="ss-ec-kpi"><span><?php esc_html_e('Net sales', 'wp-slimstat'); ?></span><strong data-metric="net"><?php echo $money($current['net']); ?></strong><small><?php echo esc_html($delta($current['net'], $previous['net'])); ?></small></div>
				<div class="ss-ec-kpi"><span><?php esc_html_e('Orders', 'wp-slimstat'); ?></span><strong data-metric="orders"><?php echo esc_html(number_format_i18n($orders)); ?></strong><small><?php echo esc_html($delta($orders, $previous['orders'])); ?></small></div>
				<div class="ss-ec-kpi"><span><?php esc_html_e('Average order value', 'wp-slimstat'); ?></span><strong data-metric="aov"><?php echo $orders ? $money((float) $current['net'] / $orders) : '—'; ?></strong><small><?php echo esc_html($orders && (int) $previous['orders'] ? $delta((float) $current['net'] / $orders, (float) $previous['net'] / (int) $previous['orders']) : __('Needs orders in both periods', 'wp-slimstat')); ?></small></div>
				<div class="ss-ec-kpi"><span><?php esc_html_e('Tracked purchase rate', 'wp-slimstat'); ?></span><strong data-metric="rate"><?php echo null === $rate ? '—' : esc_html(number_format_i18n($rate, 2) . '%'); ?></strong><small><?php echo esc_html(sprintf(__('%1$s buying visits / %2$s eligible visits', 'wp-slimstat'), number_format_i18n($journey['buyers']), number_format_i18n($journey['visits']))); ?></small></div>
			</div>
			<p class="ss-ec-comparison"><?php echo esc_html(sprintf(__('Sales, orders and AOV compared with %1$s–%2$s. Purchase rate covers tracked visits only.', 'wp-slimstat'), Report::date('M j, Y H:i', $data['previous_range'][0]), Report::date('M j, Y H:i', $data['previous_range'][1]))); ?></p>
			<?php
			$bins = max(1, (int) ceil(($data['range'][1] - $data['range'][0] + 1) / $data['bucket']));
			$series = array_fill(0, $bins, ['net' => 0, 'orders' => 0]);
			foreach ($data['trend'] as $point) { $series[(int) $point['bucket']] = $point; }
			$values = array_map(static function ($point) { return (float) $point['net']; }, $series);
			$high = max(0, max($values)); $low = min(0, min($values));
			$scale = 180 / max(1, $high - $low); $zero = 20 + $high * $scale;
			$width = 960 / $bins; $barWidth = min(38, max(1, $width - 4));
			?>
			<figure class="ss-ec-chart"><figcaption><strong><?php esc_html_e('Revenue over time', 'wp-slimstat'); ?></strong><span><?php echo esc_html(sprintf(__('%1$s · %2$s-day intervals', 'wp-slimstat'), $currency, number_format_i18n($data['bucket'] / DAY_IN_SECONDS))); ?></span></figcaption>
				<svg viewBox="0 0 1000 220" role="img" aria-label="<?php esc_attr_e('Net sales over the selected period. Exact values are in the table below.', 'wp-slimstat'); ?>" preserveAspectRatio="none">
					<text x="20" y="12" class="ss-ec-axis"><?php echo esc_html(wp_strip_all_tags(Report::money($high, $currency))); ?></text>
					<line x1="20" y1="<?php echo esc_attr((string) $zero); ?>" x2="980" y2="<?php echo esc_attr((string) $zero); ?>" class="ss-ec-baseline"/>
					<?php foreach ($series as $index => $point) : $height = abs((float) $point['net']) * $scale; $negative = (float) $point['net'] < 0; ?>
						<rect x="<?php echo esc_attr((string) (20 + $index * $width + ($width - $barWidth) / 2)); ?>" y="<?php echo esc_attr((string) ($negative ? $zero : $zero - $height)); ?>" width="<?php echo esc_attr((string) $barWidth); ?>" height="<?php echo esc_attr((string) $height); ?>" rx="2" class="<?php echo $negative ? 'ss-ec-bar-negative' : 'ss-ec-bar'; ?>"><title><?php echo esc_html(Report::date('M j, Y', $data['range'][0] + $index * $data['bucket']) . ': ' . wp_strip_all_tags(Report::money($point['net'], $currency))); ?></title></rect>
					<?php endforeach; ?>
				</svg>
				<div class="ss-ec-chart-labels"><span><?php echo esc_html(Report::date('M j', $data['range'][0])); ?></span><span><?php echo esc_html(Report::date('M j', $data['range'][1])); ?></span></div>
				<details><summary><?php esc_html_e('View exact values', 'wp-slimstat'); ?></summary><div class="ss-ec-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e('Revenue trend table', 'wp-slimstat'); ?>"><table><thead><tr><th scope="col"><?php esc_html_e('Interval starts', 'wp-slimstat'); ?></th><th scope="col"><?php esc_html_e('Orders', 'wp-slimstat'); ?></th><th scope="col"><?php esc_html_e('Net sales', 'wp-slimstat'); ?></th></tr></thead><tbody><?php foreach ($series as $index => $point) : ?><tr><th scope="row"><?php echo esc_html(Report::date('M j, Y', $data['range'][0] + $index * $data['bucket'])); ?></th><td><?php echo esc_html(number_format_i18n((int) $point['orders'])); ?></td><td><?php echo $money($point['net']); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
			</figure>
		</section>
		<?php if (!$orders && !empty($state['complete'])) : ?><p class="ss-ec-notice"><?php esc_html_e('No included orders in this period and currency. Try another date range or currency, or review pending and on-hold orders in WooCommerce.', 'wp-slimstat'); ?></p><?php endif; ?>
		<div class="ss-ec-coverage"><span class="dashicons dashicons-chart-pie" aria-hidden="true"></span><div><strong><?php echo esc_html(null === $coverage ? __('No order coverage to measure yet', 'wp-slimstat') : sprintf(__('%1$s of %2$s orders linked to a tracked visit', 'wp-slimstat'), number_format_i18n((int) $current['matched']), number_format_i18n($orders))); ?></strong><p><?php esc_html_e('WooCommerce supplies sales totals. Consent, excluded traffic, older orders and deleted visits can leave gaps in traffic attribution.', 'wp-slimstat'); ?> <?php echo esc_html(sprintf(__('%s additional orders have WooCommerce acquisition data without a visit link.', 'wp-slimstat'), number_format_i18n((int) $current['wc_attributed']))); ?></p></div><?php if (null !== $coverage) : ?><span class="ss-ec-coverage-value"><?php echo esc_html(number_format_i18n($coverage, 0) . '%'); ?></span><?php endif; ?></div>
		<div class="ss-ec-section-heading"><h2><?php esc_html_e('What drives your revenue?', 'wp-slimstat'); ?></h2><p><?php esc_html_e('Ranked by net sales. Open a report for its full breakdown.', 'wp-slimstat'); ?></p></div>
		<div class="ss-ec-grid">
		<?php
		$titles = ['channel' => __('Channels', 'wp-slimstat'), 'source' => __('Sources', 'wp-slimstat'), 'product' => __('Products', 'wp-slimstat'), 'campaign' => __('Campaigns', 'wp-slimstat'), 'landing' => __('Landing pages', 'wp-slimstat'), 'device' => __('Devices', 'wp-slimstat'), 'customer' => __('Customer segments', 'wp-slimstat'), 'coupon' => __('Coupons', 'wp-slimstat')];
		foreach ($data['groups'] as $dimension => $rows) :
			$isCoupon = 'coupon' === $dimension;
			$productDetails = 'product' === $dimension && apply_filters('slimstat_ecommerce_product_details', false);
			$measure = $isCoupon ? 'discount' : 'net';
			$empty = $isCoupon ? __('No coupon use in this view', 'wp-slimstat') : ('product' === $dimension ? __('No product lines in this view', 'wp-slimstat') : __('No orders in this view', 'wp-slimstat'));
			$leader = $rows ? Report::label($dimension, $rows[0]) : $empty;
			?>
			<details class="ss-ec-report" data-dimension="<?php echo esc_attr($dimension); ?>" <?php if (in_array($dimension, ['channel', 'product'], true)) { echo 'open'; } ?>>
				<summary><span><strong><?php echo esc_html($titles[$dimension]); ?></strong><small><?php echo esc_html($leader); ?></small></span><span class="ss-ec-report-total"><?php echo $rows ? $money($rows[0][$measure]) : '—'; ?><span class="screen-reader-text"><?php esc_html_e('Leading result', 'wp-slimstat'); ?></span></span></summary>
				<div class="ss-ec-report-body">
				<?php if (!$rows) : ?><p><?php echo esc_html($empty); ?></p><?php else : ?>
				<div class="ss-ec-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr($titles[$dimension]); ?>"><table><thead><tr><th scope="col"><?php echo esc_html($titles[$dimension]); ?></th><th scope="col"><?php esc_html_e('Orders', 'wp-slimstat'); ?></th><?php if ('product' === $dimension) : ?><th scope="col"><?php esc_html_e('Net units', 'wp-slimstat'); ?></th><?php endif; ?><?php if ($productDetails) : ?><th scope="col"><?php esc_html_e('Item refunds', 'wp-slimstat'); ?></th><?php endif; ?><th scope="col"><?php echo esc_html($isCoupon ? __('Discounts', 'wp-slimstat') : __('Net sales', 'wp-slimstat')); ?></th></tr></thead><tbody>
				<?php $shown = 0; foreach ($rows as $row) : $shown += (float) $row[$measure]; ?>
					<tr><th scope="row"><?php
						$filterColumns = ['channel' => 'traffic_channel', 'source' => 'traffic_source', 'campaign' => 'utm_campaign'];
						if (isset($filterColumns[$dimension]) && (int) $row['matched'] > 0 && '' !== (string) $row['dimension']) : ?>
							<a class="slimstat-filter-link" href="<?php echo esc_url(wp_slimstat_reports::fs_url($filterColumns[$dimension] . ' equals ' . rawurlencode($row['dimension']))); ?>" title="<?php esc_attr_e('Filter to associated tracked visits. Unlinked orders will be excluded.', 'wp-slimstat'); ?>"><?php echo esc_html(Report::label($dimension, $row)); ?></a>
						<?php else : echo esc_html(Report::label($dimension, $row)); endif; ?></th><td><?php echo esc_html(number_format_i18n((int) $row['orders'])); ?></td><?php if ('product' === $dimension) : ?><td><?php echo esc_html(number_format_i18n((float) $row['quantity'], (float) $row['quantity'] == (int) $row['quantity'] ? 0 : 2)); ?></td><?php endif; ?><?php if ($productDetails) : ?><td><?php echo $money($row['refund']); ?></td><?php endif; ?><td><?php echo $money($row[$measure]); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
				<?php if (!$isCoupon) : $total = (float) ('product' === $dimension ? $current['product_net'] : $current['net']); if (abs($total - $shown) > 0.000001) : ?><p class="ss-ec-footnote"><?php echo esc_html__('Other results:', 'wp-slimstat'); ?> <?php echo $money($total - $shown); ?></p><?php endif; endif; ?>
				<?php endif; ?>
				<p class="ss-ec-footnote"><?php echo esc_html('product' === $dimension ? __('After item discounts and itemized refunds. Fees and amount-only refunds are unallocated adjustments.', 'wp-slimstat') : ($isCoupon ? __('Applied discounts, not incremental revenue. Orders using multiple coupons appear in multiple rows.', 'wp-slimstat') : ('customer' === $dimension ? __('Guest and registered-account orders; these are not new and returning customer counts.', 'wp-slimstat') : (in_array($dimension, ['landing', 'device'], true) ? __('Based only on retained SlimStat visit entries. Orders without matching traffic stay unattributed.', 'wp-slimstat') : __('Retained SlimStat visit attribution where available; otherwise recorded WooCommerce acquisition. Missing data stays unattributed.', 'wp-slimstat'))))); ?></p>
				<?php if ('product' === $dimension) : ?><p class="ss-ec-footnote"><?php esc_html_e('Unallocated adjustments:', 'wp-slimstat'); ?> <?php echo $money((float) $current['net'] - (float) $current['product_net']); ?></p><?php endif; ?>
				<?php do_action('slimstat_ecommerce_group_actions', $dimension, $data); ?>
				</div>
			</details>
		<?php endforeach; ?>
		</div>
		<section class="ss-ec-journey"><div class="ss-ec-section-heading"><h2><?php esc_html_e('Where does the journey stop?', 'wp-slimstat'); ?></h2><p><?php esc_html_e('Observed steps in the same tracked visit and reporting window.', 'wp-slimstat'); ?></p></div>
			<?php if ($data['range'][0] < ($state['started'] ?? PHP_INT_MAX) || !$journey['visits']) : ?><p class="ss-ec-notice"><?php esc_html_e('Journey tracking starts after setup and requires eligible tracked visits. Earlier funnels cannot be reconstructed from orders. Counts below cover only the observed portion of this period.', 'wp-slimstat'); ?></p><?php endif; ?>
			<ol class="ss-ec-steps"><?php foreach (['products' => __('Viewed a product', 'wp-slimstat'), 'carts' => __('Added to cart', 'wp-slimstat'), 'checkouts' => __('Reached checkout', 'wp-slimstat'), 'completed' => __('Placed an included order', 'wp-slimstat')] as $key => $label) : ?><li><span><?php echo esc_html($label); ?></span><strong><?php echo $journey['visits'] ? esc_html(number_format_i18n($journey[$key])) : '—'; ?></strong><?php if ($journey['visits']) : ?><meter min="0" max="<?php echo esc_attr((string) max(1, $journey['products'])); ?>" value="<?php echo esc_attr((string) $journey[$key]); ?>" aria-label="<?php echo esc_attr($label); ?>"></meter><?php endif; ?></li><?php endforeach; ?></ol>
			<p class="ss-ec-footnote"><?php if ($journey['visits']) { echo esc_html(sprintf(__('%s buying visits did not follow every observed step. Express checkout, missing events or a different journey can explain this; it is not proof of abandonment.', 'wp-slimstat'), number_format_i18n(max(0, $journey['buyers'] - $journey['completed'])))); } ?> <?php if (null === $rate) { echo esc_html__('Purchase rate needs at least 100 eligible visits. This display threshold does not establish statistical significance.', 'wp-slimstat'); } ?></p>
		</section>
		<section class="ss-ec-actions"><div class="ss-ec-section-heading"><h2><?php esc_html_e('Your next investigation', 'wp-slimstat'); ?></h2><p><?php esc_html_e('Observations from this period, with practical places to look next.', 'wp-slimstat'); ?></p></div><ol>
			<?php if (empty($state['complete']) || !empty($state['error'])) : ?><li><strong><?php echo esc_html(!empty($state['error']) ? __('Resolve the synchronization issue', 'wp-slimstat') : __('Finish synchronizing your orders', 'wp-slimstat')); ?></strong><p><?php esc_html_e('Revenue investigations need a complete reporting copy. Check the import status above before comparing results.', 'wp-slimstat'); ?></p></li><?php else : ?>
			<?php if ($orders && (int) $current['matched'] < $orders) : ?><li><strong><?php esc_html_e('Check the attribution gap', 'wp-slimstat'); ?></strong><p><?php echo esc_html(sprintf(__('%s orders have no retained visit association. Review consent settings and excluded traffic before comparing traffic efficiency.', 'wp-slimstat'), number_format_i18n($orders - (int) $current['matched']))); ?></p><a href="<?php echo esc_url(admin_url('admin.php?page=slimconfig')); ?>"><?php esc_html_e('Review tracking settings', 'wp-slimstat'); ?></a></li><?php endif; ?>
			<?php if ((float) $current['refund'] > 0) : ?><li><strong><?php esc_html_e('Review refunds on these orders', 'wp-slimstat'); ?></strong><p><?php echo $money($current['refund']); ?> <?php esc_html_e('has been refunded, including tax and shipping. Review the affected orders for patterns before changing product or checkout messaging.', 'wp-slimstat'); ?></p><a href="<?php echo esc_url(admin_url(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order')); ?>"><?php esc_html_e('Review orders and refunds', 'wp-slimstat'); ?></a></li><?php endif; ?>
			<?php if ($journey['products'] >= 100 && $journey['products'] > $journey['completed']) : $counts = [$journey['products'], $journey['carts'], $journey['checkouts'], $journey['completed']]; $drops = [$counts[0] - $counts[1], $counts[1] - $counts[2], $counts[2] - $counts[3]]; $step = array_search(max($drops), $drops, true); $names = [__('product to cart', 'wp-slimstat'), __('cart to checkout', 'wp-slimstat'), __('checkout to order', 'wp-slimstat')]; ?><li><strong><?php echo esc_html(sprintf(__('Inspect the %s step', 'wp-slimstat'), $names[$step])); ?></strong><p><?php echo esc_html(sprintf(__('%s observed visits reached this step without reaching the next one in this window. Test the journey on your main devices; friction is a hypothesis, not a measured cause.', 'wp-slimstat'), number_format_i18n($drops[$step]))); ?></p></li><?php elseif (!$orders) : ?><li><strong><?php esc_html_e('Verify the first complete journey', 'wp-slimstat'); ?></strong><p><?php esc_html_e('Check a consented product-to-checkout visit, then confirm an included order appears after background synchronization. Pending and on-hold orders do not count as sales.', 'wp-slimstat'); ?></p></li><?php else : ?><li><strong><?php esc_html_e('Start with your leading revenue source', 'wp-slimstat'); ?></strong><p><?php esc_html_e('Open Channels and Products above. Check which offers and entry pages accompany the largest sales contribution before deciding what to test next.', 'wp-slimstat'); ?></p></li><?php endif; ?>
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
