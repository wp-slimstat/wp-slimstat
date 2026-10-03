<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Heatmap;

/**
 * One read contract for heatmaps, shared by Free's page list and Pro's viewer.
 *
 * Legacy layer: link and button clicks SlimStat has always written to slim_events,
 * read in place. Pages match exactly (D4), devices by numeric viewport width (D2),
 * and the "0,0" no-coordinates default is never painted (D3).
 *
 * @since 6.1.0
 */
final class Query
{
	/** Viewport width bounds per device bucket, inclusive. */
	public const DEVICES = [
		'desktop' => [1024, 65535],
		'tablet'  => [768, 1023],
		'mobile'  => [1, 767],
	];

	/** slim_heatmap.device codes; 0 is an unknown width. */
	public const DEVICE_CODES = ['desktop' => 1, 'tablet' => 2, 'mobile' => 3];

	/** Upper bound on grouped rows returned to a viewer. */
	public const MAX_POINTS = 20000;

	/** Upper bound on rows in the Heatmaps page list. */
	public const MAX_PAGES = 500;

	/** A stored position a viewer may paint: digits,digits and not the 0,0 default. */
	private const VALID_POSITION_SQL = "te.position REGEXP '^[0-9]{1,5},[0-9]{1,5}$' AND te.position NOT REGEXP '^0+,0+$'";

	public static function db(): \wpdb
	{
		return \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
	}

	/** Device bucket for a viewport width; '' when the width is unknown. */
	public static function device(int $width): string
	{
		foreach (self::DEVICES as $device => [$low, $high]) {
			if ($width >= $low && $width <= $high) {
				return $device;
			}
		}
		return '';
	}

	/**
	 * The page a stored resource belongs to: the path without query or fragment,
	 * except plain permalinks (?p=12, ?page_id=7), which keep that first parameter.
	 * Mirrors pageKeySql() exactly; a test holds the two together.
	 */
	public static function pageKey(string $resource): string
	{
		$resource = explode('#', $resource, 2)[0];
		$query    = strpos($resource, '?');
		if (false === $query) {
			return $resource;
		}
		if (preg_match('/^(p|page_id)=[0-9]+/', (string) substr($resource, strrpos($resource, '?') + 1))) {
			return explode('&', $resource, 2)[0];
		}
		return substr($resource, 0, $query);
	}

	/**
	 * Page key for a browser URL path (location.pathname + search), stored the way the
	 * tracker stores `resource`.
	 */
	public static function pageKeyFromUrl(string $url): string
	{
		return self::pageKey(\SlimStat\Tracker\Processor::sanitizeResource($url));
	}

	/** SQL twin of pageKey() over a resource column. */
	public static function pageKeySql(string $column): string
	{
		return "CASE WHEN SUBSTRING_INDEX(SUBSTRING_INDEX({$column},'#',1),'?',-1) REGEXP '^(p|page_id)=[0-9]+'"
			. " AND LOCATE('?',{$column}) > 0"
			. " THEN SUBSTRING_INDEX(SUBSTRING_INDEX({$column},'#',1),'&',1)"
			. " ELSE SUBSTRING_INDEX(SUBSTRING_INDEX({$column},'#',1),'?',1) END";
	}

	/** Prepared WHERE selecting exactly the resources of one page key, index-friendly. */
	public static function pageWhere(string $pageKey, string $column = 't1.resource'): string
	{
		$db   = self::db();
		$like = $db->esc_like($pageKey);
		$next = false === strpos($pageKey, '?') ? '?' : '&';
		// The prefix range drives the resource index; the normaliser removes '/?p=12'
		// from '/' and '/about-us' from '/about'.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column is a fixed identifier from callers; values are prepared.
		return $db->prepare("({$column} = %s OR {$column} LIKE %s OR {$column} LIKE %s) AND " . self::pageKeySql($column) . ' = %s', $pageKey, $like . $next . '%', $like . '#%', $pageKey);
	}

	/** Prepared WHERE on the pageview's viewport width; '1=1' for all devices. */
	public static function deviceWhere(string $device, string $alias = 't1'): string
	{
		if (!isset(self::DEVICES[$device])) {
			return '1=1';
		}
		[$low, $high] = self::DEVICES[$device];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Alias is a fixed identifier from callers; bounds are prepared.
		return self::db()->prepare("CAST(SUBSTRING_INDEX({$alias}.resolution,'x',1) AS UNSIGNED) BETWEEN %d AND %d", $low, $high);
	}

	/**
	 * Legacy click points for one page and device.
	 *
	 * @param string $scope Prepared WHERE on slim_stats alias t1 (date range, filters), from the caller.
	 * @return array<int,array{x:int,y:int,vw:int,n:int,id:string,text:string}>
	 */
	public static function legacyPoints(string $pageKey, string $device, string $scope = '1=1'): array
	{
		$prefix = $GLOBALS['wpdb']->prefix;
		$rows   = self::rows("SELECT te.notes, te.position, CAST(SUBSTRING_INDEX(t1.resolution,'x',1) AS UNSIGNED) vw, COUNT(*) n
			FROM {$prefix}slim_events te INNER JOIN {$prefix}slim_stats t1 ON te.id = t1.id
			WHERE " . self::pageWhere($pageKey) . ' AND ' . self::deviceWhere($device) . ' AND ' . self::VALID_POSITION_SQL . self::legacyProbe() . " AND ({$scope})
			GROUP BY te.notes, te.position, vw ORDER BY n DESC LIMIT " . self::MAX_POINTS);

		$points = [];
		foreach ($rows as $row) {
			[$x, $y]  = array_map('intval', explode(',', (string) $row['position'], 2));
			$points[] = ['x' => $x, 'y' => $y, 'vw' => (int) $row['vw'], 'n' => (int) $row['n']] + self::element((string) $row['notes']);
		}
		return $points;
	}

	/**
	 * The Heatmaps page list, cached until heatmap data changes, retention changes or
	 * 12 hours pass. Legacy clicks keep arriving without a generation bump, so the page
	 * offers Refresh.
	 *
	 * @param int $start Inclusive dt bound, in the dt column's local-time seconds.
	 * @param int $end   Inclusive dt bound.
	 * @return array{rows:array<int,array<string,mixed>>,updated:int}
	 */
	public static function cachedPages(int $start, int $end, string $device = '', bool $refresh = false): array
	{
		$key       = 'slimstat_hm_pages_' . md5((string) wp_json_encode([get_current_blog_id(), $start, $end, $device]));
		$signature = [(string) get_option(Store::GENERATION, ''), \wp_slimstat::$settings['auto_purge'] ?? 0, Store::ready()];
		$cached    = $refresh ? null : get_transient($key);
		if (is_array($cached) && ($cached['signature'] ?? null) === $signature) {
			return $cached['data'];
		}
		$data = ['rows' => self::pages($start, $end, $device), 'updated' => time()];
		set_transient($key, ['signature' => $signature, 'data' => $data], 12 * HOUR_IN_SECONDS);
		return $data;
	}

	/**
	 * Pages with clicks in a range, sorted by clicks. Both layers are merged by page key,
	 * and each pageview counts in exactly one: once capture began, a pageview with heatmap
	 * rows is read from slim_heatmap only.
	 *
	 * @return array<int,array{page:string,clicks:int,desktop:int,tablet:int,mobile:int,dead:?int,rage:?int,scroll:?int,last:int,full:bool,pageviews:int,content_id:int}>
	 */
	public static function pages(int $start, int $end, string $device = ''): array
	{
		$db     = self::db();
		$prefix = $GLOBALS['wpdb']->prefix;
		$ready  = Store::ready();
		$probe  = self::legacyProbe();

		$pages = [];
		$add   = static function (string $page, array $row) use (&$pages): void {
			if ('' === $page) {
				return;
			}
			$entry = $pages[$page] ?? ['page' => $page, 'clicks' => 0, 'desktop' => 0, 'tablet' => 0, 'mobile' => 0, 'dead' => null, 'rage' => null, 'scroll' => null, 'last' => 0, 'full' => false, 'pageviews' => 0, 'content_id' => 0];
			foreach (['clicks', 'desktop', 'tablet', 'mobile'] as $count) {
				$entry[$count] += (int) $row[$count];
			}
			foreach (['dead', 'rage'] as $count) {
				if (isset($row[$count])) {
					$entry[$count] = (int) $entry[$count] + (int) $row[$count];
				}
			}
			$entry['last'] = max($entry['last'], (int) $row['last']);
			$pages[$page]  = $entry;
		};

		$legacy = self::rows($db->prepare('SELECT ' . self::pageKeySql('t1.resource') . ' page, COUNT(*) clicks, ' . self::deviceSplit() . ", MAX(te.dt) last
			FROM {$prefix}slim_events te INNER JOIN {$prefix}slim_stats t1 ON te.id = t1.id
			WHERE te.dt BETWEEN %d AND %d AND " . self::VALID_POSITION_SQL . ' AND ' . self::deviceWhere($device) . "{$probe}
			GROUP BY page ORDER BY clicks DESC LIMIT " . self::MAX_PAGES, $start, $end));
		foreach ($legacy as $row) {
			$add((string) $row['page'], $row);
		}

		if ($ready) {
			$table  = Store::table();
			$code   = self::DEVICE_CODES[$device] ?? 0;
			$on     = $code ? $db->prepare(' AND device = %d', $code) : '';
			$clicks = self::rows($db->prepare("SELECT HEX(page) page, MIN(id) id, COUNT(*) clicks, SUM(device = 1) desktop, SUM(device = 2) tablet, SUM(device = 3) mobile,
				SUM(flags & 2 > 0) dead, SUM(flags & 4 > 0) rage, MAX(dt) last
				FROM {$table} WHERE kind = 0 AND dt BETWEEN %d AND %d{$on} GROUP BY page ORDER BY clicks DESC LIMIT " . self::MAX_PAGES, $start, $end));
			$scroll = self::rows($db->prepare("SELECT HEX(page) page, ROUND(100 * AVG(LEAST(y / dh, 1))) depth
				FROM {$table} WHERE kind = 1 AND dh > 0 AND dt BETWEEN %d AND %d{$on} GROUP BY page", $start, $end));
			// Rows carry the page hash only; one sample pageview per page names it.
			$paths = [];
			if ($clicks) {
				$ids = implode(',', array_map('intval', array_column($clicks, 'id')));
				foreach (self::rows("SELECT id, resource FROM {$prefix}slim_stats WHERE id IN ({$ids})") as $row) {
					$paths[(int) $row['id']] = self::pageKey((string) $row['resource']);
				}
			}
			$byHash = [];
			foreach ($clicks as $row) {
				$page = $paths[(int) $row['id']] ?? '';
				$add($page, $row);
				$byHash[(string) $row['page']] = $page;
			}
			foreach ($scroll as $row) {
				$page = $byHash[(string) $row['page']] ?? '';
				if (isset($pages[$page])) {
					$pages[$page]['scroll'] = (int) $row['depth'];
					$pages[$page]['full']   = true;
				}
			}
		}

		uasort($pages, static function (array $a, array $b): int {
			return $b['clicks'] <=> $a['clicks'] ?: strcmp($a['page'], $b['page']);
		});
		$pages = array_slice($pages, 0, self::MAX_PAGES, true);
		if (!$pages) {
			return [];
		}

		// ponytail: one OR'd range per page over idx_goal_queries; chunk it if 500 ranges ever exceed the range optimizer's memory.
		$match = implode(' OR ', array_map(static function (string $page): string {
			return '(' . self::pageWhere($page) . ')';
		}, array_keys($pages)));
		$views = self::rows('SELECT ' . self::pageKeySql('t1.resource') . " page, COUNT(*) n, MAX(t1.content_id) content_id FROM {$prefix}slim_stats t1
			WHERE " . $db->prepare('t1.dt BETWEEN %d AND %d', $start, $end) . ' AND ' . self::deviceWhere($device) . " AND ({$match}) GROUP BY page");
		foreach ($views as $row) {
			if (isset($pages[$row['page']])) {
				$pages[$row['page']]['pageviews']  = (int) $row['n'];
				$pages[$row['page']]['content_id'] = (int) $row['content_id'];
			}
		}
		return array_values($pages);
	}

	/**
	 * Clicks on one page from slim_heatmap, grouped by element and a 20x20 grid over it.
	 * rx/ry come back as the grid cell's centre (0..10000 of the element box).
	 *
	 * @param int    $start Inclusive dt bound.
	 * @param int    $end   Inclusive dt bound.
	 * @param string $scope Prepared WHERE on slim_stats alias t1 (report filters); '1=1' skips the join.
	 * @return array<int,array{s:string,l:string,rx:int,ry:int,x:int,y:int,vw:int,n:int,d:int,r:int,f:int}>
	 */
	public static function clickBins(string $pageKey, string $device, int $start, int $end, string $scope = '1=1'): array
	{
		if (!Store::ready()) {
			return [];
		}
		$rows = self::rows('SELECT e.selector, e.label, b.* FROM (SELECT h.sel, h.rx DIV 500 bx, h.ry DIV 500 `by`, ROUND(AVG(h.x)) x, ROUND(AVG(h.y)) y, ROUND(AVG(h.vw)) vw,
			COUNT(*) n, SUM(h.flags & 2 > 0) dead, SUM(h.flags & 4 > 0) rage, SUM(h.seq = 0) first
			' . self::heatmapFrom($pageKey, $device, $start, $end, $scope) . ' AND h.kind = 0
			GROUP BY h.sel, bx, `by` ORDER BY n DESC LIMIT ' . self::MAX_POINTS . ') b LEFT JOIN ' . Store::table('slim_heatmap_elements') . ' e ON e.sel = b.sel');

		$bins = [];
		foreach ($rows as $row) {
			$bins[] = [
				's'  => (string) $row['selector'],
				'l'  => (string) $row['label'],
				'rx' => (int) $row['bx'] * 500 + 250,
				'ry' => (int) $row['by'] * 500 + 250,
				'x'  => (int) $row['x'],
				'y'  => (int) $row['y'],
				'vw' => (int) $row['vw'],
				'n'  => (int) $row['n'],
				'd'  => (int) $row['dead'],
				'r'  => (int) $row['rage'],
				'f'  => (int) $row['first'],
			];
		}
		return $bins;
	}

	/**
	 * How far down one page visitors scrolled: reach[p] is the percentage of pageviews that
	 * saw p% of the page, half the deepest point at least half of them saw, fold the average
	 * share visible without scrolling.
	 *
	 * @return array{views:int,reach:int[],half:int,fold:int}
	 */
	public static function scrollReach(string $pageKey, string $device, int $start, int $end, string $scope = '1=1'): array
	{
		$empty = ['views' => 0, 'reach' => [], 'half' => 0, 'fold' => 0];
		if (!Store::ready()) {
			return $empty;
		}
		$rows = self::rows('SELECT LEAST(100, FLOOR(100 * h.y / h.dh)) band, COUNT(*) n, 100 * AVG(LEAST(h.vh / h.dh, 1)) fold
			' . self::heatmapFrom($pageKey, $device, $start, $end, $scope) . ' AND h.kind = 1 AND h.dh > 0 GROUP BY band');

		$stopped = array_fill(0, 101, 0);
		$views   = 0;
		$fold    = 0.0;
		foreach ($rows as $row) {
			$stopped[max(0, min(100, (int) $row['band']))] += (int) $row['n'];
			$views += (int) $row['n'];
			$fold  += (float) $row['fold'] * (int) $row['n'];
		}
		if (!$views) {
			return $empty;
		}
		$reach = [];
		$left  = $views;
		$half  = 0;
		for ($p = 0; $p <= 100; $p++) {
			$reach[$p] = (int) round(100 * $left / $views);
			if (2 * $left >= $views) {
				$half = $p;
			}
			$left -= $stopped[$p];
		}
		return ['views' => $views, 'reach' => $reach, 'half' => $half, 'fold' => (int) round($fold / $views)];
	}

	/** Average scroll depth per device, for the device-gap insight. @return array<string,int> */
	public static function scrollByDevice(string $pageKey, int $start, int $end, string $scope = '1=1'): array
	{
		if (!Store::ready()) {
			return [];
		}
		$names = array_flip(self::DEVICE_CODES);
		$depth = [];
		foreach (self::rows('SELECT h.device, ROUND(100 * AVG(LEAST(h.y / h.dh, 1))) depth
			' . self::heatmapFrom($pageKey, '', $start, $end, $scope) . ' AND h.kind = 1 AND h.dh > 0 GROUP BY h.device') as $row) {
			if (isset($names[(int) $row['device']])) {
				$depth[$names[(int) $row['device']]] = (int) $row['depth'];
			}
		}
		return $depth;
	}

	/**
	 * Pageviews of one page in a range: per device, and how many came before full
	 * tracking began (those have link clicks only).
	 *
	 * @return array{views:int,desktop:int,tablet:int,mobile:int,older:int,content_id:int}
	 */
	public static function deviceViews(string $pageKey, int $start, int $end, string $scope = '1=1'): array
	{
		$db    = self::db();
		$since = Store::ready() ? (int) (get_option(Store::STATE, [])['since'] ?? 0) : PHP_INT_MAX;
		$row   = self::rows($db->prepare('SELECT COUNT(*) views, ' . self::deviceSplit() . ', SUM(t1.dt < %d) older, MAX(t1.content_id) content_id
			FROM ' . $GLOBALS['wpdb']->prefix . 'slim_stats t1
			WHERE t1.dt BETWEEN %d AND %d AND ', $since, $start, $end) . self::pageWhere($pageKey) . " AND ({$scope})")[0] ?? [];

		$views = [];
		foreach (['views', 'desktop', 'tablet', 'mobile', 'older', 'content_id'] as $key) {
			$views[$key] = (int) ($row[$key] ?? 0);
		}
		return $views;
	}

	/**
	 * What identifies the clicked element in a legacy note: its id, else its text.
	 * Notes are cut at 256 bytes, so a truncated JSON object still yields its id.
	 *
	 * @return array{id:string,text:string}
	 */
	public static function element(string $notes): array
	{
		$note = json_decode($notes, true);
		if (!is_array($note)) {
			$note = preg_match('/"id":"([^"\\\\]{1,128})"/', $notes, $match) ? ['id' => $match[1]] : [];
		}
		$id   = is_string($note['id'] ?? null) && preg_match('/\A[A-Za-z][\w\-:.]{0,127}\z/', $note['id']) ? $note['id'] : '';
		$text = is_string($note['text'] ?? null) ? trim(preg_replace('/\s+/', ' ', $note['text'])) : '';
		return ['id' => $id, 'text' => mb_substr($text, 0, 80)];
	}

	/**
	 * Each pageview is read by one layer: once capture began, a pageview with heatmap rows
	 * is not legacy. History before capture skips the probe; after it, it is a primary-key seek.
	 */
	private static function legacyProbe(): string
	{
		if (!Store::ready()) {
			return '';
		}
		return self::db()->prepare(' AND (te.dt < %d OR NOT EXISTS (SELECT 1 FROM ' . Store::table() . ' h WHERE h.id = te.id))', (int) (get_option(Store::STATE, [])['since'] ?? 0));
	}

	/** SUM columns counting t1 pageviews per device bucket. */
	private static function deviceSplit(): string
	{
		$width = "CAST(SUBSTRING_INDEX(t1.resolution,'x',1) AS UNSIGNED)";
		$split = [];
		foreach (self::DEVICES as $name => [$low, $high]) {
			$split[] = "SUM({$width} BETWEEN {$low} AND {$high}) {$name}";
		}
		return implode(', ', $split);
	}

	/** FROM/WHERE over slim_heatmap alias h for one page, device and range; joins t1 only for report filters. */
	private static function heatmapFrom(string $pageKey, string $device, int $start, int $end, string $scope): string
	{
		$db   = self::db();
		$sql  = 'FROM ' . Store::table() . ' h';
		$sql .= '1=1' === $scope ? '' : ' INNER JOIN ' . $GLOBALS['wpdb']->prefix . 'slim_stats t1 ON t1.id = h.id';
		$sql .= $db->prepare(' WHERE h.page = UNHEX(%s) AND h.dt BETWEEN %d AND %d', \SlimStat\Schema\SurrogateKey::hex($pageKey), $start, $end);
		$code = self::DEVICE_CODES[$device] ?? 0;
		$sql .= $code ? $db->prepare(' AND h.device = %d', $code) : '';
		return $sql . ('1=1' === $scope ? '' : " AND ({$scope})");
	}

	/** Database errors surface as errors, never as an empty heatmap. */
	private static function rows(string $sql): array
	{
		$db  = self::db();
		$old = $db->suppress_errors(true);
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Fragments are prepared by pageWhere()/deviceWhere() and the caller's scope; table names come from the prefix.
			$result = $db->get_results($sql, ARRAY_A);
			if ('' !== (string) $db->last_error || !is_array($result)) {
				throw new \RuntimeException(__('Heatmap data could not be loaded. Try a shorter date range, then retry.', 'wp-slimstat'));
			}
			return $result;
		} finally {
			$db->suppress_errors($old);
		}
	}
}
