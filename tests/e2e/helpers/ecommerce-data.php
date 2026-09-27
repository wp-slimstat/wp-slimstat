<?php
/** Known WC/traffic data; deliberately refuses the real Local database. */
use SlimStat\Ecommerce\Integration;
use SlimStat\Ecommerce\Money;
use SlimStat\Ecommerce\Report;

if (!defined('DB_NAME') || 'tests-wordpress' !== DB_NAME) {
	throw new RuntimeException('Ecommerce fixtures require a disposable test database.');
}
$mode = $fixture_mode ?? ($args[0] ?? 'verify');
$key = 'slimstat_ecommerce_test_fixture';
$fixture = get_option($key, []);
// No fixture may send store/customer notifications, including status transitions.
add_filter('pre_wp_mail', '__return_true');

if ('cleanup' === $mode) {
	foreach ($fixture['orders'] ?? [] as $id) {
		$order = wc_get_order($id);
		if ($order) { $order->delete(true); }
		Integration::sync($id);
	}
	foreach ($fixture['products'] ?? [] as $id) { wp_delete_post($id, true); }
	foreach ($fixture['stats'] ?? [] as $id) {
		Integration::db()->delete($GLOBALS['wpdb']->prefix . 'slim_stats', ['id' => $id]);
	}
	delete_option($key);
	Integration::invalidate();
	echo wp_json_encode(['cleaned' => true]);
	return;
}
if (!function_exists('wc_get_orders')) { throw new RuntimeException('Active WooCommerce is required.'); }

if ('seed' === $mode) {
	if ($fixture) { throw new RuntimeException('Clean up the previous Ecommerce fixture first.'); }
	Integration::setup();
	$state = get_option(Integration::STATE);
	$state['complete'] = true;
	update_option(Integration::STATE, $state, false);
	$start = (int) floor((wp_slimstat::now() - 10 * DAY_IN_SECONDS) / DAY_IN_SECONDS) * DAY_IN_SECONDS;
	$fixture = ['orders' => [], 'products' => [], 'stats' => [], 'start' => $start, 'end' => $start + DAY_IN_SECONDS - 1];
	update_option($key, $fixture, false);
	$product = new WC_Product_Simple();
	$product->set_name('Ecommerce fixture product'); $product->set_virtual(true); $product->set_regular_price('50'); $product->save();
	$fixture['products'][] = $product->get_id(); update_option($key, $fixture, false);
	$utc = static function ($wall) { return (new DateTimeImmutable(gmdate('Y-m-d H:i:s', $wall), wp_timezone()))->getTimestamp(); };
	$make = static function ($total, $status, $currency, $when) use (&$fixture, $key, $product, $utc) {
		$order = new WC_Order(); $order->set_status($status); $order->set_currency($currency);
		$order->set_date_created($utc($when)); $order->set_created_via('slimstat-ecommerce-fixture');
		$line = new WC_Order_Item_Product(); $line->set_product($product); $line->set_quantity(1); $line->set_subtotal($total); $line->set_total($total);
		$order->add_item($line); $order->set_total($total); $order->save();
		$fixture['orders'][] = $order->get_id(); update_option($key, $fixture, false);
		return $order;
	};
	$a = $make('114', 'processing', 'USD', $start + 120);
	$item = current($a->get_items()); $item->set_quantity(2); $item->set_subtotal('100'); $item->set_total('80'); $item->save();
	$a->set_discount_total('20'); $a->set_shipping_total('10'); $a->set_cart_tax('19'); $a->save();
	$refund = new WC_Order_Refund(); $refund->set_parent_id($a->get_id()); $refund->set_currency('USD'); $refund->set_amount('30');
	$refund->set_cart_tax('-5'); $refund->set_shipping_total('-5'); $refund->set_total('-30');
	$line = new WC_Order_Item_Product(); $line->set_product($product); $line->set_quantity(-1); $line->set_subtotal('-20'); $line->set_total('-20'); $line->add_meta_data('_refunded_item_id', $item->get_id()); $refund->add_item($line); $refund->save();
	$fixture['refund'] = $refund->get_id();
	$b = $make('50', 'completed', 'USD', $start + 180);
	$b->update_meta_data('_wc_order_attribution_source_type', 'utm'); $b->update_meta_data('_wc_order_attribution_utm_source', 'newsletter'); $b->update_meta_data('_wc_order_attribution_utm_medium', 'email'); $b->save();
	$c = $make('30', 'completed', 'USD', $start + 200);
	$full = new WC_Order_Refund(); $full->set_parent_id($c->get_id()); $full->set_currency('USD'); $full->set_amount('30'); $full->set_total('-30');
	$line = new WC_Order_Item_Product(); $line->set_product($product); $line->set_quantity(-1); $line->set_subtotal('-30'); $line->set_total('-30'); $line->add_meta_data('_refunded_item_id', current($c->get_items())->get_id()); $full->add_item($line); $full->save();
	$c->update_status('refunded');
	$make('999', 'cancelled', 'USD', $start + 220);
	$make('200', 'completed', 'EUR', $start + 240);
	$f = $make('0', 'processing', 'USD', $start + 260);
	$g = $make('25', 'on-hold', 'USD', $start + 280);
	$fixture['hold'] = $g->get_id();
	$make('20', 'completed', 'USD', $start - 1800);
	\SlimStat\Tracker\Acquisition::checkSchema();
	for ($i = 0; $i < 120; ++$i) {
		$stat = ['dt' => $start + 10, 'visit_id' => 1900000000 + $i, 'browser_type' => $i % 2 ? 2 : 0,
			'resource' => '/ecommerce-fixture/product-' . $i, 'content_type' => 'cpt:product', 'content_id' => $product->get_id(),
			'notes' => '[ec:eligible][ec:product]', 'traffic_channel' => 'paid_search', 'traffic_source' => 'google',
			'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'ecommerce-fixture', 'country' => 'us'];
		if (false === Integration::db()->insert($GLOBALS['wpdb']->prefix . 'slim_stats', $stat)) { throw new RuntimeException('Fixture pageview insert failed.'); }
		$fixture['stats'][] = (int) Integration::db()->insert_id;
	}
	$entry = $fixture['stats'][0];
	Integration::db()->insert($GLOBALS['wpdb']->prefix . 'slim_events', ['id' => $entry, 'dt' => $start + 20, 'notes' => '[ec:cart]', 'event_description' => 'ecommerce_add_to_cart']);
	Integration::db()->insert($GLOBALS['wpdb']->prefix . 'slim_stats', ['dt' => $start + 30, 'visit_id' => 1900000000, 'browser_type' => 0, 'resource' => '/ecommerce-fixture/checkout', 'notes' => '[ec:eligible][ec:checkout]', 'traffic_channel' => 'internal']);
	$fixture['stats'][] = (int) Integration::db()->insert_id;
	foreach ($fixture['orders'] as $id) { Integration::sync($id); }
	foreach ([$a->get_id(), $f->get_id()] as $id) {
		Integration::db()->update(Integration::table(), ['stat_id' => $entry], ['order_id' => $id]);
		Integration::sync($id); // Re-import must preserve the association.
	}
	$fixture['primary'] = $a->get_id();
	$fixture['zero'] = $f->get_id();
	update_option($key, $fixture, false); Integration::invalidate();
	echo wp_json_encode($fixture);
	return;
}
if (!$fixture) { throw new RuntimeException('Seed Ecommerce fixtures before verification.'); }
require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
wp_slimstat_db::init();
wp_slimstat_db::$filters_normalized['utime'] = ['start' => $fixture['start'], 'end' => $fixture['end']];
wp_slimstat_db::$filters_normalized['columns'] = [Report::CURRENCY_FILTER => ['equals', 'USD']];
$data = (new Report())->data();
if ('data' === $mode) { echo wp_json_encode($data); return; }
$checks = 0;
$same = static function ($expected, $actual, $label) use (&$checks) {
	if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual)); }
	++$checks;
};
$same('115.000000', $data['current']['net'], 'WC net sales after partial and full refunds');
$same('4', $data['current']['orders'], 'Included statuses, fully refunded and zero-value order');
$same('60.000000', $data['current']['refund'], 'Late refunds belong to original order cohort');
$same('20.000000', $data['current']['discount'], 'Discounts counted once');
$same('110.000000', $data['current']['product_net'], 'Item refund allocation');
$same('3.000000', $data['current']['quantity'], 'Net units');
$same('2', $data['current']['matched'], 'Association retained after repeated sync');
$same('20.000000', $data['previous']['net'], 'Previous equal window');
$same(['visits' => 120, 'products' => 120, 'carts' => 1, 'checkouts' => 1, 'buyers' => 1, 'completed' => 1], $data['journey'], 'Ordered journey and distinct converting visits');
$same(115000000, array_sum(array_map(static function ($row) { return Money::units($row['net']); }, $data['groups']['channel'])), 'Channel reconciliation');
wp_slimstat_db::$filters_normalized['columns']['utm_source'] = ['equals', 'google'];
$filtered = (new Report())->data();
$same('65.000000', $filtered['current']['net'], 'Shared UTM filter scopes orders');
$same('2', $filtered['current']['orders'], 'Unassociated orders excluded by traffic filter');
$same(120, $filtered['journey']['visits'], 'Same filter scopes traffic denominator');
wp_slimstat_db::$filters_normalized['columns'] = [Report::CURRENCY_FILTER => ['equals', 'EUR']];
$euros = (new Report())->data();
$same('200.000000', $euros['current']['net'], 'Currency isolation');
$same('1', $euros['current']['orders'], 'EUR order count');
$same(0, $euros['journey']['buyers'], 'Currency-scoped purchase numerator');

if ('safety' === $mode) {
	require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/index.php';
	$db = Integration::db(); $table = Integration::table(); $stats = $GLOBALS['wpdb']->prefix . 'slim_stats';
	wp_slimstat_db::$filters_normalized['columns'] = [Report::CURRENCY_FILTER => ['equals', 'USD']];
	$state = get_option(Integration::STATE); $settings = wp_slimstat::$settings;
	$cookie = $_COOKIE; $server = $_SERVER; $user = get_current_user_id();
	$hold = wc_get_order($fixture['hold']); $entry = $fixture['stats'][0];
	try {
		foreach (['admin' => true, 'e2e_author' => false, 'e2e_subscriber' => false] as $login => $allowed) {
			$account = get_user_by('login', $login); if (!$account) { throw new RuntimeException('Missing fixture role: ' . $login); }
			wp_set_current_user($account->ID);
			$same($allowed, Report::canView(), 'Revenue permission for ' . $login);
		}
		wp_set_current_user(0);
		$fallback = wc_get_order($fixture['orders'][1]);
		$fallback->update_meta_data('_wc_order_attribution_utm_source', '0');
		$fallback->update_meta_data('_wc_order_attribution_utm_campaign', 'literal %20');
		$projection = \SlimStat\Ecommerce\OrderData::rows($fallback)[0];
		$same('0', $projection['source'], 'WooCommerce fallback preserves zero-valued tags');
		$same('literal %20', $projection['campaign'], 'WooCommerce fallback preserves literal percent tags');
		wp_slimstat::$settings = array_merge($settings, ['tracking' => 'on', 'set_tracker_cookie' => 'on', 'gdpr_enabled' => 'off', 'ignore_ip' => '', 'ignore_browsers' => '', 'ignore_resources' => '']);
		$allowedSettings = wp_slimstat::$settings;
		$db->insert($stats, ['dt' => wp_slimstat::now(), 'visit_id' => 1900009999, 'browser_type' => 0, 'resource' => '/ecommerce-fixture/privacy', 'notes' => '[ec:eligible]']);
		$page = (int) $db->insert_id;
		$_COOKIE['slimstat_tracking_code'] = \SlimStat\Tracker\Utils::getValueWithChecksum(1900009999);
		$_SERVER['HTTP_REFERER'] = home_url('/ecommerce-fixture/privacy'); $_SERVER['REMOTE_ADDR'] = '198.51.100.31'; $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
		foreach (['tracking' => 'off', 'set_tracker_cookie' => 'off', 'ignore_resources' => '*privacy*', 'ignore_browsers' => '*Chrome*', 'ignore_ip' => '198.51.100.31'] as $setting => $value) {
			wp_slimstat::$settings[$setting] = $value;
			Integration::associate($hold); Integration::cart('', 0);
			$same(null, $db->get_var($db->prepare("SELECT stat_id FROM {$table} WHERE order_id = %d AND item_id = 0", $hold->get_id())), 'Association respects ' . $setting);
			$same('0', (string) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}slim_events WHERE id = %d", $page)), 'Cart event respects ' . $setting);
			wp_slimstat::$settings = $allowedSettings;
		}
		$humanAgent = $_SERVER['HTTP_USER_AGENT'];
		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
		Integration::associate($hold);
		$same(null, $db->get_var($db->prepare("SELECT stat_id FROM {$table} WHERE order_id = %d AND item_id = 0", $hold->get_id())), 'Changed bot agent cannot reuse a human visit');
		$_SERVER['HTTP_USER_AGENT'] = $humanAgent;
		$_SERVER['HTTP_X_PURPOSE'] = 'preview'; wp_slimstat::$settings['ignore_prefetch'] = 'on';
		Integration::associate($hold);
		$same(null, $db->get_var($db->prepare("SELECT stat_id FROM {$table} WHERE order_id = %d AND item_id = 0", $hold->get_id())), 'Preview request cannot reuse a human visit');
		unset($_SERVER['HTTP_X_PURPOSE']);
		add_filter('slimstat_can_track', '__return_false', 999);
		Integration::associate($hold);
		$same(null, $db->get_var($db->prepare("SELECT stat_id FROM {$table} WHERE order_id = %d AND item_id = 0", $hold->get_id())), 'Consent refusal blocks association');
		remove_filter('slimstat_can_track', '__return_false', 999);
		$_COOKIE['slimstat_tracking_code'] .= 'tampered'; Integration::associate($hold);
		$same(null, $db->get_var($db->prepare("SELECT stat_id FROM {$table} WHERE order_id = %d AND item_id = 0", $hold->get_id())), 'Unsigned cookie rejected');
		$_COOKIE['slimstat_tracking_code'] = \SlimStat\Tracker\Utils::getValueWithChecksum(1900009999);
		Integration::associate($hold); Integration::cart('', 0);
		$same((string) $page, $db->get_var($db->prepare("SELECT stat_id FROM {$table} WHERE order_id = %d AND item_id = 0", $hold->get_id())), 'Allowed consented association control');
		$same('1', (string) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}slim_events WHERE id = %d", $page)), 'Allowed successful cart control');
		do_action('woocommerce_privacy_before_remove_order_personal_data', $hold); Integration::sync($hold->get_id());
		$same(null, $db->get_var($db->prepare("SELECT stat_id FROM {$table} WHERE order_id = %d AND item_id = 0", $hold->get_id())), 'WC erasure hook survives reimport');
		$db->update($stats, ['traffic_source' => '', 'utm_campaign' => ''], ['id' => $entry]);
		$db->update($table, ['source' => 'fallback-must-not-mix', 'campaign' => 'fallback-must-not-mix'], ['order_id' => $fixture['primary']]);
		Integration::invalidate();
		$sources = (new Report())->rows('source');
		$same(false, in_array('fallback-must-not-mix', array_column($sources, 'dimension'), true), 'Attribution uses one provider per order');
		wp_slimstat_admin::deactivate();
		$rejected = false;
		try { (new Report())->data(); } catch (RuntimeException $e) { $rejected = strpos($e->getMessage(), 'Rebuild') !== false; }
		$same(true, $rejected, 'Reactivation refuses stale commerce until rebuilt');
		Integration::setup();
		$started = get_option(Integration::STATE)['started'];
		// A missed deletion is repaired through WC CRUD during a bounded rebuild.
		$db->insert($table, ['order_id' => 900001, 'item_id' => 0, 'dt' => $fixture['start'], 'currency' => 'USD', 'status' => 'completed', 'net' => '999']);
		Integration::setup();
		$pending = get_option(Integration::STATE); $pending['cursor'] = max($fixture['orders']); update_option(Integration::STATE, $pending, false);
		Integration::import();
		$same('0', (string) $db->get_var("SELECT COUNT(*) FROM {$table} WHERE order_id = 900001"), 'Rebuild removes missed deleted orders');
		$same($started, get_option(Integration::STATE)['started'], 'Repeated setup preserves tracking start');
		$same(true, get_option(Integration::STATE)['complete'], 'Bounded import reaches fixed ceiling');
		$unreadable = static function ($items, $order) use ($fixture) {
			if ($order->get_id() === $fixture['primary']) { throw new RuntimeException('Fixture unreadable item metadata'); }
			return $items;
		};
		Integration::setup();
		add_filter('woocommerce_order_get_items', $unreadable, 10, 2);
		$retention = wp_slimstat::$settings['auto_purge']; wp_slimstat::$settings['auto_purge'] = 1;
		try { $same([], \SlimStat\Ecommerce\OrderData::rows(wc_get_order($fixture['primary'])), 'Expired orders do not read unnecessary or corrupt item metadata'); }
		finally { wp_slimstat::$settings['auto_purge'] = $retention; }
		try { Integration::import(); } finally { remove_filter('woocommerce_order_get_items', $unreadable, 10); }
		$failed = get_option(Integration::STATE);
		$same($fixture['primary'], $failed['failed_order'] ?? 0, 'Unreadable order identified without customer data');
		$same(true, $failed['error'], 'Partial import never presented as reconciled totals');
		$same(true, $failed['complete'], 'Other orders still imported after one unreadable order');
		Integration::setup(); Integration::import();
		$same(false, get_option(Integration::STATE)['error'], 'Rebuild recovers after order read failure');

		// Database failures must be explicit, not a plausible all-zero dashboard.
		$db->query("RENAME TABLE {$table} TO {$table}_fixture_unavailable");
		try {
			Integration::invalidate(); $rejected = false;
			try { (new Report())->data(); } catch (RuntimeException $e) { $rejected = strpos($e->getMessage(), 'could not be loaded') !== false; }
			$same(true, $rejected, 'Query failure is unavailable data');
		} finally { $db->query("RENAME TABLE {$table}_fixture_unavailable TO {$table}"); }
		for ($i = 0; $i < 55; ++$i) {
			$order = new WC_Order(); $order->set_status('on-hold'); $order->set_total('0'); $order->set_created_via('slimstat-ecommerce-fixture'); $order->save();
			$fixture['orders'][] = $order->get_id(); update_option($key, $fixture, false);
		}
		Integration::setup(); Integration::import();
		$same(50, get_option(Integration::STATE)['imported'], 'One job processes at most fifty IDs');
		$same(false, get_option(Integration::STATE)['complete'], 'Large import retains resumable cursor');
		for ($i = 0; $i < 5 && !get_option(Integration::STATE)['complete']; ++$i) { Integration::import(); }
		$same(true, get_option(Integration::STATE)['complete'], 'Subsequent batches complete without growing offsets');
		$queueArgs = ['hook' => 'slimstat_ecommerce_sync', 'group' => Integration::GROUP, 'args' => [$fixture['primary']], 'status' => 'pending', 'per_page' => 1000];
		$queued = count(as_get_scheduled_actions($queueArgs, 'ids'));
		Integration::queue($fixture['primary']); Integration::queue($fixture['primary']);
		$same($queued + 2, count(as_get_scheduled_actions($queueArgs, 'ids')), 'Later order mutations always have a successor job');

	} finally {
		remove_filter('slimstat_can_track', '__return_false', 999);
		wp_slimstat::$settings = $settings; $_COOKIE = $cookie; $_SERVER = $server; wp_set_current_user($user);
		if (isset($page)) { $db->delete($stats, ['id' => $page]); }
		$db->delete($table, ['order_id' => 900001]);
		$db->update($stats, ['traffic_source' => 'google', 'utm_campaign' => 'ecommerce-fixture'], ['id' => $entry]);
		Integration::sync($fixture['primary']); update_option(Integration::STATE, $state, false); Integration::invalidate();
	}
}

if ('edges' === $mode) {
	wp_slimstat_db::$filters_normalized['columns'] = [Report::CURRENCY_FILTER => ['equals', 'USD']];
	$report = static function () { return (new Report())->data(); };
	$hold = wc_get_order($fixture['hold']);
	$hold->update_status('completed'); Integration::sync($hold->get_id());
	$same('140.000000', $report()['current']['net'], 'Status update invalidates warm cache');
	$hold->update_status('on-hold'); Integration::sync($hold->get_id());
	$same('115.000000', $report()['current']['net'], 'Excluded status removes revenue');
	$refund = wc_get_order($fixture['refund']);
	$refund->set_amount('35'); $refund->save(); Integration::sync($fixture['primary']);
	$same('110.000000', $report()['current']['net'], 'Refund update invalidates warm cache');
	$refund->set_amount('30'); $refund->save(); Integration::sync($fixture['primary']);
	$zero = wc_get_order($fixture['zero']);
	$original = $zero->get_date_created()->getTimestamp();
	$utc = static function ($wall) { return (new DateTimeImmutable(gmdate('Y-m-d H:i:s', $wall), wp_timezone()))->getTimestamp(); };
	$zero->set_date_created($utc($fixture['end'] + 1)); $zero->save(); Integration::sync($zero->get_id());
	$same('3', $report()['current']['orders'], 'End boundary excludes the next second');
	$zero->set_date_created($utc($fixture['end'])); $zero->save(); Integration::sync($zero->get_id());
	$same('4', $report()['current']['orders'], 'End boundary is inclusive');
	$zero->set_date_created($utc($fixture['start'])); $zero->save(); Integration::sync($zero->get_id());
	$same('4', $report()['current']['orders'], 'Start boundary is inclusive');
	$same(1, $report()['journey']['completed'], 'Earlier order does not hide later ordered completion in same visit');
	$zero->set_date_created($original); $zero->save(); Integration::sync($zero->get_id());
	wp_slimstat_db::$filters_normalized['columns']['country'] = ['equals', 'zz'];
	$same('0', $report()['current']['orders'], 'Country filter excludes orders and visits consistently');
	$same(0, $report()['journey']['visits'], 'Country filter denominator');
	wp_slimstat_db::$filters_normalized['columns'] = [Report::CURRENCY_FILTER => ['equals', 'USD']];
	$beforeQueries = Integration::db()->num_queries;
	$report(); $afterCold = Integration::db()->num_queries;
	$report();
	$same(0, Integration::db()->num_queries - $afterCold, 'Warm report uses cached response');
	$oldTimezone = get_option('timezone_string');
	update_option('timezone_string', 'America/New_York');
	try {
		$projection = \SlimStat\Ecommerce\OrderData::rows(wc_get_order($fixture['primary']))[0];
		$same(gmdate('Y-m-d H:i:s', $projection['dt']), wp_date('Y-m-d H:i:s', $projection['created_utc'], wp_timezone()), 'Order projection uses site wall time');
		$rejected = false;
		try { $report(); } catch (RuntimeException $e) { $rejected = strpos($e->getMessage(), 'timezone') !== false; }
		$same(true, $rejected, 'Timezone changes refuse mixed-date data until rebuild');
	} finally { update_option('timezone_string', $oldTimezone); }
	$db = Integration::db(); $table = Integration::table(); $stats = $GLOBALS['wpdb']->prefix . 'slim_stats';
	// A later identified pageview must identify the association even if entry had no email.
	$db->update($stats, ['email' => 'commerce-privacy@example.test'], ['id' => end($fixture['stats'])]);
	$same(2, count(Integration::exportPersonalData('commerce-privacy@example.test')['data']), 'Privacy export follows retained visit association');
	$same(true, Integration::erase('commerce-privacy@example.test')['items_removed'], 'Privacy eraser removes association before parent');
	Integration::sync($fixture['primary']);
	$same('0', $report()['current']['matched'], 'Erasure cannot be undone by a later synchronization');
	$db->update($table, ['stat_id' => $fixture['stats'][0]], ['order_id' => $fixture['zero']]);
	$db->delete($stats, ['id' => $fixture['stats'][0]]);
	$same('0', (string) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND stat_id IS NOT NULL", $fixture['zero'])), 'Pageview deletion clears associations atomically via FK');
	$oldRetention = wp_slimstat::$settings['auto_purge'];
	wp_slimstat::$settings['auto_purge'] = 1;
	$same('0', $report()['current']['orders'], 'Reports exclude expired data even before background cleanup');
	wp_slimstat::$settings['auto_purge'] = $oldRetention;
	$refund->delete(true); Integration::sync($fixture['primary']);
	$same('135.000000', $report()['current']['net'], 'Deleting a refund restores order net sales');
	$hold->delete(true); Integration::sync($fixture['hold']);
	$same('0', (string) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $fixture['hold'])), 'Deleted orders leave no projection rows');
}
echo wp_json_encode(['checks' => $checks, 'storage' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy', 'result' => 'pass']);
