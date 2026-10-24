<?php
/**
 * Heatmap legacy layer against a raw oracle, on the site's real corpus. Read-only.
 *
 * 1. pageKeySql() and pageKey() agree on every distinct stored resource.
 * 2. For the busiest pages and every device, legacyPoints() totals equal a raw count
 *    of valid, non-0,0 events computed in PHP from unfiltered rows (plan §12.1).
 * 3. Date coverage: the oracle's min/max dt equal the SQL range (plan §12.2).
 * 4. The old starts_with + string-width model disagrees with the oracle wherever the
 *    corpus has the D2/D4 shapes, so the assertion can fail (PITFALLS: prove the guard).
 *
 * Run: wp eval-file tests/heatmap-legacy-oracle-wp-test.php
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
if (!class_exists('SlimStat\Heatmap\Query')) { fwrite(STDERR, "Load WordPress and the candidate Free plugin with wp eval-file.\n"); exit(2); }

use SlimStat\Heatmap\Query;

global $wpdb;
$db       = Query::db();
$stats    = $wpdb->prefix . 'slim_stats';
$events   = $wpdb->prefix . 'slim_events';
$failures = [];
$checks   = 0;

// 1. Normaliser parity.
$resources = $db->get_results("SELECT resource, " . Query::pageKeySql('resource') . " k FROM {$stats} WHERE resource IS NOT NULL GROUP BY resource LIMIT 200000", ARRAY_A);
foreach ($resources as $row) {
	++$checks;
	if (Query::pageKey((string) $row['resource']) !== (string) $row['k']) {
		$failures[] = sprintf('pageKey(%s) = %s but SQL says %s', $row['resource'], Query::pageKey((string) $row['resource']), $row['k']);
	}
}
foreach (['/?p=12', '/?page_id=7&x=1', '/?s=p=1', '/about?x#y', '/blog/?p=3#c'] as $synthetic) {
	++$checks;
	$sql = $db->get_var('SELECT ' . Query::pageKeySql($db->prepare('%s', $synthetic)));
	if (Query::pageKey($synthetic) !== $sql) {
		$failures[] = sprintf('synthetic pageKey(%s) = %s but SQL says %s', $synthetic, Query::pageKey($synthetic), $sql);
	}
}

// 2 + 3. Raw oracle: every event with its pageview, classified in PHP.
$raw = $db->get_results("SELECT te.position, te.dt, t1.resource, t1.resolution FROM {$events} te INNER JOIN {$stats} t1 ON te.id = t1.id", ARRAY_A);
$oracle = [];
$wouldPaintOrigin = 0;
foreach ($raw as $row) {
	$position = (string) $row['position'];
	if (preg_match('/^0+,0+$/', $position)) { ++$wouldPaintOrigin; }
	if (!preg_match('/^\d{1,5},\d{1,5}$/', $position) || preg_match('/^0+,0+$/', $position)) { continue; }
	$device = Query::device((int) explode('x', (string) $row['resolution'])[0]);
	$page   = Query::pageKey((string) $row['resource']);
	if ('' === $device || '' === $page) { continue; }
	$cell = &$oracle[$page][$device];
	$cell['n']   = ($cell['n'] ?? 0) + 1;
	$cell['min'] = min($cell['min'] ?? PHP_INT_MAX, (int) $row['dt']);
	$cell['max'] = max($cell['max'] ?? 0, (int) $row['dt']);
	unset($cell);
}
uasort($oracle, static function ($a, $b) { return array_sum(array_column($b, 'n')) <=> array_sum(array_column($a, 'n')); });
$pages = array_slice(array_keys($oracle), 0, 15, true);
if (!$pages) { $failures[] = 'the corpus has no valid legacy clicks; nothing was compared'; }

$oldModelDiffers = 0;
foreach ($pages as $page) {
	foreach (array_keys(Query::DEVICES) as $device) {
		++$checks;
		$expected = $oracle[$page][$device]['n'] ?? 0;
		$actual   = array_sum(array_column(Query::legacyPoints((string) $page, $device), 'n'));
		if ($expected !== $actual) {
			$failures[] = sprintf('%s on %s: legacy layer %d, oracle %d', $page, $device, $actual, $expected);
		}
		if ($expected) {
			++$checks;
			$range = $db->get_row("SELECT MIN(te.dt) lo, MAX(te.dt) hi FROM {$events} te INNER JOIN {$stats} t1 ON te.id = t1.id WHERE " . Query::pageWhere((string) $page) . ' AND ' . Query::deviceWhere($device) . " AND te.position REGEXP '^[0-9]{1,5},[0-9]{1,5}$' AND te.position NOT REGEXP '^0+,0+$'", ARRAY_A);
			if ((int) $range['lo'] !== $oracle[$page][$device]['min'] || (int) $range['hi'] !== $oracle[$page][$device]['max']) {
				$failures[] = sprintf('%s on %s: date coverage %s..%s, oracle %d..%d', $page, $device, $range['lo'], $range['hi'], $oracle[$page][$device]['min'], $oracle[$page][$device]['max']);
			}
		}
		// The pre-v2 model: resource starts_with path, "resolution > min" as strings, 0,0 painted.
		[$low] = Query::DEVICES[$device];
		$old = (int) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$events} te INNER JOIN {$stats} t1 ON te.id = t1.id WHERE t1.resource LIKE %s AND t1.resolution > %s AND te.position LIKE '%%,%%'", $db->esc_like((string) $page) . '%', (string) ($low - 1)));
		$oldModelDiffers += (int) ($old !== $expected);
	}
}

// 4. The comparison can fail: on this corpus the old model gives different answers.
++$checks;
if ($pages && 0 === $oldModelDiffers && 0 === $wouldPaintOrigin) {
	$failures[] = 'the old starts_with/string-width model matched the oracle everywhere; this corpus cannot tell the models apart';
}

printf("SLIMSTAT-HEATMAP-LEGACY-ORACLE resources=%d pages=%d checks=%d old_model_differs=%d origin_rows=%d failures=%d\n", count($resources), count($pages), $checks, $oldModelDiffers, $wouldPaintOrigin, count($failures));
foreach ($failures as $failure) { echo '  FAIL ', $failure, "\n"; }
exit($failures ? 1 : 0);
