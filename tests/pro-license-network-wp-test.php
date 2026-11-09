<?php
/** @license GPL-2.0-or-later
 * Run with wp eval-file on a disposable multisite (Pro CI's multisite step). The license key that
 * Network Analytics marks as Network is the key of every site, as Pro's own settings see it, so a
 * network license never locks Pro on its sites. Option reads are filtered; nothing is written.
 */
use SlimStat\Services\ProLicense;
if (!defined('WP_CLI') || !WP_CLI || !is_multisite()) { fwrite(STDERR, "Run with wp eval-file on a multisite.\n"); exit(1); }
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } WP_CLI::log('PASS ' . $message); };
$network = ['slimstat_pro_license_key' => 'network-key'];
$site = static function () { return ['slimstat_pro_license_key' => 'site-key']; };
$sitewide = static function () use (&$network) { return $network; };
try {
    add_filter('pre_option_slimstat_options', $site);
    add_filter('pre_site_option_slimstat_options', $sitewide);
    $check('site-key' === ProLicense::key(), 'a site uses its own key while the network does not control it');
    $network['addon_network_settings_slimstat_pro_license_key'] = 'on';
    $check('network-key' === ProLicense::key(), 'a key the network controls is the key of every site');
    $network['slimstat_pro_license_key'] = '';
    $check('none' === ProLicense::state(), 'a network that controls the key but has none leaves its sites unlicensed');
} finally {
    remove_filter('pre_option_slimstat_options', $site);
    remove_filter('pre_site_option_slimstat_options', $sitewide);
}
WP_CLI::success('Network license key assertions passed.');
