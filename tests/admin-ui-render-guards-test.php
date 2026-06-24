<?php

/**
 * Source-level guards for the additive admin-UI quick wins (C3, C6, C8).
 *
 * These are view-layer render changes (procedural admin templates), so full
 * Playwright E2E in CI is the behavioural check; this scanner is the fast local
 * regression guard that the markup/guards stay in place.
 *
 *   C3 (#273) — Access Log author rows get a capability-guarded link to the
 *               admin user profile, in addition to the existing author archive.
 *   C6 (#77)  — Settings shows the GeoIP last-download date with an epoch-zero
 *               "Never" guard.
 *   C8 (#281) — Access Log renders an inline color legend (non-dashboard only).
 *
 * Run: php tests/admin-ui-render-guards-test.php
 */

declare(strict_types=1);

$failures = 0;
function check(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        $failures++;
    }
}
function read_or_die(string $rel): string
{
    $src = file_get_contents(__DIR__ . '/../' . $rel);
    if (false === $src) {
        fwrite(STDERR, "FAIL: cannot read {$rel}\n");
        exit(1);
    }
    return $src;
}

// --- C3: guarded admin-profile link in the author cells ---------------------
$reports = read_or_die('admin/view/wp-slimstat-reports.php');
check(false !== strpos($reports, 'function get_edit_profile_link('), 'C3: get_edit_profile_link() helper exists');
check(substr_count($reports, 'self::get_edit_profile_link(') >= 2, 'C3: both author cells call get_edit_profile_link()');
check(false !== strpos($reports, 'slimstat-author-profile-link'), 'C3: profile link uses the slimstat-author-profile-link class');
check(
    (bool) preg_match('/\$edit_link\s*=\s*get_edit_user_link\(.*?if\s*\(\s*!\$edit_link\s*\)/s', $reports),
    'C3: the helper is capability-guarded (returns "" when get_edit_user_link() is empty)'
);

// --- C6: GeoIP last-download date with epoch-zero guard ---------------------
$config = read_or_die('admin/config/index.php');
check(false !== strpos($config, "'last_geoip_dl'"), 'C6: a last_geoip_dl settings row is rendered');
check(false !== strpos($config, "get_option('slimstat_last_geoip_dl'"), 'C6: reads the slimstat_last_geoip_dl option');
check(
    (bool) preg_match("/get_option\('slimstat_last_geoip_dl',\s*0\)\)\s*>\s*0/", $config),
    'C6: guards the 0/never-downloaded default (no Jan-1-1970)'
);
check(false !== strpos($config, "__('Never', 'wp-slimstat')"), 'C6: shows "Never" when not yet downloaded');
// must stay read-only — no write call introduced in the display row
check(
    !preg_match("/(update_option|delete_option)\([^)]*slimstat_last_geoip_dl/", $config),
    'C6: the display row must not write the geoip option'
);

// --- C8: inline color legend, non-dashboard only, with clear swatch↔label pairs
$rightnow    = read_or_die('admin/view/right-now.php');
check(false !== strpos($rightnow, 'slimstat-access-log-legend'), 'C8: inline legend block is rendered');
$legendStart = strpos($rightnow, 'slimstat-access-log-legend');
$legendEnd   = false !== $legendStart ? strpos($rightnow, '</p>', $legendStart) : false;
$legendBlock = (false !== $legendStart && false !== $legendEnd) ? substr($rightnow, $legendStart, $legendEnd - $legendStart) : '';
$gateWindow  = false !== $legendStart ? substr($rightnow, max(0, $legendStart - 300), 300) : '';
check(false !== strpos($legendBlock, 'little-color-box'), 'C8: legend reuses the existing little-color-box swatches');
check(
    (bool) preg_match('/if\s*\(\s*!\$is_dashboard\s*\)/', $gateWindow),
    'C8: legend is gated on !$is_dashboard (hidden in the compact widget)'
);
// All five categories and their swatch classes are present.
foreach (['From search result page', 'Has Left Comments', 'WP User', 'Other Human', 'Bot or Crawler'] as $label) {
    check(false !== strpos($legendBlock, $label), "C8: legend has the \"{$label}\" category");
}
foreach (['is-search-engine', 'is-known-visitor', 'is-known-user', 'is-direct'] as $cls) {
    check(false !== strpos($legendBlock, $cls), "C8: legend swatch uses {$cls}");
}
// Clarity (#impeccable): each swatch is grouped with its label and carries a
// hover tooltip, so the colour → meaning mapping is unambiguous.
check(false !== strpos($legendBlock, 'slimstat-legend-item'), 'C8: swatches are grouped with labels via .slimstat-legend-item');
check(false !== strpos($legendBlock, 'title='), 'C8: each swatch carries a tooltip title');

// C8 CSS: the legend lays swatch+label inline (flex) and overrides the global
// float:left so swatches sit next to their labels instead of stranding.
$admincss = read_or_die('admin/assets/css/admin.css');
check((bool) preg_match('/\.slimstat-access-log-legend[^{]*\{[^}]*display:\s*flex/', $admincss), 'C8: legend is laid out with flex');
check((bool) preg_match('/\.slimstat-access-log-legend \.little-color-box\s*\{[^}]*float:\s*none/', $admincss), 'C8: legend swatches override float:left');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} check(s) failed in admin-ui-render-guards-test.php\n");
    exit(1);
}
echo "OK: C3 profile link, C6 geoip-date row, C8 color legend present and guarded\n";
