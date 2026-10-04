<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Ecommerce;

use SlimStat\Migration\MigrationService;
use SlimStat\Schema\Schema;
use SlimStat\Tracker\Acquisition;
use SlimStat\Tracker\Processor;
use SlimStat\Tracker\Storage;
use SlimStat\Tracker\Utils;
use SlimStat\Utils\Consent;
use SlimStat\Utils\OptionClaim;

/** Optional WooCommerce lifecycle and bounded, rebuildable reporting projection. */
final class Integration
{
	public const VERSION = 1;
	public const GROUP = 'slimstat-ecommerce';
	public const STATE = 'slimstat_ecommerce_state';

	/** Register only when WooCommerce is loaded. No schema/query work on storefront boot. */
	public static function boot(): void
	{
		// Privacy and retention still work after WooCommerce is deactivated.
		if (self::ready()) {
			add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers'], 5);
			add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
			add_action('slimstat_ecommerce_maintenance', [self::class, 'maintenance']);
			add_action('wp_slimstat_purge', [self::class, 'maintenance'], 20);
		}
		if (!self::available()) {
			return;
		}
		add_action('before_woocommerce_init', static function () {
			if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SLIMSTAT_FILE, true);
			}
		});
		add_action('admin_post_slimstat_ecommerce_setup', [self::class, 'setupAction']);
		add_action('slimstat_ecommerce_sync', [self::class, 'sync']);
		add_action('slimstat_ecommerce_import', [self::class, 'import']);
		if (!self::ready()) {
			return;
		}
		foreach (['woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_new_order_refund', 'woocommerce_update_order_refund'] as $hook) {
			add_action($hook, [self::class, 'changed'], 20, 2);
		}
		add_action('woocommerce_order_refunded', [self::class, 'queue']);
		add_action('woocommerce_refund_deleted', static function ($refund, $order) { self::queue($order); }, 20, 2);
		add_action('woocommerce_before_delete_order', [self::class, 'beforeDelete'], 20, 2);
		// Legacy refund CRUD deletes a post before its WC deletion hook has the parent.
		add_action('before_delete_post', [self::class, 'legacyBeforeDelete']);
		add_action('woocommerce_delete_order', [self::class, 'queue']);
		add_action('woocommerce_trash_order', [self::class, 'queue']);
		add_action('woocommerce_untrash_order', [self::class, 'queue']);
		add_action('woocommerce_checkout_order_created', [self::class, 'associate']);
		add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'associate']);
		add_filter('slimstat_filter_pageview_stat', [self::class, 'markPageview'], 20);
		add_action('woocommerce_add_to_cart', [self::class, 'cart'], 20, 2);
		add_action('woocommerce_privacy_before_remove_order_personal_data', [self::class, 'eraseOrder']);
	}

	/** The analytics handle may be external; WC CRUD always resolves its own store. */
	public static function db(): \wpdb
	{
		return \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
	}

	public static function table(): string
	{
		return Schema::tableName('slim_ecommerce', $GLOBALS['wpdb']->prefix);
	}

	/**
	 * Figures are provisional only while the import runs. A failed order after it
	 * finished ($state['error']) is reported on its own; the totals stand.
	 */
	public static function provisional(array $state): bool
	{
		return empty($state['complete']);
	}

	public static function ready(): bool
	{
		$state = get_option(self::STATE, []);
		return self::VERSION === (int) ($state['version'] ?? 0)
			&& (!isset($state['database']) || $state['database'] === Acquisition::readinessKey());
	}

	/** HPOS, Blocks checkout and Action Scheduler APIs used by this integration. */
	public static function available(): bool
	{
		return PHP_INT_SIZE >= 8 && defined('WC_VERSION') && version_compare(WC_VERSION, '8.3', '>=')
			&& function_exists('wc_get_orders') && function_exists('as_enqueue_async_action')
			&& class_exists('\Automattic\WooCommerce\Utilities\OrderUtil');
	}

	/** Admin mutation; viewing traffic alone never grants access to store revenue. */
	public static function setupAction(): void
	{
		if ((!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) || !Report::canView()) {
			wp_die(esc_html__('You do not have permission to set up Ecommerce reports.', 'wp-slimstat'), '', ['response' => 403]);
		}
		check_admin_referer('slimstat_ecommerce_setup');
		try {
			self::setup();
		} catch (\Throwable $error) {
			self::failure();
		}
		wp_safe_redirect(admin_url('admin.php?page=slimview7'));
		exit;
	}

	/** Versioned, idempotent setup and resumable rebuild. Never changes WooCommerce data. */
	public static function setup(): void
	{
		if (!self::available() || MigrationService::migrationsDisabled()) {
			throw new \RuntimeException('Ecommerce setup is unavailable.');
		}
		$token = self::claim('slimstat_ec_import', 300);
		if (null === $token) { throw new \RuntimeException('Ecommerce setup/import is already running.'); }
		try {
			$db = self::db();
			$sql = Schema::createTableSql('slim_ecommerce', $GLOBALS['wpdb']->prefix, Schema::targetCollation($GLOBALS['wpdb']));
			if (false === $db->query($sql)) {
				throw new \RuntimeException('Ecommerce schema is unavailable.');
			}
			$columns = Schema::columnState($db, 'slim_ecommerce', $GLOBALS['wpdb']->prefix);
			if (!$columns['present'] || $columns['missing'] || $columns['narrow'] || $db->last_error) {
				throw new \RuntimeException('Ecommerce schema needs repair.');
			}
			$indexes = Schema::indexState($db, 'slim_ecommerce', $GLOBALS['wpdb']->prefix);
			if ($indexes['malformed'] || (!$indexes['present'] && !$indexes['missing'])) { throw new \RuntimeException('Ecommerce indexes need repair.'); }
			foreach ($indexes['missing'] as $name) {
				if (false === $db->query(Schema::createIndexSql('slim_ecommerce', $name, $GLOBALS['wpdb']->prefix) . ' ALGORITHM=INPLACE LOCK=NONE')) {
					throw new \RuntimeException('Ecommerce index is unavailable.');
				}
			}
			$stats = $GLOBALS['wpdb']->prefix . 'slim_stats';
			$index = $db->get_results("SHOW INDEX FROM {$stats} WHERE Key_name = 'idx_ecommerce_visit'", ARRAY_A);
			if (!is_array($index) || ($index && !Schema::indexMatches($index, 'visit_id, dt, id'))) {
				throw new \RuntimeException('Ecommerce visit index needs repair.');
			}
			if (!$index && false === $db->query(Schema::createIndexSql('slim_stats', 'idx_ecommerce_visit', $GLOBALS['wpdb']->prefix) . ' ALGORITHM=INPLACE LOCK=NONE')) {
				throw new \RuntimeException('Ecommerce visit index is unavailable.');
			}
			$state = get_option(self::STATE, []);
			$last = wc_get_orders(['type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'limit' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'ids']);
			$state = array_merge($state, [
				'version' => self::VERSION, 'started' => $state['started'] ?? \wp_slimstat::now(),
				'cursor' => 0, 'ceiling' => max((int) ($last[0] ?? 0), (int) $db->get_var('SELECT MAX(order_id) FROM ' . self::table())), 'imported' => 0,
				'complete' => false, 'error' => false, 'failed_order' => 0, 'needs_rebuild' => false, 'timezone' => wp_timezone_string(), 'database' => Acquisition::readinessKey(),
			]);
			update_option(self::STATE, $state, false);
			self::invalidate();
			as_enqueue_async_action('slimstat_ecommerce_import', [], self::GROUP, true);
			if (!wp_next_scheduled('slimstat_ecommerce_maintenance')) {
				wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'slimstat_ecommerce_maintenance');
			}
		} finally {
			OptionClaim::delete('slimstat_ec_import', $token);
		}
	}

	/** Read through WC APIs with a stable ID cursor, including stores with large ID gaps. */
	public static function import(): void
	{
		if (!self::ready() || !self::available()) {
			return;
		}
		$token = self::claim('slimstat_ec_import', 300);
		if (null === $token) {
			as_schedule_single_action(time() + 30, 'slimstat_ecommerce_import', [], self::GROUP);
			return;
		}
		$state = get_option(self::STATE, []);
		if (!empty($state['complete'])) {
			OptionClaim::delete('slimstat_ec_import', $token);
			return;
		}
		try {
			$upper = (int) $state['ceiling'];
			$ids = [];
			if ($upper > $state['cursor']) {
				$ids = self::orderIds((int) $state['cursor'], $upper);
				// Include projected IDs so a rebuild also removes orders deleted while
				// SlimStat was inactive. Both sides are bounded by the same keyset.
				$projected = self::db()->get_col(self::db()->prepare('SELECT DISTINCT order_id FROM ' . self::table() . ' WHERE order_id BETWEEN %d AND %d ORDER BY order_id LIMIT 50', (int) $state['cursor'] + 1, $upper));
				if (self::db()->last_error) { throw new \RuntimeException('Ecommerce reconciliation read failed.'); }
				$ids = array_unique(array_merge($ids, $projected));
				sort($ids, SORT_NUMERIC);
				$ids = array_slice($ids, 0, 50);
				foreach ($ids as $id) {
					try {
						self::sync((int) $id);
					} catch (\Throwable $error) {
						// One corrupt WC object must not block the rest of a store. Keep
						// a bounded recovery pointer and label all figures provisional.
						$state['failed_order'] = !empty($state['failed_order']) ? $state['failed_order'] : (int) $id;
					}
				}
			}
			$state['cursor'] = count($ids) === 50 ? (int) end($ids) : $upper;
			$state['imported'] += count($ids);
			$state['complete'] = $state['cursor'] >= $state['ceiling'];
			$state['error'] = !empty($state['failed_order']) || !empty(get_option(self::STATE, [])['error']);
			update_option(self::STATE, $state, false);
			if (!$state['complete']) {
				// A delayed successor avoids an unbounded loop in one background request.
				as_schedule_single_action(time() + 5, 'slimstat_ecommerce_import', [], self::GROUP);
			}
		} catch (\Throwable $error) {
			self::failure();
			throw $error;
		} finally {
			OptionClaim::delete('slimstat_ec_import', $token);
		}
	}

	/** WC documents custom query parameters separately for HPOS and its legacy store. */
	private static function orderIds(int $cursor, int $ceiling): array
	{
		$args = ['type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()),
			'limit' => 50, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids'];
		$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ($hpos) {
			$args['field_query'] = [['field' => 'id', 'value' => [$cursor + 1, $ceiling], 'compare' => 'BETWEEN']];
		}
		// Legacy wc_get_orders delegates to WP_Query. Scope its documented query
		// extension to our marker, and remove both filters immediately after the call.
		$translate = static function ($query, $vars) use ($cursor, $ceiling) {
			if (!empty($vars['slimstat_ecommerce_cursor'])) { $query['slimstat_ecommerce_cursor'] = [$cursor + 1, $ceiling]; }
			return $query;
		};
		$where = static function ($sql, $query) {
			$bounds = $query->get('slimstat_ecommerce_cursor');
			if (is_array($bounds) && 2 === count($bounds)) {
				$sql .= $GLOBALS['wpdb']->prepare(" AND {$GLOBALS['wpdb']->posts}.ID BETWEEN %d AND %d", $bounds[0], $bounds[1]);
			}
			return $sql;
		};
		if (!$hpos) {
			$args['slimstat_ecommerce_cursor'] = true;
			add_filter('woocommerce_order_data_store_cpt_get_orders_query', $translate, 10, 2);
			add_filter('posts_where', $where, 10, 2);
		}
		try {
			$ids = wc_get_orders($args);
			if ($GLOBALS['wpdb']->last_error) { throw new \RuntimeException('WooCommerce import read failed.'); }
			return $ids;
		} finally {
			if (!$hpos) {
				remove_filter('woocommerce_order_data_store_cpt_get_orders_query', $translate, 10);
				remove_filter('posts_where', $where, 10);
			}
		}
	}

	/** Only IDs enter the queue; never customer data or browser identifiers. */
	public static function queue($id): void
	{
		if (self::ready() && (int) $id > 0 && function_exists('as_enqueue_async_action')) {
			// A change during an in-progress sync needs a successor. Scheduler-level
			// uniqueness would drop it; atomic replacement makes duplicate jobs harmless.
			as_enqueue_async_action('slimstat_ecommerce_sync', [(int) $id], self::GROUP);
		}
	}

	public static function changed($id, $order = null): void
	{
		$order = $order ?: wc_get_order($id);
		if ($order) {
			self::queue('shop_order_refund' === $order->get_type() ? $order->get_parent_id() : $order->get_id());
		}
	}

	public static function beforeDelete($id, $order): void
	{
		self::changed($id, $order);
	}

	public static function legacyBeforeDelete($id): void
	{
		// WC's type lookup chooses the active storage; no direct postmeta/order SQL.
		if ('shop_order_refund' === \Automattic\WooCommerce\Utilities\OrderUtil::get_order_type($id)) {
			self::changed($id);
		}
	}

	/** Atomic replacement prevents half-imported products and duplicate sales. */
	public static function sync($id): void
	{
		if (!self::ready() || !self::available()) {
			return;
		}
		$id = (int) $id;
		$db = self::db();
		$table = self::table();
		$lock = 'slimstat_ec_order_' . $id;
		$token = self::claim($lock, 180);
		if (null === $token) {
			as_schedule_single_action(time() + 30, 'slimstat_ecommerce_sync', [$id], self::GROUP);
			return;
		}
		try {
			$order = wc_get_order($id);
			$rows = $order ? OrderData::rows($order) : [];
			if (false === $db->query('START TRANSACTION')) {
				throw new \RuntimeException('Ecommerce transaction unavailable.');
			}
			// The checkout capture upsert locks this same summary row, avoiding lost associations.
			$association = $db->get_row($db->prepare("SELECT stat_id, erased FROM {$table} WHERE order_id = %d AND item_id = 0 FOR UPDATE", $id), ARRAY_A);
			if ($db->last_error) {
				throw new \RuntimeException('Ecommerce association unavailable.');
			}
			if (false === $db->query($db->prepare("DELETE FROM {$table} WHERE order_id = %d", $id))) {
				throw new \RuntimeException('Ecommerce replacement failed.');
			}
			foreach ($rows as $row) {
				$row['stat_id'] = $association['stat_id'] ?? null;
				$row['erased'] = (int) ($association['erased'] ?? 0);
				if ($row['erased']) {
					$row['stat_id'] = null;
					$row['source'] = $row['channel'] = $row['campaign'] = '';
				}
				if (false === $db->insert($table, $row)) {
					throw new \RuntimeException('Ecommerce write failed.');
				}
			}
			if (false === $db->query('COMMIT')) {
				throw new \RuntimeException('Ecommerce commit failed.');
			}
			self::invalidate();
		} catch (\Throwable $error) {
			$db->query('ROLLBACK');
			self::failure();
			throw $error;
		} finally {
			OptionClaim::delete($lock, $token);
		}
	}

	/** Add stage markers after the tracker has applied consent and exclusions. */
	public static function markPageview(array $stat): array
	{
		if (!self::trackingAllowed() || empty($stat['visit_id']) || !in_array((int) ($stat['browser_type'] ?? 1), [0, 2], true)) {
			return $stat;
		}
		$stat['notes'] = isset($stat['notes']) && is_array($stat['notes']) ? $stat['notes'] : [];
		$stat['notes'][] = 'ec:eligible';
		if ('cpt:product' === ($stat['content_type'] ?? '')) {
			$stat['notes'][] = 'ec:product';
		}
		$endpoint = (string) get_option('woocommerce_checkout_order_received_endpoint', 'order-received');
		$query = [];
		wp_parse_str((string) wp_parse_url($stat['resource'] ?? '', PHP_URL_QUERY), $query);
		if ((int) ($stat['content_id'] ?? 0) > 0 && (int) $stat['content_id'] === (int) wc_get_page_id('checkout')
			&& !isset($query[$endpoint]) && false === strpos((string) ($stat['resource'] ?? ''), '/' . $endpoint . '/')) {
			$stat['notes'][] = 'ec:checkout';
		}
		return $stat;
	}

	/** Match only a verified retained visit; never guess from IP or email. */
	private static function trackedPage(): ?array
	{
		if (!self::trackingAllowed() || empty($_COOKIE['slimstat_tracking_code']) || !is_string($_COOKIE['slimstat_tracking_code'])) {
			return null;
		}
		$cookie = Utils::getValueWithoutChecksum(sanitize_text_field(wp_unslash($_COOKIE['slimstat_tracking_code'])));
		if (!is_string($cookie) || !ctype_digit($cookie) || (int) $cookie < 1) {
			return null;
		}
		$db = self::db();
		$table = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$now = \wp_slimstat::now();
		$row = $db->get_row($db->prepare(
			"SELECT id, visit_id, dt, resource, notes, content_type, referer, browser, platform, country FROM {$table} WHERE visit_id = %d AND dt BETWEEN %d AND %d AND browser_type IN (0,2) AND notes LIKE %s ORDER BY dt DESC, id DESC LIMIT 1",
			(int) $cookie, $now - max(1, (int) (\wp_slimstat::$settings['session_duration'] ?? 1800)), $now, '%[ec:eligible]%'
		), ARRAY_A);
		// A stale cookie from an earlier allowed page must not bypass an excluded checkout.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Type-checked below and compared only against URL/exclusion rules; never output, stored or interpolated into SQL.
		$ref = $_SERVER['HTTP_REFERER'] ?? '';
		$refPath = is_string($ref) ? wp_parse_url($ref, PHP_URL_PATH) : null;
		if (!$row || !is_string($refPath) || wp_parse_url($ref, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)
			|| wp_parse_url($row['resource'], PHP_URL_PATH) !== $refPath
			|| Utils::isBlacklisted($row['resource'], \wp_slimstat::$settings['ignore_resources'] ?? '')) {
			return null;
		}
		foreach (['referer' => 'ignore_referers', 'content_type' => 'ignore_content_types', 'browser' => 'ignore_browsers', 'platform' => 'ignore_platforms', 'country' => 'ignore_countries'] as $column => $setting) {
			if (Utils::isBlacklisted($row[$column] ?? '', \wp_slimstat::$settings[$setting] ?? '')) { return null; }
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Type-checked below and compared only against URL/exclusion rules; never output, stored or interpolated into SQL.
		$agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		if (!is_string($agent) || Utils::isBlacklisted($agent, \wp_slimstat::$settings['ignore_browsers'] ?? '')) { return null; }
		$browser = \SlimStat\Services\Browscap::apply_bot_safety_net(['user_agent' => $agent, 'browser_type' => 0]);
		if (1 === $browser['browser_type'] || \SlimStat\Tracker\Acquisition::aiAgent($agent)) { return null; }
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Type-checked below and compared only against URL/exclusion rules; never output, stored or interpolated into SQL.
		$prefetch = $_SERVER['HTTP_X_MOZ'] ?? ''; $purpose = $_SERVER['HTTP_X_PURPOSE'] ?? '';
		if (!is_string($prefetch) || !is_string($purpose)
			|| ('on' === (\wp_slimstat::$settings['ignore_prefetch'] ?? 'on')
				&& ('prefetch' === strtolower($prefetch) || 'preview' === strtolower($purpose)))) { return null; }
		[$ip, $otherIp] = Utils::getRemoteIp();
		if (Processor::isIpExcluded($ip, $otherIp)) { return null; }
		return $row;
	}

	private static function trackingAllowed(): bool
	{
		return 'on' === (\wp_slimstat::$settings['tracking'] ?? 'on')
			&& 'on' === (\wp_slimstat::$settings['set_tracker_cookie'] ?? 'off')
			&& !\wp_slimstat::$is_programmatic_tracking && !Processor::isUserExcluded()
			&& Consent::canTrack() && Consent::piiAllowed();
	}

	/** Constant-size association capture; money and product work stays in the background. */
	public static function associate($order): void
	{
		try {
			$page = self::trackedPage();
			if (!$page) {
				return;
			}
			$db = self::db();
			$table = self::table();
			$stats = $GLOBALS['wpdb']->prefix . 'slim_stats';
			$entry = (int) $db->get_var($db->prepare("SELECT id FROM {$stats} WHERE visit_id = %d AND dt <= %d ORDER BY dt, id LIMIT 1", $page['visit_id'], $page['dt']));
			if (!$entry) {
				return;
			}
			$written = $db->query($db->prepare(
				"INSERT INTO {$table} (order_id, item_id, stat_id) VALUES (%d, 0, %d) ON DUPLICATE KEY UPDATE stat_id = IF(stat_id IS NULL AND erased = 0, VALUES(stat_id), stat_id)",
				$order->get_id(), $entry
			));
			if (false === $written) {
				throw new \RuntimeException('Ecommerce association write failed.');
			}
			self::queue($order->get_id());
		} catch (\Throwable $error) {
			// Analytics must never interrupt checkout; expose failure in the report.
			self::failure();
		}
	}

	/** Successful WC cart action, including Blocks; no click-intent masquerading as success. */
	public static function cart($key, $product): void
	{
		try {
			$page = self::trackedPage();
			if ($page) {
				$result = Storage::insertRow([
					'id' => $page['id'], 'dt' => \wp_slimstat::now(), 'type' => 0,
					'event_description' => 'ecommerce_add_to_cart', 'notes' => '[ec:cart]',
				], $GLOBALS['wpdb']->prefix . 'slim_events');
				if (!$result->isStored()) {
					self::failure();
				}
			}
		} catch (\Throwable $error) {
			self::failure();
		}
	}

	public static function retentionStart(): int
	{
		$days = max(0, (int) (\wp_slimstat::$settings['auto_purge'] ?? 420));
		return $days ? \wp_slimstat::now() - $days * DAY_IN_SECONDS : 0;
	}

	/** Bounded retention; associations are never recovered from archived visitor records. */
	public static function maintenance(): void
	{
		if (!self::ready()) {
			return;
		}
		$db = self::db();
		$table = self::table();
		if (self::retentionStart()) {
			$deleted = $db->query($db->prepare("DELETE FROM {$table} WHERE dt > 0 AND dt < %d LIMIT 1000", self::retentionStart()));
			if (false === $deleted) { self::failure(); }
			if (1000 === $deleted) {
				// Drain large retention backlogs without an unbounded request.
				wp_schedule_single_event(time() + 10, 'slimstat_ecommerce_maintenance');
			}
		}
		self::invalidate();
	}

	/** Clear the association before WC removes identifiers. No customer data is mirrored. */
	public static function eraseOrder($order): void
	{
		if (false === self::db()->update(self::table(), ['stat_id' => null, 'erased' => 1, 'channel' => '', 'source' => '', 'campaign' => ''], ['order_id' => $order->get_id()])) {
			throw new \RuntimeException('Ecommerce privacy erasure failed.');
		}
		self::invalidate();
	}

	public static function exporters(array $exporters): array
	{
		$exporters['slimstat-ecommerce'] = [
			'exporter_friendly_name' => __('SlimStat Ecommerce associations', 'wp-slimstat'),
			'callback' => [self::class, 'exportPersonalData'],
		];
		return $exporters;
	}

	/** Only expose the association; WooCommerce's exporter owns customer/order details. */
	public static function exportPersonalData($email, $page = 1): array
	{
		$db = self::db();
		$table = self::table();
		$stats = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$rows = $db->get_results($db->prepare("SELECT DISTINCT e.order_id, e.stat_id FROM {$table} e INNER JOIN {$stats} s ON s.id = e.stat_id INNER JOIN {$stats} p ON p.visit_id = s.visit_id WHERE e.kind = 0 AND p.email = %s ORDER BY e.order_id LIMIT 100 OFFSET %d", $email, max(0, (int) $page - 1) * 100), ARRAY_A);
		if ($db->last_error) { throw new \RuntimeException('Ecommerce privacy export failed.'); }
		$data = [];
		foreach ($rows as $row) {
			$data[] = ['group_id' => 'slimstat-ecommerce', 'group_label' => __('SlimStat Ecommerce associations', 'wp-slimstat'), 'item_id' => 'slimstat-order-' . $row['order_id'], 'data' => [
				['name' => __('Order ID', 'wp-slimstat'), 'value' => $row['order_id']],
				['name' => __('Associated entry pageview ID', 'wp-slimstat'), 'value' => $row['stat_id']],
			]];
		}
		return ['data' => $data, 'done' => count($rows) < 100];
	}

	/** Remove associations before SlimStat erases their parent pageviews. */
	public static function erasers(array $erasers): array
	{
		return ['slimstat-ecommerce' => [
			'eraser_friendly_name' => __('SlimStat Ecommerce associations', 'wp-slimstat'),
			'callback' => [self::class, 'erase'],
		]] + $erasers;
	}

	public static function erase($email, $page = 1, string $column = 'email'): array
	{
		if (!in_array($column, ['email', 'ip'], true)) { throw new \InvalidArgumentException('Unknown privacy identifier.'); }
		$db = self::db();
		$table = self::table();
		$stats = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$ids = $db->get_col($db->prepare("SELECT DISTINCT e.order_id FROM {$table} e INNER JOIN {$stats} s ON s.id = e.stat_id INNER JOIN {$stats} p ON p.visit_id = s.visit_id WHERE p.{$column} = %s LIMIT 100", $email));
		if ($db->last_error) { throw new \RuntimeException('Ecommerce privacy erasure failed.'); }
		foreach ($ids as $id) {
			if (false === $db->update($table, ['stat_id' => null, 'erased' => 1, 'source' => '', 'channel' => '', 'campaign' => ''], ['order_id' => (int) $id])) {
				throw new \RuntimeException('Ecommerce privacy erasure failed.');
			}
		}
		self::invalidate();
		return ['items_removed' => (bool) $ids, 'items_retained' => false, 'messages' => [], 'done' => count($ids) < 100];
	}

	public static function invalidate(): void
	{
		update_option('slimstat_ecommerce_generation', sprintf('%.6F', microtime(true)), false);
	}

	/** Reuse the plugin's atomic option claims; expired workers cannot release a new claim. */
	private static function claim(string $name, int $seconds): ?string
	{
		$token = (string) (time() + $seconds);
		$old = get_option($name, null);
		$won = null === $old ? OptionClaim::insert($name, $token) : ((int) $old < time() && OptionClaim::compareAndSwap($name, (string) $old, $token));
		return $won ? $token : null;
	}

	private static function failure(): void
	{
		$state = get_option(self::STATE, []);
		$state['error'] = true;
		update_option(self::STATE, $state, false);
	}
}
