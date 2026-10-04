<?php
/**
 * Source-level: translatable strings use the UI glossary's words, not the ones it retired.
 *
 * The 2026-10-04 screenshot audit (A1–A3, D1–D3) found one product under five names, the
 * same metric under three, and two voices: legacy help text full of "here below" and capitals
 * for emphasis, new modules full of em dashes. Each was fixed by hand across both plugins, and
 * nothing stopped the next string from bringing any of them back. The rules live in
 * jaan-to/docs/ui-glossary.md; this gate holds the "avoid" column of it.
 *
 * Scope: the first argument of a gettext call in free PHP and admin JS, and in Pro's src when
 * the Pro checkout sits beside this one. Comments, identifiers and option keys are not copy,
 * so "geoip" in a setting name or a comment is out of reach on purpose.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$roots       = [$plugin_root . '/wp-slimstat.php', $plugin_root . '/admin', $plugin_root . '/src', $plugin_root . '/views', $plugin_root . '/languages'];
if (is_dir($plugin_root . '/../wp-slimstat-pro/src')) {
    $roots[] = realpath($plugin_root . '/../wp-slimstat-pro/src');
}

// Retired term => what the glossary says to write instead.
$avoid = [
    '/\bPremium\b/'                 => 'Pro',
    '/\(pro\)/'                     => 'a "Pro" badge',
    '/Unlock SlimStat Pro/'         => 'Upgrade to Pro',
    '/\bGeoIP\b/'                   => 'geolocation database',
    '/\badd-on/i'                   => 'addon',
    '/\bhere (above|below)\b/'      => 'above / below',
    '/Javascript/'                  => 'JavaScript',
    '/So far so good/'              => 'No errors.',
    '/\blast day\b/'                => 'yesterday',
    '/Export to Excel/'             => 'Export to CSV',
    '/\bSlimstat\b|WP SlimStat/'    => 'SlimStat',
    '/Users live|Online users/i'    => 'Visitors online',
    '/\x{2014}/u'                   => 'a period, colon or comma (no em dash)',
];

$gettext = '/\b(?:__|_e|_n|_x|_ex|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s';
$skip    = '#/(vendor|node_modules|Dependencies|build|dist)/|\.min\.js$#';

$files = [];
foreach ($roots as $root) {
    if (is_file($root)) {
        $files[] = $root;
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (preg_match('/\.(php|js)$/', $p) && !preg_match($skip, $p)) {
            $files[] = $p;
        }
    }
}

$scanned  = 0;
$failures = [];
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    // PHP comments are blanked (strings kept, offsets kept): a commented-out call is not copy.
    // JS goes in raw; no PHP tokeniser reads it.
    if ('.php' === substr($file, -4)) {
        $src = slimstat_blank_comments($src);
    }
    if (!preg_match_all($gettext, $src, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($m[2] as [$text, $offset]) {
        $scanned++;
        foreach ($avoid as $rx => $use) {
            if (preg_match($rx, $text, $hit)) {
                $failures[] = sprintf('%s:%d: "%s" (write %s)', str_replace($plugin_root . '/', '', $file), substr_count($src, "\n", 0, $offset) + 1, $hit[0], $use);
            }
        }
    }
}

// The patterns must fire on the strings they were written for, or a clean run means nothing.
foreach (['Upgrade to Premium', 'Heatmaps (pro)', 'GeoIP database', 'Add-ons', 'see here below', 'Javascript', 'So far so good.', 'was 4 last day', 'Export to Excel', 'Slimstat Analytics', 'Users live', "a \u{2014} b"] as $bad) {
    $fires = false;
    foreach ($avoid as $rx => $_) {
        $fires = $fires || preg_match($rx, $bad);
    }
    if (!$fires) {
        $failures[] = "self-check: no pattern fires on \"{$bad}\"";
    }
}
// Floor: a regex that stopped matching gettext calls would scan nothing and pass.
if ($scanned < 1000) {
    $failures[] = "scanned only {$scanned} gettext literals; expected well over 1000";
}

if ($failures) {
    fwrite(STDERR, "copy-glossary: " . count($failures) . " string(s) use a retired term (jaan-to/docs/ui-glossary.md)\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
echo "copy-glossary: {$scanned} gettext literals clean\n";
