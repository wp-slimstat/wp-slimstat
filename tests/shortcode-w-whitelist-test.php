<?php
/** @license GPL-2.0-or-later */
// Run: php tests/shortcode-w-whitelist-test.php [mutated-source]
$path = $argv[1] ?? dirname(__DIR__) . '/src/Shortcodes/Shortcode.php';
if (!is_file($path)) { fwrite(STDERR, "Missing shortcode engine\n"); exit(1); }
$src = file_get_contents($path);
$errors = [];
if (!preg_match('/in_array\(\$w,\s*\[([^\]]*)\], true\)/s', $src, $match)) {
    $errors[] = 'Missing strict literal column allowlist';
} else {
    preg_match_all("/'([^']+)'/", $match[1], $tokens);
    foreach (['country', 'browser', 'platform', 'language', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'traffic_channel', 'traffic_source', 'slim_heatmap_01', 'slim_p10_01', 'slim_p8_01', 'slim_p8_02', 'users', 'pages', 'countries'] as $id) {
        if (!in_array($id, $tokens[1], true)) { $errors[] = 'Missing column: ' . $id; }
    }
    preg_match_all("/case '([a-z_]+)':/", $src, $cases);
    foreach ($cases[1] as $id) {
        if (!in_array($id, $tokens[1], true)) { $errors[] = 'Unreachable case: ' . $id; }
    }
}
if (!preg_match('/foreach \(\$columns as \$w\).*?self::allowed\(\$w\)/s', $src)) { $errors[] = 'Every split column must be guarded'; }
if (strpos($src, "explode(',',") === false || strpos($src, "'live'") === false) { $errors[] = 'Missing multi-column or live path'; }
if ($errors) { fwrite(STDERR, implode("\n", $errors) . "\n"); exit(1); }
echo "PASS: strict column boundary and shortcode coverage\n";
