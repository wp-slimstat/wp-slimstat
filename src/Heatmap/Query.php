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
	 * tracker stores `resource`: decoded, sanitised, non-ASCII bytes as lowercase %xx.
	 * Mirrors Tracker\Processor; a drift shows up as an empty overlay on non-ASCII URLs.
	 */
	public static function pageKeyFromUrl(string $url): string
	{
		$resource = preg_replace_callback('/[^\x20-\x7E]/', static function ($m) {
			return '%' . bin2hex($m[0]);
		}, sanitize_text_field(urldecode($url)));
		return self::pageKey((string) $resource);
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
			WHERE " . self::pageWhere($pageKey) . ' AND ' . self::deviceWhere($device) . ' AND ' . self::VALID_POSITION_SQL . " AND ({$scope})
			GROUP BY te.notes, te.position, vw ORDER BY n DESC LIMIT " . self::MAX_POINTS);

		$points = [];
		foreach ($rows as $row) {
			[$x, $y]  = array_map('intval', explode(',', (string) $row['position'], 2));
			$points[] = ['x' => $x, 'y' => $y, 'vw' => (int) $row['vw'], 'n' => (int) $row['n']] + self::element((string) $row['notes']);
		}
		return $points;
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
