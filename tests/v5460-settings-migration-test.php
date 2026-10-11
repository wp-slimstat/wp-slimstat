<?php
/** Upgrade compatibility: inherited defaults and explicit choices are equally binding. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
require_once dirname(__DIR__) . '/src/Migration/LegacySettings5460.php';
require_once __DIR__ . '/lib/source-scan.php';
use SlimStat\Migration\LegacySettings5460;
$count = 0;
$assert = static function ($expected, $actual, string $why) use (&$count): void {
    ++$count;
    if ($expected !== $actual) { throw new RuntimeException($why); }
};
$source = slimstat_blank_comments((string) file_get_contents(dirname(__DIR__) . '/wp-slimstat.php'));
$assert(1, preg_match_all('/LegacySettings5460::apply\\s*\\(/', $source), 'init() calls LegacySettings5460::apply() exactly once');
foreach (['0', '5.4.0', '5.4.6', '5.4.7', '5.5.0', '6.0.0'] as $version) {
    foreach (['off', 'no', false, 0, '', 'on'] as $choice) {
        $saved = ['_migration_5460' => $version, 'gdpr_enabled' => $choice, 'consent_integration' => '',
            'use_slimstat_banner' => $choice, 'javascript_mode' => $choice, 'set_tracker_cookie' => $choice,
            'anonymize_ip' => $choice, 'hash_ip' => $choice, 'heatmap_capture' => $choice,
            'heatmap_rate' => 0, 'heatmap_pages' => '', 'delete_data_on_uninstall' => $choice,
            'auto_purge' => 0, 'rows_to_show' => 7, 'addon_email_report_frequency' => 'monthly',
            'addon_email_report_email' => 'fixture@example.test', 'capability_can_view' => 'read',
            'custom_option' => ['nested' => false]];
        $result = LegacySettings5460::apply($saved, '6.0.0', false, true);
        $after = $result['settings']; $before = $saved;
        unset($before['_migration_5460'], $after['_migration_5460']);
        $assert($before, $after, 'User configuration changed for ' . $version);
        $assert(false, $result['ip_notice'], 'No misleading IP reset notice');
        $assert($result['settings'], LegacySettings5460::apply($result['settings'], '6.0.0', false, true)['settings'], 'Retry must be idempotent');
    }
}
$legacy = ['display_opt_out' => 'on', 'javascript_mode' => 'off', 'anonymize_ip' => 'on'];
$mapped = LegacySettings5460::apply($legacy, '6.0.0', false, false)['settings'];
$assert('on', $mapped['gdpr_enabled'], 'Map legacy opt-out consent');
$assert('slimstat_banner', $mapped['consent_integration'], 'Map legacy banner');
$assert('off', $mapped['javascript_mode'], 'Preserve server tracking');
$assert('on', $mapped['anonymize_ip'], 'Preserve anonymization');
$modern = $legacy + ['gdpr_enabled' => 'off', 'consent_integration' => ''];
$assert('off', LegacySettings5460::apply($modern, '6.0.0', false, true)['settings']['gdpr_enabled'], 'An explicit modern choice wins');
$fresh = ['gdpr_enabled' => 'off', 'heatmap_capture' => 'full'];
$assert('full', LegacySettings5460::apply($fresh, '6.0.0', true, false)['settings']['heatmap_capture'], 'Fresh defaults remain separate');
$old = ['track_users' => 'no'];
$assert('on', LegacySettings5460::apply($old, '6.0.0', false, false)['settings']['ignore_wp_users'], 'Rename legacy do-not-track-users choice');
$modern = $old + ['ignore_wp_users' => 'off'];
$assert('off', LegacySettings5460::apply($modern, '6.0.0', false, false)['settings']['ignore_wp_users'], 'Modern choice overrides obsolete name');
$merged = $old + ['ignore_wp_users' => 'no'];
$assert('on', LegacySettings5460::apply($merged, '6.0.0', false, false, $old)['settings']['ignore_wp_users'], 'A merged default must not hide the legacy choice');
echo "PASS: {$count} upgrade-preservation assertions\n";
