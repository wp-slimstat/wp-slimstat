<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Ecommerce;

use SlimStat\Tracker\Acquisition;
use SlimStat\Utils\NetworkMerge;

/** One metric/query contract for Free, Pro, HTML and exports. */
final class Report
{
	public const CURRENCY_FILTER = 'addon_ecommerce_currency';
	private array $range;
	private array $requestedRange;
	private string $currency;
	private string $scope;
	private string $join;
	private string $table;
	private string $stats;
	private bool $acquisition;

	/** Commerce permissions are additional to the native analytics permission. */
	public static function canView(): bool
	{
		return class_exists('wp_slimstat_admin') && \wp_slimstat_admin::can_view_stats()
			&& (current_user_can('view_woocommerce_reports') || current_user_can('manage_options'));
	}

	public function __construct()
	{
		$this->range = \wp_slimstat_db::$filters_normalized['utime'];
		$this->requestedRange = [(int) $this->range['start'], (int) $this->range['end']];
		if (abs((int) $this->range['end'] - \wp_slimstat::now()) < MINUTE_IN_SECONDS) {
			$this->requestedRange[1] = (int) ceil($this->range['end'] / MINUTE_IN_SECONDS) * MINUTE_IN_SECONDS;
		}
		$this->range['end'] = min((int) $this->range['end'], \wp_slimstat::now());
		$this->currency = (string) (\wp_slimstat_db::$filters_normalized['columns'][self::CURRENCY_FILTER][1] ?? get_woocommerce_currency());
		if (!preg_match('/\A[A-Z0-9]{3,8}\z/', $this->currency)
			|| 'equals' !== (\wp_slimstat_db::$filters_normalized['columns'][self::CURRENCY_FILTER][0] ?? 'equals')) {
			throw new \RuntimeException(__('Choose a valid order currency.', 'wp-slimstat'));
		}
		foreach (array_keys(\wp_slimstat_db::$filters_normalized['columns'] ?? []) as $key) {
			if (false !== strpos($key, 'addon_') && self::CURRENCY_FILTER !== $key) {
				throw new \RuntimeException(__('This addon filter cannot be applied to Ecommerce. Remove it to load this report.', 'wp-slimstat'));
			}
		}
		if (NetworkMerge::isMerging()) {
			throw new \RuntimeException(__('Ecommerce reports use one store at a time. Open the store dashboard to review its revenue.', 'wp-slimstat'));
		}
		$this->table = Integration::table();
		$this->stats = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$this->join = " LEFT JOIN {$this->stats} s ON s.id = e.stat_id ";
		$this->acquisition = '1' === get_option(Acquisition::readinessKey(), '0');
		$this->scope = \wp_slimstat_db::get_combined_where('', '', false, 'p');
	}

	/** Date/currency/status always scope money; traffic filters select associated visits. */
	private function where(int $start, int $end, ?int $kind = 0, bool $trafficScope = true): string
	{
		$db = Integration::db();
		$start = max($start, Integration::retentionStart());
		$where = $db->prepare("e.dt BETWEEN %d AND %d AND e.currency = %s AND e.status IN ('processing','completed','refunded')", $start, $end, $this->currency);
		if (null !== $kind) {
			$where .= $db->prepare(' AND e.kind = %d', $kind);
		} else {
			$where .= ' AND e.kind IN (0,1)';
		}
		if ($trafficScope && '1=1' !== $this->scope) {
			$where .= $db->prepare(" AND EXISTS (SELECT 1 FROM {$this->stats} p WHERE p.visit_id = s.visit_id AND p.dt BETWEEN %d AND %d AND ({$this->scope}))", $start, $end);
		}
		return $where;
	}

	/** Preserve database errors as unavailable data instead of turning them into zero. */
	private function query(string $sql): array
	{
		$db = Integration::db();
		$old = $db->suppress_errors(true);
		try {
			$result = $db->get_results($sql, ARRAY_A);
			if ('' !== (string) $db->last_error || !is_array($result)) {
				throw new \RuntimeException(__('Ecommerce data could not be loaded. Check reporting setup and the database connection, then retry.', 'wp-slimstat'));
			}
			return $result;
		} finally {
			$db->suppress_errors($old);
		}
	}

	/** The same per-order basis is used in every summary and dimension. */
	private function summary(int $start, int $end): array
	{
		$where = $this->where($start, $end, null);
		$rows = $this->query("SELECT
			COALESCE(SUM(CASE WHEN e.kind = 0 THEN e.net ELSE 0 END),0) net,
			COUNT(CASE WHEN e.kind = 0 THEN 1 END) orders,
			COUNT(CASE WHEN e.kind = 0 AND s.id IS NOT NULL THEN 1 END) matched,
			COUNT(CASE WHEN e.kind = 0 AND s.id IS NULL AND e.channel <> '' THEN 1 END) wc_attributed,
			COALESCE(SUM(CASE WHEN e.kind = 0 THEN e.refund ELSE 0 END),0) refund,
			COALESCE(SUM(CASE WHEN e.kind = 0 THEN e.discount ELSE 0 END),0) discount,
			COALESCE(SUM(CASE WHEN e.kind = 0 THEN e.tax ELSE 0 END),0) tax,
			COALESCE(SUM(CASE WHEN e.kind = 0 THEN e.shipping ELSE 0 END),0) shipping,
			COALESCE(SUM(CASE WHEN e.kind = 1 THEN e.net ELSE 0 END),0) product_net,
			COALESCE(SUM(CASE WHEN e.kind = 1 THEN e.quantity ELSE 0 END),0) quantity
			FROM {$this->table} e {$this->join} WHERE {$where}");
		return $rows[0];
	}

	/** Fixed whitelist of SQL expressions; addons select dimensions, never inject SQL. */
	private function dimensions(): array
	{
		$channel = $this->acquisition ? "NULLIF(s.traffic_channel,'')," : '';
		// Choose the provider once. A missing SlimStat campaign is not evidence
		// that a different WooCommerce campaign drove this same visit.
		$source = $this->acquisition ? "CASE WHEN s.traffic_channel <> '' THEN s.traffic_source ELSE e.source END" : 'e.source';
		$campaign = $this->acquisition ? "CASE WHEN s.traffic_channel <> '' THEN s.utm_campaign ELSE e.campaign END" : 'e.campaign';
		return [
			'channel' => ["COALESCE({$channel} NULLIF(e.channel,''),'unattributed')", 0],
			'source' => ["COALESCE({$source},'')", 0],
			'campaign' => ["COALESCE({$campaign},'')", 0],
			'landing' => ["COALESCE(SUBSTRING_INDEX(SUBSTRING_INDEX(s.resource,'?',1),'#',1),'')", 0],
			'device' => ["CASE WHEN s.id IS NULL THEN '' WHEN s.browser_type = 2 THEN 'mobile' WHEN s.browser_type = 0 THEN 'desktop' ELSE '' END", 0],
			'customer' => ["CASE WHEN e.customer = 1 THEN 'account' ELSE 'guest' END", 0],
			'product' => ['e.product_id', 1],
			'coupon' => ['e.label', 2],
		];
	}

	/** Bounded rankings; callers render the remaining value as Other, not a false total. */
	public function rows(string $dimension, int $limit = 10): array
	{
		$dimensions = $this->dimensions();
		if (!isset($dimensions[$dimension])) {
			throw new \InvalidArgumentException('Unknown Ecommerce dimension.');
		}
		[$expression, $kind] = $dimensions[$dimension];
		$limit = max(1, min(1000, $limit));
		$where = $this->where((int) $this->range['start'], (int) $this->range['end'], $kind);
		$order = 'coupon' === $dimension ? 'discount' : 'net';
		return $this->query("SELECT {$expression} dimension, MAX(e.label) label, SUM(e.net) net,
			COUNT(DISTINCT e.order_id) orders, SUM(e.quantity) quantity, SUM(e.refund) refund, SUM(e.discount) discount,
			COUNT(DISTINCT CASE WHEN s.id IS NOT NULL THEN e.order_id END) matched
			FROM {$this->table} e {$this->join} WHERE {$where}
			GROUP BY {$expression} ORDER BY {$order} DESC, dimension LIMIT {$limit}");
	}

	/** Ordered observations only; checkout visits and successful cart changes are different events. */
	private function journey(): array
	{
		$db = Integration::db();
		$start = (int) $this->range['start'];
		$end = (int) $this->range['end'];
		$events = $GLOBALS['wpdb']->prefix . 'slim_events';
		$cohort = $db->prepare("SELECT p.visit_id FROM {$this->stats} p WHERE p.dt BETWEEN %d AND %d AND p.visit_id > 0 AND p.browser_type IN (0,2) AND p.notes LIKE %s AND ({$this->scope}) GROUP BY p.visit_id", $start, $end, '%[ec:eligible]%');
		$products = "SELECT c.visit_id, (SELECT MIN(p.dt) FROM {$this->stats} p WHERE p.visit_id = c.visit_id AND p.dt BETWEEN {$start} AND {$end} AND p.notes LIKE '%[ec:product]%') product_at FROM ({$cohort}) c";
		$carts = "SELECT a.*, (SELECT MIN(v.dt) FROM {$this->stats} p INNER JOIN {$events} v ON v.id = p.id WHERE p.visit_id = a.visit_id AND v.dt BETWEEN a.product_at AND {$end} AND v.notes = '[ec:cart]') cart_at FROM ({$products}) a";
		$checkouts = "SELECT b.*, (SELECT MIN(p.dt) FROM {$this->stats} p WHERE p.visit_id = b.visit_id AND p.dt BETWEEN b.cart_at AND {$end} AND p.notes LIKE '%[ec:checkout]%') checkout_at FROM ({$carts}) b";
		// The eligible cohort already applies traffic filters. Aggregate purchases once;
		// repeating the same filtered EXISTS per visit becomes quadratic on MariaDB.
		$where = $this->where($start, $end, 0, false);
		$purchases = "SELECT s.visit_id, MAX(e.dt) last_purchase FROM {$this->table} e INNER JOIN {$this->stats} s ON s.id = e.stat_id WHERE {$where} GROUP BY s.visit_id";
		$result = $this->query("SELECT COUNT(*) visits, COUNT(product_at) products, COUNT(cart_at) carts, COUNT(checkout_at) checkouts,
			COUNT(p.last_purchase) buyers, COUNT(CASE WHEN p.last_purchase >= d.checkout_at THEN 1 END) completed
			FROM ({$checkouts}) d LEFT JOIN ({$purchases}) p ON p.visit_id = d.visit_id");
		return array_map('intval', $result[0]);
	}

	/** Cached complete response; scope includes edition, dates, filters, currency and mutations. */
	public function data(): array
	{
		$state = get_option(Integration::STATE, []);
		if (!empty($state['needs_rebuild'])) {
			throw new \RuntimeException(__('SlimStat was deactivated and may have missed order changes. Rebuild Ecommerce reports to reconcile with WooCommerce.', 'wp-slimstat'));
		}
		if (($state['timezone'] ?? '') !== wp_timezone_string()) {
			throw new \RuntimeException(__('The store timezone changed. Rebuild Ecommerce reports to align order dates with the date picker.', 'wp-slimstat'));
		}
		if ($this->range['start'] > $this->range['end']) {
			throw new \RuntimeException(__('This date range is in the future. Choose a period with observed activity.', 'wp-slimstat'));
		}
		$extra = array_values(array_intersect(['campaign', 'landing', 'device', 'customer', 'coupon'], (array) apply_filters('slimstat_ecommerce_dimensions', [])));
		$key = 'slimstat_ec_' . md5(wp_json_encode([
			$this->requestedRange, $this->currency, $this->scope, $extra, $this->acquisition, get_current_blog_id(),
		]));
		$signature = [get_option('slimstat_ecommerce_generation', ''), $state, \wp_slimstat::$settings['auto_purge'] ?? 420];
		$cached = get_transient($key);
		if (is_array($cached) && ($cached['signature'] ?? []) === $signature) {
			return $cached['data'];
		}
		$start = (int) $this->range['start'];
		$end = (int) $this->range['end'];
		$span = $end - $start + 1;
		$bucket = max(DAY_IN_SECONDS, (int) ceil($span / (62 * DAY_IN_SECONDS)) * DAY_IN_SECONDS);
		$where = $this->where($start, $end);
		$data = [
			'currency' => $this->currency, 'range' => [$start, $end], 'previous_range' => [$start - $span, $start - 1],
			'current' => $this->summary($start, $end), 'previous' => $this->summary($start - $span, $start - 1),
			'bucket' => $bucket, 'filtered' => '1=1' !== $this->scope,
			'currencies' => $this->query("SELECT DISTINCT currency FROM {$this->table} WHERE kind = 0 AND currency <> '' ORDER BY currency"),
			'trend' => $this->query("SELECT FLOOR((e.dt - {$start}) / {$bucket}) bucket, SUM(e.net) net, COUNT(*) orders FROM {$this->table} e {$this->join} WHERE {$where} GROUP BY bucket ORDER BY bucket"),
			'journey' => $this->journey(), 'groups' => [],
		];
		foreach (array_merge(['channel', 'product', 'source'], $extra) as $dimension) {
			$data['groups'][$dimension] = $this->rows($dimension);
		}
		set_transient($key, ['signature' => $signature, 'data' => $data], MINUTE_IN_SECONDS);
		return $data;
	}

	/** Native report callback; async loading, manual refresh and shared filters remain native. */
	public static function render(array $args = []): void
	{
		if (!self::canView()) {
			echo '<p>' . esc_html__('Store reporting permission is required to view Ecommerce.', 'wp-slimstat') . '</p>';
			return;
		}
		if ('on' === (\wp_slimstat::$settings['async_load'] ?? '') && !wp_doing_ajax() && empty($args['is_widget'])) {
			return;
		}
		$data = null;
		$error = '';
		$state = get_option(Integration::STATE, []);
		$available = Integration::available();
		if ($available && Integration::ready()) {
			try {
				$data = (new self())->data();
			} catch (\Throwable $exception) {
				$error = $exception instanceof \RuntimeException ? $exception->getMessage() : __('Ecommerce data could not be loaded. Check reporting setup and the database connection, then retry.', 'wp-slimstat');
			}
		}
		include dirname(__DIR__, 2) . '/admin/view/partials/ecommerce.php';
		if (wp_doing_ajax()) {
			wp_die();
		}
	}

	/** WC formatting deliberately follows the store's currency/decimal/separator settings. */
	public static function money($amount, string $currency): string
	{
		return wc_price($amount, ['currency' => $currency]);
	}

	/** Localized labels for SlimStat's already-local wall timestamps, without a second offset. */
	public static function date(string $format, int $timestamp): string
	{
		return wp_date($format, $timestamp, new \DateTimeZone('UTC'));
	}

	/** Shared human labels for the dashboard and Pro CSV. */
	public static function label(string $dimension, array $row): string
	{
		$value = (string) $row['dimension'];
		if ('channel' === $dimension) {
			return Acquisition::labels()[$value] ?? __('Unattributed', 'wp-slimstat');
		}
		if ('product' === $dimension) {
			return $row['label'] ?: __('Deleted product', 'wp-slimstat');
		}
		$labels = [
			'mobile' => __('Mobile', 'wp-slimstat'), 'desktop' => __('Desktop', 'wp-slimstat'),
			'tablet' => __('Tablet', 'wp-slimstat'), 'guest' => __('Guest checkout', 'wp-slimstat'),
			'account' => __('Registered account', 'wp-slimstat'),
		];
		return '' === $value ? __('Unattributed', 'wp-slimstat') : (in_array($dimension, ['device', 'customer'], true) ? ($labels[$value] ?? $value) : $value);
	}
}
