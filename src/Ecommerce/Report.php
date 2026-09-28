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
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; presentation layers escape or JSON-encode caught messages.
			throw new \RuntimeException(__('Choose a valid order currency.', 'wp-slimstat'));
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		foreach (array_keys(\wp_slimstat_db::$filters_normalized['columns'] ?? []) as $key) {
			if (false !== strpos($key, 'addon_') && self::CURRENCY_FILTER !== $key) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; presentation layers escape or JSON-encode caught messages.
				throw new \RuntimeException(__('This addon filter cannot be applied to Ecommerce. Remove it to load this report.', 'wp-slimstat'));
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		if (NetworkMerge::isMerging()) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; presentation layers escape or JSON-encode caught messages.
			throw new \RuntimeException(__('Ecommerce reports use one store at a time. Open the store dashboard to review its revenue.', 'wp-slimstat'));
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
		// Summary facts have exactly one row per order (item_id = 0). Only item
		// dimensions need distinct-order aggregation across multiple line items.
		$count = 0 === $kind ? 'COUNT(*)' : 'COUNT(DISTINCT e.order_id)';
		$matched = 0 === $kind ? 'COUNT(CASE WHEN s.id IS NOT NULL THEN 1 END)' : 'COUNT(DISTINCT CASE WHEN s.id IS NOT NULL THEN e.order_id END)';
		return $this->query("SELECT {$expression} dimension, MAX(e.label) label, SUM(e.net) net,
			{$count} orders, SUM(e.quantity) quantity, SUM(e.refund) refund, SUM(e.discount) discount,
			{$matched} matched
			FROM {$this->table} e {$this->join} WHERE {$where}
			GROUP BY {$expression} ORDER BY {$order} DESC, dimension LIMIT {$limit}");
	}

	/** Ordered observations only; checkout visits and successful cart changes are different events. */
	private function journey(): array
	{
		$db = Integration::db();
		$start = max((int) $this->range['start'], Integration::retentionStart());
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

	/** Calendar buckets in SlimStat wall time. Previous points cover equal elapsed windows. */
	private function series(string $interval, array $state, array $data): array
	{
		$start = (int) $this->range['start']; $end = (int) $this->range['end'];
		$span = $end - $start + 1;
		$weekStart = (int) get_option('start_of_week', 1);
		$shift = ((4 - $weekStart + 7) % 7) * DAY_IN_SECONDS;
		$bucket = static function (string $column, int $offset) use ($interval, $shift): string {
			$time = "({$column} + {$offset})";
			if ('monthly' === $interval) { return "EXTRACT(YEAR_MONTH FROM DATE_ADD('1970-01-01', INTERVAL {$time} SECOND))"; }
			return 'weekly' === $interval ? "FLOOR(({$time} + {$shift}) / 604800)" : "FLOOR({$time} / 86400)";
		};
		$bins = [];
		$cursor = $start;
		while ($cursor <= $end && count($bins) < 366) {
			$day = (int) floor($cursor / DAY_IN_SECONDS) * DAY_IN_SECONDS;
			if ('monthly' === $interval) {
				$next = (new \DateTimeImmutable(gmdate('Y-m-01', $cursor), new \DateTimeZone('UTC')))->modify('+1 month')->getTimestamp();
				$key = (int) gmdate('Ym', $cursor);
			} elseif ('weekly' === $interval) {
				$key = (int) floor(($cursor + $shift) / WEEK_IN_SECONDS);
				$next = ($key + 1) * WEEK_IN_SECONDS - $shift;
			} else { $key = (int) floor($cursor / DAY_IN_SECONDS); $next = $day + DAY_IN_SECONDS; }
			$bins[$key] = [$cursor, min($end, $next - 1), $cursor !== ('monthly' === $interval ? strtotime(gmdate('Y-m-01', $cursor) . ' UTC') : ('weekly' === $interval ? $key * WEEK_IN_SECONDS - $shift : $day)) || $next - 1 > $end];
			$cursor = $next;
		}
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; presentation layers escape or JSON-encode caught messages.
		if ($cursor <= $end) { throw new \RuntimeException(__('Choose a shorter period to explore Ecommerce trends (up to 366 monthly points).', 'wp-slimstat')); }
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		$result = ['interval' => $interval, 'current' => [], 'previous' => []];
		foreach (['current' => 0, 'previous' => $span] as $period => $offset) {
			$from = $start - $offset; $to = $end - $offset;
			$retainedFrom = max($from, Integration::retentionStart());
			$where = $this->where($from, $to);
			$orderBucket = $bucket('e.dt', $offset); $visitBucket = $bucket('p.dt', $offset);
			$money = count($bins) === 1 ? [array_key_first($bins) => $data[$period]] : array_column($this->query("SELECT {$orderBucket} bucket, SUM(e.net) net, COUNT(*) orders FROM {$this->table} e {$this->join} WHERE {$where} GROUP BY bucket"), null, 'bucket');
			// Distinct visits per bucket, not summed daily rates. Multiple purchases count once.
			$cohort = "SELECT {$visitBucket} bucket, p.visit_id FROM {$this->stats} p WHERE p.dt BETWEEN {$retainedFrom} AND {$to} AND p.visit_id > 0 AND p.browser_type IN (0,2) AND p.notes LIKE '%[ec:eligible]%' AND ({$this->scope}) GROUP BY bucket, p.visit_id";
			$purchasesWhere = $this->where($from, $to, 0, false);
			$purchases = "SELECT {$orderBucket} bucket, s.visit_id FROM {$this->table} e INNER JOIN {$this->stats} s ON s.id = e.stat_id WHERE {$purchasesWhere} GROUP BY bucket, s.visit_id";
			$visits = count($bins) === 1 && 'current' === $period ? [array_key_first($bins) => $data['journey']] : array_column($this->query("SELECT c.bucket, COUNT(*) visits, COUNT(b.visit_id) buyers FROM ({$cohort}) c LEFT JOIN ({$purchases}) b ON b.bucket = c.bucket AND b.visit_id = c.visit_id GROUP BY c.bucket"), null, 'bucket');
			foreach ($bins as $key => [$first, $last, $partial]) {
				$first -= $offset; $last -= $offset;
				$unavailable = $last < Integration::retentionStart();
				$net = $unavailable ? null : (float) ($money[$key]['net'] ?? 0);
				$orders = $unavailable ? null : (int) ($money[$key]['orders'] ?? 0);
				$count = (int) ($visits[$key]['visits'] ?? 0); $buyers = (int) ($visits[$key]['buyers'] ?? 0);
				$result[$period][] = [
					'start' => $first, 'end' => $last, 'label' => self::date('M j, Y H:i', $first) . ' – ' . self::date('M j, Y H:i', $last),
					'short' => self::date('M j', $first), 'net' => $net, 'orders' => $orders,
					'aov' => $orders ? $net / $orders : null, 'visits' => $count, 'buyers' => $buyers,
					'rate' => !$unavailable && $count >= 100 ? 100 * $buyers / $count : null,
					'partial' => $partial || $first < Integration::retentionStart(),
					'provisional' => empty($state['complete']) || !empty($state['error']),
				];
			}
		}
		return $result;
	}

	/** Validate freshness once for HTML, email and dimension-only downloads. */
	public function context(): array
	{
		$state = get_option(Integration::STATE, []);
		if (!Integration::ready()) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; presentation layers escape or JSON-encode caught messages.
			throw new \RuntimeException(__('Set up Ecommerce for the current analytics database before loading reports.', 'wp-slimstat'));
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if (!empty($state['needs_rebuild'])) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; presentation layers escape or JSON-encode caught messages.
			throw new \RuntimeException(__('SlimStat was deactivated and may have missed order changes. Rebuild Ecommerce reports to reconcile with WooCommerce.', 'wp-slimstat'));
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if (($state['timezone'] ?? '') !== wp_timezone_string()) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; presentation layers escape or JSON-encode caught messages.
			throw new \RuntimeException(__('The store timezone changed. Rebuild Ecommerce reports to align order dates with the date picker.', 'wp-slimstat'));
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ($this->range['start'] > $this->range['end']) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain exception diagnostics, not HTML output; presentation layers escape or JSON-encode caught messages.
			throw new \RuntimeException(__('This date range is in the future. Choose a period with observed activity.', 'wp-slimstat'));
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return ['currency' => $this->currency, 'range' => [(int) $this->range['start'], (int) $this->range['end']],
			'provisional' => empty($state['complete']) || !empty($state['error']),
			'retention_limited' => $this->range['start'] < Integration::retentionStart()];
	}

	/** Cached response; email omits interactive rankings/series that it does not render. */
	public function data(string $interval = 'auto', bool $details = true): array
	{
		$this->context();
		$state = get_option(Integration::STATE, []);
		$extra = array_values(array_intersect(['campaign', 'landing', 'device', 'customer', 'coupon'], (array) apply_filters('slimstat_ecommerce_dimensions', [])));
		$days = ($this->range['end'] - $this->range['start']) / DAY_IN_SECONDS;
		$interval = in_array($interval, ['daily', 'weekly', 'monthly'], true) ? $interval : ($days > 730 ? 'monthly' : ($days > 62 ? 'weekly' : 'daily'));
		// Bound response size even for a manually requested all-time range.
		if ($days > 366 && 'daily' === $interval) { $interval = 'weekly'; }
		if ($days > 2562) { $interval = 'monthly'; }
		$key = 'slimstat_ec_' . md5(wp_json_encode([
			$this->requestedRange, $this->currency, $this->scope, $extra, $this->acquisition, get_current_blog_id(), Acquisition::readinessKey(), $details, 3,
		]));
		$signature = [get_option('slimstat_ecommerce_generation', ''), $state, \wp_slimstat::$settings['auto_purge'] ?? 420];
		$cached = get_transient($key);
		if (is_array($cached) && ($cached['signature'] ?? []) === $signature) {
			$data = $cached['data'];
		} else {
			$start = (int) $this->range['start'];
			$end = (int) $this->range['end'];
			$span = $end - $start + 1;
			$data = [
				'currency' => $this->currency, 'range' => [$start, $end], 'previous_range' => [$start - $span, $start - 1],
				'current' => $this->summary($start, $end), 'previous' => $this->summary($start - $span, $start - 1),
				'filtered' => '1=1' !== $this->scope,
				'currencies' => $this->query("SELECT DISTINCT currency FROM {$this->table} WHERE kind = 0 AND currency <> '' ORDER BY currency"),
				'journey' => $this->journey(), 'groups' => [],
			];
			foreach ($details ? array_merge(['channel', 'product', 'source'], $extra) : [] as $dimension) {
				$data['groups'][$dimension] = $this->rows($dimension);
			}
			set_transient($key, ['signature' => $signature, 'data' => $data], MINUTE_IN_SECONDS);
		}
		if (!$details) { return $data; }
		// Interval changes reuse summaries/rankings and query only their own trend.
		$this->range['start'] = $data['range'][0]; $this->range['end'] = $data['range'][1];
		$seriesKey = $key . '_' . $interval . '_' . (int) get_option('start_of_week', 1);
		$cachedSeries = get_transient($seriesKey);
		if (is_array($cachedSeries) && ($cachedSeries['signature'] ?? []) === $signature && ($cachedSeries['range'] ?? []) === $data['range']) {
			$data['series'] = $cachedSeries['data'];
		} else {
			$data['series'] = $this->series($interval, $state, $data);
			set_transient($seriesKey, ['signature' => $signature, 'range' => $data['range'], 'data' => $data['series']], MINUTE_IN_SECONDS);
		}
		return $data;
	}

	/** Plain native metric/value rows. Email context is supplied only by the trusted scheduler. */
	public static function raw(array $args = []): array
	{
		if (empty($args['email']) && !self::canView()) { return []; }
		if (!Integration::available() || !Integration::ready()) {
			return [['metric' => __('Data quality', 'wp-slimstat'), 'value' => __('Ecommerce is unavailable. Activate WooCommerce and set up reporting.', 'wp-slimstat')]];
		}
		$columns = \wp_slimstat_db::$filters_normalized['columns'];
		// Native export's transport filters are not analytical segments.
		unset(\wp_slimstat_db::$filters_normalized['columns']['addon_e2e_id'], \wp_slimstat_db::$filters_normalized['columns']['addon_e2e_nonce']);
		try {
			$data = (new self())->data('auto', false);
			$state = get_option(Integration::STATE, []);
			$orders = (int) $data['current']['orders']; $journey = $data['journey'];
			$money = static function ($amount) use ($data) { return html_entity_decode(wp_strip_all_tags(self::money($amount, $data['currency'])), ENT_QUOTES, 'UTF-8'); };
			$quality = empty($state['complete']) || !empty($state['error']) ? __('Provisional: synchronization is incomplete or needs attention.', 'wp-slimstat') : __('WooCommerce totals; attribution covers retained tracked visits only.', 'wp-slimstat');
			if ($data['range'][0] < Integration::retentionStart()) { $quality .= ' ' . __('Part of this period is outside analytics retention.', 'wp-slimstat'); }
			$values = [
				__('Reporting period', 'wp-slimstat') => self::date('Y-m-d H:i', $data['range'][0]) . ' – ' . self::date('Y-m-d H:i', $data['range'][1]) . ' (' . wp_timezone_string() . ')',
				__('Currency', 'wp-slimstat') => $data['currency'], __('Data quality', 'wp-slimstat') => $quality,
				__('Net sales', 'wp-slimstat') => $money($data['current']['net']), __('Orders', 'wp-slimstat') => number_format_i18n($orders),
				__('Average order value', 'wp-slimstat') => $orders ? $money((float) $data['current']['net'] / $orders) : __('Unavailable', 'wp-slimstat'),
				__('Tracked purchase rate', 'wp-slimstat') => $journey['visits'] >= 100 ? number_format_i18n(100 * $journey['buyers'] / $journey['visits'], 2) . '%' : __('Insufficient observations', 'wp-slimstat'),
				__('Tracked buying / eligible visits', 'wp-slimstat') => $journey['buyers'] . ' / ' . $journey['visits'],
				__('Linked / included orders', 'wp-slimstat') => $data['current']['matched'] . ' / ' . $orders,
				__('Scope', 'wp-slimstat') => $data['filtered'] ? __('Selected traffic filters; unlinked orders excluded.', 'wp-slimstat') : __('All included orders in this currency and period.', 'wp-slimstat'),
				__('Basis', 'wp-slimstat') => __('Order-created cohort; refunds revise original orders; net sales exclude tax and shipping.', 'wp-slimstat'),
			];
			$rows = [];
			foreach ($values as $metric => $value) { $rows[] = compact('metric', 'value'); }
			return $rows;
		} catch (\Throwable $error) {
			return [['metric' => __('Data quality', 'wp-slimstat'), 'value' => __('Ecommerce could not be loaded. Review its reporting setup and selected filters.', 'wp-slimstat')]];
		} finally {
			\wp_slimstat_db::$filters_normalized['columns'] = $columns;
		}
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
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only aggregation selector; canView gates the report and the AJAX route checks its nonce.
				$interval = isset($_POST['ecommerce_interval']) && is_string($_POST['ecommerce_interval']) ? sanitize_key(wp_unslash($_POST['ecommerce_interval'])) : 'auto';
				$data = (new self())->data($interval);
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
