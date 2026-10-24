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

require_once __DIR__ . '/lib/source-scan.php';

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
foreach (['From search result page', 'Has left comments', 'WordPress user', 'Other human', 'Bot or crawler'] as $label) {
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

// B11: legend swatches are dots, not checkbox-like squares; "Pages live" is not
// underlined like a link; the performance line names its units in words.
check((bool) preg_match('/\.slimstat-access-log-legend \.little-color-box\s*\{[^}]*border-radius:\s*50%/', $admincss), 'B11: legend swatches are round');
check(!preg_match('/\.pages-label\s*\{[^}]*underline/', read_or_die('admin/assets/css/live-analytics.css')), 'B11: "Pages live" label is not underlined');
// C2: rate rows render with a % sign. The suffix is added by the renderer, not the
// data row, because the report-answer contract pins the raw value ("66.67").
$reports = read_or_die('admin/view/wp-slimstat-reports.php');
check((bool) preg_match('/\$percent_metrics\s*=\s*\[__\(\'Bounce rate\', \'wp-slimstat\'\), __\(\'New Visitors Rate\', \'wp-slimstat\'\)\]/', $reports), 'C2: Bounce rate and New Visitors Rate are listed as percentages');
check(false !== strpos($reports, 'in_array($a_result[\'metric\'], $percent_metrics, true) ? \'%\' : \'\''), 'C2: the summary renderer appends % to rate rows');
check(false === strpos($rightnow, "__('SL'") && false === strpos($rightnow, "__('PS'"), 'B11: no SL/PS abbreviations in the Access Log');
// QA §5: the count floated right AFTER the label, so a label that wrapped pushed its count onto
// a line of its own. The row is flex instead; DOM order (which the parity harness reads) is kept.
check((bool) preg_match('/p\.slimstat-tooltip-trigger:has\(>\s*\.slimstat-count-pct\)\s*\{[^}]*display:\s*flex/', $admincss), 'QA §5: a report row with a count is a flex row');
check((bool) preg_match('/p span\.slimstat-count-pct\s*\{[^}]*float:\s*none[^}]*margin-inline-start:\s*auto/', $admincss), 'QA §5: the count stops floating and sits at the end of the first line');
// A4: Delete heatmap data takes the destructive role. It renders only once Pro capture has set up
// the heatmap tables, so a Free-only E2E install never shows it; this is the check that always runs.
check(false !== strpos(read_or_die('admin/view/heatmaps.php'), '<p class="ss-hm-delete"><button type="button" class="button-link button-link-delete"'), 'A4: Delete heatmap data uses the destructive button role');
// QA D11: "Build your first campaign link" read one way on the Upgrade page and another on Reports.
$campaignLink = "__('Tag an incoming link to see its recorded pageviews in UTM Campaigns.', 'wp-slimstat')";
check(false !== strpos($reports, $campaignLink) && false !== strpos(read_or_die('admin/view/upgrade-pro.php'), str_replace('__(', 'esc_html_e(', $campaignLink)), 'D11: one description for "Build your first campaign link"');
// QA D7: the #aaa base border around a pale 10px fill read as a hollow ring, not the row colour it names.
check((bool) preg_match('/\.slimstat-access-log-legend \.little-color-box\s*\{[^}]*width:\s*12px[^}]*border-color:\s*rgba\(0,\s*0,\s*0,\s*\.12\)/', $admincss), 'D7: legend swatches are filled dots with a hairline, not #aaa rings');
// QA R12: the pill rule for settings inputs also rounded checkboxes, so Email Report's picks read as radio buttons.
check(false === strpos($admincss, '.wrap-slimstat .form-table tr td input{') && false !== strpos($admincss, '.wrap-slimstat .form-table tr td input:not([type=checkbox]):not([type=radio]){'), 'R12: form-table pill inputs leave checkboxes and radios native');

// --- 6.0 release notice (tests/Integration/ReleaseNoticeTest.php covers the rendering) ---
$admin = read_or_die('admin/index.php');
// Menu position: with no $position, add_menu_page() appends SlimStat below Settings. 59 is the
// separator's slot, so core's collision nudge lands it in (59, 60) on WP 5.6+, never on another menu.
// Not 58: WooCommerce's Marketing menu is there. Not a float or string: WP < 6.0 truncates a free
// float key onto the separator, and core types $position as int|float (PHPStan).
check((bool) preg_match("/add_menu_page\([^;]*'dashicons-chart-area',\s*59\s*\);/", $admin), 'Menu: SlimStat sits between the separator and Appearance (position 59)');
check(false !== strpos(read_or_die('wp-slimstat.php'), "\$defaults['notice_latest_news'] = 'no';"), 'Release notice: new installs never see it (get_fresh_defaults sets no)');
check(false !== strpos(read_or_die('uninstall.php'), "delete_option('slimstat_release_notice_since')"), 'Release notice: uninstall deletes its anchor');
check((bool) preg_match("/if \(version_compare\([^;]*'6\.0\.0', '<'\)\) \{\s*wp_slimstat::\\\$settings\['notice_latest_news'\] = 'on';/", $admin), 'Release notice: only an upgrade from 5.x turns it on, so a 6.0.x update does not undo a dismissal');
preg_match("/\/\/ 6\.0 release notice:.*?add_action\('admin_notices', \[self::class, 'show_latest_news'\], 10, 0\);/s", $admin, $gate);
$gate = $gate[0] ?? '';
check('' !== $gate, 'Release notice: the gate in init() is present');
check(false !== strpos($gate, "current_user_can('manage_options')") && false === strpos($gate, 'is_super_admin'), 'Release notice: the gate uses the capability notices_handler() checks, not is_super_admin()');
preg_match_all("/'([\w-]+\.php)'/", $gate, $pages);
check(['index.php', 'plugins.php'] === $pages[1] && false === strpos($gate, 'slimview'), 'Release notice: it shows on the Dashboard and Plugins screens only, never on SlimStat\'s own');
$dismiss = read_or_die('admin/assets/js/promotions.js');
check(false !== strpos($dismiss, "action: 'slimstat_notice_latest_news'") && false !== strpos($dismiss, 'security: bar.dataset.dismissNonce'), 'Release notice: dismissal posts the shared nonce-protected AJAX action');
check(false !== strpos($admin, "wp_create_nonce('meta-box-order')") && false !== strpos($admin, 'self::enqueue_promotions();'), 'Release notice: rendered bar has its dismiss nonce and shared script');
check(false !== strpos($dismiss, "closest('.slimstat-release-dismiss')") && false === strpos($dismiss, 'notice-dismiss'), 'Release notice: its dedicated button cannot trigger core notice handlers');
check(false !== strpos(read_or_die('admin/view/partials/header.php'), "show_latest_news(null, null, null, 'bar')"), 'Release bar: shared header renders it across SlimStat pages');
check(false !== strpos(read_or_die('src/Migration/MigrationService.php'), 'public static function manager(): ?MigrationManager'), 'Release notice: MigrationService exposes the manager so the notice can wait for migrations');
// Plugin Check (WordPress.Security.EscapeOutput) flags `echo $var` even when $var holds escaped
// pieces, so each placement is assembled first and echoed once through wp_kses_post().
// Checked per placement: the notice branch returns early, the bar branch is the remainder.
$notice = slimstat_function_body($admin, 'show_latest_news');
$split  = (int) strpos($notice, "if ('notice' === \$placement)");
$end    = (int) strpos($notice, 'return;', $split) + strlen('return;');
check($split > 0 && $end > $split, 'Release notice: the placement branches are where the guard expects them');
foreach (['notice' => substr($notice, $split, $end - $split), 'bar' => substr($notice, $end)] as $placement => $branch) {
    check(1 === preg_match_all('/\becho\b/', $branch) && 1 === substr_count($branch, 'echo wp_kses_post('), "Release {$placement}: echoes once, through wp_kses_post()");
}

// --- Pro links (tests/Integration/ProLinksTest.php covers Export and the Channels row) ---
$upgrade = read_or_die('admin/view/upgrade-pro.php');
preg_match('/\$converts = \[(.*?)\n\];/s', $upgrade, $converts);
check(false !== strpos($converts[1] ?? '', "__('Channels and UTM Campaigns', 'wp-slimstat')"), 'Pro page: "See all channels with Pro" lands on a section that lists Channels and UTM Campaigns');
$proAdds = read_or_die('admin/view/partials/pro-adds.php');
preg_match('/<\?php if \(current_user_can\(\'manage_options\'\)\) : \?>(.*?)<\?php endif; \?>/s', $proAdds, $proAddsLinks);
check(3 === substr_count($proAdds, '<a ') && 3 === substr_count($proAddsLinks[1] ?? '', '<a '), 'Pro adds: purchase and alternative activation/comparison links stay inside manage_options');
$ecommerce = read_or_die('admin/view/partials/ecommerce.php');
check(false !== strpos($ecommerce, "'action' => __('Unlock Ecommerce insights with Pro', 'wp-slimstat')") && false !== strpos($ecommerce, "'campaign' => 'ecommerce', 'content' => 'kpi-blur'"), 'Ecommerce: the KPI unlock uses the shared capability-aware attributed action');
// B1 (release review 2026-10-13): the setup-failure reason renders before the setup CTA, or the
// admin is sent back to the same button with no explanation, forever.
$setupError = strpos($ecommerce, 'elseif (!Integration::ready() && \'\' !== $error)');
$setupCta = strpos($ecommerce, 'elseif (!Integration::ready()) :');
check(false !== $setupError && false !== $setupCta && $setupError < $setupCta, 'Ecommerce: a failed setup shows its reason before the "Set up Ecommerce" button');
$integration = read_or_die('src/Ecommerce/Integration.php');
preg_match('/function setup\(\): void(.*?)\n\t}\n/s', $integration, $setupBody);
$engineCheck = strpos($setupBody[1] ?? '', 'self::requireInnoDb(');
check(false !== $engineCheck && $engineCheck < (int) strpos($setupBody[1] ?? '', 'createTableSql('), 'Ecommerce: setup names a MyISAM analytics table before it creates the foreign-keyed table');
// S3 (release review 2026-10-13): MyISAM has no online index build, so the Migration page says
// tracking pauses while it runs, and only when the controller found a MyISAM table.
$migrationPage = read_or_die('src/view/migration-page.php');
check((bool) preg_match('/<\?php if \(\$analytics_myisam\) \{ echo \' \' \. esc_html__\(\'[^\']*MyISAM[^\']*tracking pauses/', $migrationPage), 'Migration page: the MyISAM sentence is drawn only under $analytics_myisam');
$migrationAdmin = read_or_die('src/Migration/Admin/MigrationAdmin.php');
check(false !== strpos($migrationAdmin, 'Schema::tableEngine('), 'Migration page: renderPage() reads the analytics table engine');
// Required steps also build idx_events_notes_dt, which write-locks a MyISAM slim_events (CodeRabbit, PR #349).
check((bool) preg_match("/\['slim_stats', 'slim_events'\]/", $migrationAdmin), 'Migration page: the MyISAM probe covers slim_stats and slim_events');
check(false === strpos($ecommerce, 'wp-slimstat.com/pricing') && false === strpos(read_or_die('src/Reports/AcquisitionReport.php'), 'wp-slimstat.com/pricing'), 'Ecommerce and Channels: no pricing link for users who cannot buy');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} check(s) failed in admin-ui-render-guards-test.php\n");
    exit(1);
}
echo "OK: C3 profile link, C6 geoip-date row, C8 color legend present and guarded\n";
