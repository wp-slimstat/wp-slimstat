<?php
/**
 * Admin labels found untranslated by the 2026-10 pseudo-locale sweep must stay translated:
 * every shortcode display mode has a translated label, and the report rows print no
 * hardcoded English label or alt text.
 */
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/lib/source-scan.php';
$root     = __DIR__ . '/..';
$failures = [];

// The mode allowlist the shortcode accepts is the list the Playground can offer.
$shortcode = slimstat_blank_comments(file_get_contents($root . '/src/Shortcodes/Shortcode.php'));
$js        = file_get_contents($root . '/admin/assets/js/shortcodes.js');
if (!preg_match("/in_array\(\\\$atts\['f'\], \[([^\]]+)\], true\)/", $shortcode, $m)
    || !preg_match('/const modeLabels = \{([^}]+)\}/', $js, $labels)) {
    $failures[] = 'mode allowlist or modeLabels not found; this gate would be vacuous';
} else {
    foreach (array_map(static fn($mode) => trim($mode, " '"), explode(',', $m[1])) as $mode) {
        if (!preg_match("/(?:'" . preg_quote($mode, '/') . "'|\b" . preg_quote($mode, '/') . "\b): __\('/", $labels[1])) {
            $failures[] = "shortcode mode '$mode' has no translated label in modeLabels";
        }
    }
}

// Comments blanked, strings kept: the labels under test ARE string literals, and a comment
// quoting the fixed call must not satisfy a must-appear check.
$reports = slimstat_blank_comments(file_get_contents($root . '/admin/view/wp-slimstat-reports.php'));
if (false !== stripos($reports, "alt='Unknown'")) {
    $failures[] = "wp-slimstat-reports.php prints a hardcoded English alt text";
}
if (false !== strpos($reports, "'<br> IP: ")) {
    $failures[] = 'the access-log IP label is hardcoded English';
}

$right_now = slimstat_blank_comments(file_get_contents($root . '/admin/view/right-now.php'));
if (false === strpos($right_now, "esc_attr__('Server Latency and Page Speed in milliseconds'")) {
    $failures[] = 'the performance tooltip title is not escaped for its attribute';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "PASS: admin labels found by the pseudo-locale sweep stay translated\n";
