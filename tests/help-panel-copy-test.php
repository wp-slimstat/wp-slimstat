<?php
/**
 * Source-level: the help panel (wp_slimstat_admin::contextual_help) stays true to v6.
 *
 * Every filter in wp_slimstat_db::$columns_names is explained, and the help explains no filter that
 * is gone. Labels are read from the filter list at runtime, so they cannot drift (the v4 panel said
 * "IP", "Language Code", "Visitor's Name"). Retired v4 instructions stay out, every wp-slimstat.com
 * link goes through the one helper that adds utm_medium=help, and purchase links need manage_options.
 */

declare(strict_types=1);

require __DIR__ . '/lib/source-scan.php';

$root = dirname(__DIR__);

/** @return string[] the filter keys a help body explains ('key' => __('What it is', …)). */
$described = static function (string $help): array {
	preg_match_all('/\'(\w+)\'\s*=>\s*__\(/', $help, $m);
	return $m[1];
};

/** @return string[] what is wrong with a help body, checked against the filter list. */
$check = static function (string $help, array $filters) use ($described): array {
	$explained = $described($help);
	$problems  = [];
	foreach (array_diff($filters, $explained) as $key) {
		$problems[] = "filter {$key} is not explained";
	}
	foreach (array_diff($explained, $filters) as $key) {
		$problems[] = "the help explains {$key}, which is not in the filter list";
	}
	if (preg_match_all('/Yahoo|MSN|cd=N|interval=-1|\[pre\]|msdn\.|php\.net|codex\.wordpress/i', $help, $r)) {
		$problems[] = 'retired v4 instructions: ' . implode(', ', array_unique($r[0]));
	}
	if (1 !== substr_count($help, 'https://wp-slimstat.com/') || false === strpos($help, 'utm_medium=help')) {
		$problems[] = 'wp-slimstat.com links must all go through the one helper that adds utm_medium=help';
	}
	if (false === strpos($help, "!current_user_can('manage_options') ? '' :")) {
		$problems[] = 'purchase links must be hidden from users without manage_options';
	}
	return $problems;
};

// The checks must be able to fail: one planted violation of each kind.
$planted = "'ip' => __('Address', 'wp-slimstat'),
	'serp' => __('Referer contains cd=N&', 'wp-slimstat'),
	'<a href=\"https://wp-slimstat.com/resources/\">' . '<a href=\"https://wp-slimstat.com/support/\">'";
if (5 !== count($check($planted, ['ip', 'browser']))) {
	fwrite(STDERR, "FAIL help-panel-copy: the checks miss a planted violation\n");
	exit(1);
}

$help  = slimstat_function_body(slimstat_blank_comments((string) file_get_contents($root . '/admin/index.php')), 'contextual_help');
$db    = slimstat_blank_comments((string) file_get_contents($root . '/admin/view/wp-slimstat-db.php'));
$list  = preg_match('/self::\$columns_names = \[(.*?)\n\s*\];/s', $db, $c) ? $c[1] : '';
preg_match_all('/\'(\w+)\'\s*=>\s*\[__\(([\'"])((?:(?!\2).)+)\2, \'wp-slimstat\'\), \'(?!none\')\w+\'\]/', $list, $f, PREG_SET_ORDER);
$filters = array_column($f, 1);

$failures = $check($help, $filters);
if (count($filters) < 35) {
	$failures[] = 'only ' . count($filters) . ' filters found; the scan is not reading $columns_names';
}
if ($failures) {
	fwrite(STDERR, "FAIL help-panel-copy:\n  " . implode("\n  ", $failures) . "\n");
	exit(1);
}
echo 'OK help-panel-copy: ' . count($filters) . " filters explained, no retired instructions, links tagged\n";
