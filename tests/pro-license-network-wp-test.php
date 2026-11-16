<?php
/** @license GPL-2.0-or-later
 * Run with wp eval-file on a disposable multisite (Pro CI's multisite step). The license key that
 * Network Analytics marks as Network is the key of every site, as Pro's own settings see it, so a
 * network license never locks Pro on its sites. Option reads are filtered; nothing is written.
 */
use SlimStat\Services\ProLicense;
use SlimStat\Services\Admin\ConditionTagEvaluator;
if (!defined('WP_CLI') || !WP_CLI || !is_multisite()) { fwrite(STDERR, "Run with wp eval-file on a multisite.\n"); exit(1); }
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } WP_CLI::log('PASS ' . $message); };
$network = ['slimstat_pro_license_key' => 'network-key'];
$site = static function () { return ['slimstat_pro_license_key' => 'site-key']; };
$sitewide = static function () use (&$network) { return $network; };
$networkPlugin = static function () { return ['wp-slimstat-pro/wp-slimstat-pro.php' => 1]; };
try {
    add_filter('pre_option_slimstat_options', $site);
    add_filter('pre_site_option_slimstat_options', $sitewide);
    $check('site-key' === ProLicense::key(), 'a site uses its own key while the network does not control it');
    $network['addon_network_settings_slimstat_pro_license_key'] = 'on';
    $check('network-key' === ProLicense::key(), 'a key the network controls is the key of every site');
    $check(!ConditionTagEvaluator::hasNoLicense(), 'notification tags recognize a network-controlled key');
    $network['slimstat_pro_license_key'] = '';
    $check('none' === ProLicense::state(), 'a network that controls the key but has none leaves its sites unlicensed');
    $check(ConditionTagEvaluator::hasNoLicense(), 'the empty network key wins over a stale local key for notification tags');
    add_filter('pre_site_option_active_sitewide_plugins', $networkPlugin);
    $check(ConditionTagEvaluator::hasProPlugin(), 'addon tags recognize network-activated Pro without a license');
    $check(!ConditionTagEvaluator::hasNoProPlugin(), 'network-activated Pro never matches the no-addon audience');
} finally {
    remove_filter('pre_site_option_active_sitewide_plugins', $networkPlugin);
    remove_filter('pre_option_slimstat_options', $site);
    remove_filter('pre_site_option_slimstat_options', $sitewide);
}
WP_CLI::success('Network license key assertions passed.');
