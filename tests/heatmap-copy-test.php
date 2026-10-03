<?php
/**
 * Source-level: heatmap copy speaks the visitor's language (plan §6a).
 *
 * No translatable heatmap string may say "segment", "legacy" or "capture", or use an em dash.
 * Those are the words the code uses; the UI says "Visitors", "Links & buttons" and "record".
 * Scoped to gettext literals, so comments and identifiers are free to use the code's words.
 */

declare(strict_types=1);

$root    = dirname(__DIR__);
$sources = [
	'admin/view/heatmaps.php',
	'admin/assets/js/heatmaps.js',
	'src/Heatmap/Store.php',
	'src/Heatmap/Query.php',
	'src/Controllers/Rest/HeatmapRestController.php',
];
$call    = '/\b(?:__|_e|_x|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)\(\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1(?:\s*,\s*([\'"])((?:(?!\3)[^\\\\]|\\\\.)*)\3)?/s';
$banned  = '/segment|legacy|capture|\x{2014}|&mdash;/iu';

/** @return string[] the gettext literals in a source (singular and plural). */
$strings = static function (string $source) use ($call): array {
	preg_match_all($call, $source, $m, PREG_SET_ORDER);
	$found = [];
	foreach ($m as $match) {
		$found[] = $match[2];
		if (isset($match[4]) && 'wp-slimstat' !== $match[4]) {
			$found[] = $match[4];
		}
	}
	return $found;
};

// The scan must be able to fail: a planted violation in each shape it checks.
$planted = $strings("__('Build a segment', 'wp-slimstat'); _n('%d click', '%d legacy clicks', \$n, 'wp-slimstat'); esc_html__(\"Start \u{2014} now\", 'wp-slimstat');");
if (3 !== count(preg_grep($banned, $planted))) {
	fwrite(STDERR, "FAIL heatmap-copy: the scan misses a planted violation\n");
	exit(1);
}

$failures = [];
$count    = 0;
foreach ($sources as $file) {
	$found  = $strings((string) file_get_contents($root . '/' . $file));
	$count += count($found);
	foreach (preg_grep($banned, $found) as $text) {
		$failures[] = "{$file}: \"{$text}\"";
	}
}
if ($count < 40) {
	$failures[] = "only {$count} strings found; the scan is not reading these files";
}
if ($failures) {
	fwrite(STDERR, "FAIL heatmap-copy:\n  " . implode("\n  ", $failures) . "\n");
	exit(1);
}
echo "OK heatmap-copy: {$count} strings in " . count($sources) . " files\n";
