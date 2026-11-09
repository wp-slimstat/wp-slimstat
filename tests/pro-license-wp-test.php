<?php
/** @license GPL-2.0-or-later
 * Run with wp eval-file. Pro entitlement: the verdict state machine, the license-server answer
 * mapping, the locked notice, and which Pro hooks the lock detaches. Restores every option it touches.
 */
use SlimStat\Services\ProLicense;
if (!defined('ABSPATH')) { exit(1); }
if (!defined('WP_CLI') || !WP_CLI) { fwrite(STDERR, "Run with wp eval-file.\n"); exit(1); }
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$private = static function ($method) { $m = new ReflectionMethod(ProLicense::class, $method); $m->setAccessible(true); return $m; };
$client = static function ($answer) { return new class($answer) { private $a; public function __construct($a) { $this->a = $a; } public function licenseStatus($key, $domain) { return $this->a; } }; };
$verdict = get_option(ProLicense::OPTION, null);
$user = get_current_user_id();
$key = 'wp-test-license';
$licensed = static function ($options) use (&$key) { return ['slimstat_pro_license_key' => $key] + (array) $options; };
$pro = static function ($plugins) { return array_values(array_unique(array_merge((array) $plugins, ['wp-slimstat-pro/wp-slimstat-pro.php']))); };
$noPro = static function ($plugins) { return array_values(array_diff((array) $plugins, ['wp-slimstat-pro/wp-slimstat-pro.php'])); };
$backdate = static function ($seconds) { $v = get_option(ProLicense::OPTION); $v['checked'] -= $seconds; $v['tried'] -= $seconds; update_option(ProLicense::OPTION, $v); };
try {
    add_filter('option_slimstat_options', $licensed);
    add_filter('option_active_plugins', $pro);
    delete_option(ProLicense::OPTION);
    wp_set_current_user(get_users(['role' => 'administrator', 'number' => 1])[0]->ID);

    // Verdict state machine.
    $key = '';
    $check('none' === ProLicense::state(), 'No key: none');
    $key = 'wp-test-license';
    $check('unverified' === ProLicense::state() && !ProLicense::active(), 'A key alone is not a license');
    ProLicense::record($key, 'unreachable');
    $check('unverified' === ProLicense::state(), 'An outage never grants first access');
    ProLicense::record($key, 'valid');
    $check(ProLicense::active() && wp_slimstat::pro_is_installed(), 'valid unlocks Pro');
    $backdate(ProLicense::TTL - HOUR_IN_SECONDS);
    ProLicense::record($key, 'unreachable');
    $check(ProLicense::active(), 'An outage keeps a confirmed license inside the grace period');
    $backdate(2 * HOUR_IN_SECONDS);
    $check('unverified' === ProLicense::state() && !wp_slimstat::pro_is_installed(), 'Past the grace period the license is unverified');
    ProLicense::record($key, 'valid');
    $backdate(-2 * HOUR_IN_SECONDS);
    $check('unverified' === ProLicense::state(), 'A verdict from the future is not trusted');
    foreach (['invalid', 'domain_not_activated'] as $state) {
        ProLicense::record($key, 'valid');
        ProLicense::record($key, $state);
        $check($state === ProLicense::state(), $state . ' locks at once');
    }
    ProLicense::record($key, 'valid');
    $key = 'wp-other-license';
    $check('unverified' === ProLicense::state(), 'Changing the key discards the verdict');
    ProLicense::record($key, 'unreachable');
    $key = 'wp-test-license';
    $check('unverified' === ProLicense::state(), 'A verdict for another key never unlocks this one');
    ProLicense::record('  ', 'valid');
    $check('unverified' === ProLicense::state(), 'A blank key records nothing');

    // Refresh throttle: retry after 5 minutes, re-confirm after 12 hours.
    $due = $private('due');
    ProLicense::record($key, 'valid');
    $check(!$due->invoke(null, $key), 'A fresh license is not due');
    $backdate(ProLicense::DUE);
    $check($due->invoke(null, $key), 'A license is due after 12 hours');
    ProLicense::record($key, 'unreachable');
    $check(!$due->invoke(null, $key), 'An outage waits 5 minutes before the next try');
    $backdate(ProLicense::RETRY);
    $check($due->invoke(null, $key), 'An outage is retried after 5 minutes');

    // License-server answers, as Pro's client returns them.
    $ask = $private('ask');
    $answers = [
        'valid'                => ['status' => 'valid', 'license_details' => ['status' => 'active']],
        'valid '               => ['status' => 'valid', 'license_details' => []],
        'invalid'              => ['status' => 'valid', 'license_details' => ['status' => 'expired']],
        'invalid '             => ['status' => 'invalid'],
        'domain_not_activated' => ['status' => 'domain_not_activated'],
        'unreachable'          => ['status' => 'valid', 'license_details' => 'active'],
        'unreachable '         => ['status' => 'surprise'],
        'unreachable  '        => 'not json',
        'unreachable   '       => new WP_Error('http_request_failed', 'down'),
        'unreachable    '      => new WP_Error('http', 'busy', ['status' => 503]),
        'unreachable     '     => ['status' => 'valid', 'license_details' => null],
        'unreachable      '    => ['status' => 'valid', 'license_details' => ['status' => null]],
        'unreachable       '   => ['status' => 'valid', 'license_details' => ['status' => []]],
        'unreachable        '  => ['status' => 'domain_not_activated', 'license_details' => null],
        'invalid  '            => new WP_Error('http', 'refused', ['status' => 403]),
        'invalid   '           => new WP_Error('http', 'refused', ['status' => 401]),
    ];
    foreach ($answers as $expected => $answer) {
        $check(trim($expected) === $ask->invoke(null, $client($answer), $key), 'Answer maps to ' . trim($expected) . ': ' . wp_json_encode(is_wp_error($answer) ? $answer->get_error_data() : $answer));
    }

    // Locked notice and its links, per state: the fix first, then the account and plans when a key is missing or wrong.
    $hasClient = class_exists(ProLicense::CLIENT);
    $admin = get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
    $states = [
        'none'                 => ['activate a license', ['Activate license', 'Find my license', 'View plans']],
        'domain_not_activated' => ['not activated for this site', ['Activate license', 'Find my license']],
        'invalid'              => ['expired, revoked', ['Open License settings', 'Find my license', 'View plans']],
        'unreachable'          => ['could not verify', ['Open License settings']],
    ];
    foreach ($states as $state => [$text, $labels]) {
        delete_option(ProLicense::OPTION);
        $key = 'none' === $state ? '' : 'wp-test-license';
        if ('none' !== $state) { ProLicense::record($key, $state); }
        $message = ProLicense::message();
        $links = ProLicense::links();
        $check(false !== strpos($message, $hasClient ? $text : 'Update SlimStat Pro') && false !== strpos($message, 'settings and data are kept'), 'Notice for ' . $state . ': ' . $message);
        $check(($hasClient ? $labels : ['Open Plugins']) === array_column($links, 0), 'Links for ' . $state . ': ' . wp_json_encode($links));
        $check($links[0] === ProLicense::action(), 'The header button is the banner\'s first link for ' . $state);
        $check(!$hasClient || false !== strpos($links[0][1], 'page=slimconfig&tab=8') && false === $links[0][2], 'The fix opens License settings for ' . $state);
        foreach (array_slice($links, 1) as [$label, $url, $external]) {
            $expected = 'Find my license' === $label ? ProLicense::ACCOUNT : 'https://wp-slimstat.com/pricing/?';
            $check($external && 0 === strpos($url, $expected), $label . ' leads to ' . $expected . ' for ' . $state . ': ' . $url);
        }
    }

    // One banner on the Dashboard and the Plugins screen, for administrators only; never elsewhere.
    $check(10 === has_action('admin_notices', [ProLicense::class, 'screen_notice']), 'The Dashboard and Plugins banner is registered');
    $pagenow = $GLOBALS['pagenow'] ?? null;
    $screen = static function ($page) { $GLOBALS['pagenow'] = $page; ob_start(); ProLicense::screen_notice(); return (string) ob_get_clean(); };
    delete_option(ProLicense::OPTION);
    $key = '';
    foreach (['index.php', 'plugins.php'] as $page) {
        $html = $screen($page);
        $check(1 === substr_count($html, 'slimstat-pro-license-notice" role="status"') && 1 === substr_count($html, 'button-primary') && false === strpos($html, 'is-dismissible'), 'One banner that cannot be dismissed on ' . $page);
        $check(!$hasClient || false !== strpos($html, 'href="' . ProLicense::ACCOUNT . '" target="_blank" rel="noopener noreferrer"'), 'Find my license opens the account in a new tab on ' . $page);
    }
    $check('' === $screen('edit.php') && '' === $screen('admin.php'), 'No banner on other WordPress screens');
    wp_set_current_user(0);
    $check('' === $screen('index.php'), 'No Dashboard banner for users who cannot manage the license');
    $key = 'wp-test-license';
    ob_start();
    ProLicense::render();
    $html = (string) ob_get_clean();
    $check(false !== strpos(ProLicense::message(), 'site administrator') && [] === ProLicense::links() && false !== strpos($html, 'site administrator') && false === strpos($html, '<a '), 'Users who cannot fix the lock get the administrator message and no link');
    wp_set_current_user($admin);
    ProLicense::record($key, 'valid');
    $check('' === ProLicense::message() && [] === ProLicense::links() && '' === $screen('index.php') && '' === $screen('plugins.php'), 'No notice while licensed');
    remove_filter('option_active_plugins', $pro);
    add_filter('option_active_plugins', $noPro);
    delete_option(ProLicense::OPTION);
    $check('' === ProLicense::message() && '' === $screen('index.php') && '' === $screen('plugins.php'), 'No notice without Pro');
    remove_filter('option_active_plugins', $noPro);
    add_filter('option_active_plugins', $pro);
    $GLOBALS['pagenow'] = $pagenow;

    // The lock detaches Pro features and keeps the license screen, the updater, Custom DB and Free.
    $tag = 'slimstat_pro_license_probe';
    $global = static function () {};
    $callbacks = [
        'WpSlimstatPro\Addon\Addons\ProbeAddon::run' => false,
        '\WpSlimstatPro\AI\Analysis::routes'         => false,
        'WpSlimstatPro\Addon\Addons\CustomDBAddon::run' => true,
        'WpSlimstatPro\Admin\AdminManager::menu'     => true,
        'WpSlimstatPro\Update\NexusClient::run'      => true,
        'SlimStat\Services\ProLicense::state'        => true,
        'wp_slimstat_pro_probe'                      => true,
    ];
    $attach = static function () use ($tag, $callbacks, $global) {
        foreach (array_keys($callbacks) as $callback) { add_filter($tag, $callback); }
        add_filter($tag, ['WpSlimstatPro\Heatmap\Viewer', 'render']);
        add_filter($tag, $global);
        add_action('network_admin_menu', ['wp_slimstat_admin', 'add_menus']);
    };
    $attach();
    ProLicense::record($key, 'valid');
    ProLicense::enforce();
    $check(false !== has_filter($tag, ['WpSlimstatPro\Heatmap\Viewer', 'render']) && false !== has_action('network_admin_menu', ['wp_slimstat_admin', 'add_menus']), 'A valid license detaches nothing');
    delete_option(ProLicense::OPTION);
    ProLicense::enforce();
    foreach ($callbacks as $callback => $kept) {
        $check($kept === (false !== has_filter($tag, $callback)), ($kept ? 'Kept: ' : 'Detached: ') . $callback);
    }
    $check(false === has_filter($tag, ['WpSlimstatPro\Heatmap\Viewer', 'render']), 'Detached: array callback');
    $check(false !== has_filter($tag, $global), 'Kept: closure outside Pro classes');
    $check(false === has_action('network_admin_menu', ['wp_slimstat_admin', 'add_menus']), 'Detached: Pro network menu');
    remove_all_filters($tag);
    $attach();
    remove_filter('option_active_plugins', $pro);
    add_filter('option_active_plugins', $noPro);
    ProLicense::enforce();
    $check(false !== has_filter($tag, ['WpSlimstatPro\Heatmap\Viewer', 'render']), 'Without Pro nothing is detached');
    remove_all_filters($tag);

    // Refresh through Pro's own client, when this site has it.
    remove_filter('option_active_plugins', $noPro);
    add_filter('option_active_plugins', $pro);
    if ($hasClient) {
        $calls = 0;
        $server = static function ($pre, $args, $url) use (&$calls) {
            if (0 !== strpos((string) $url, 'https://my.wp-slimstat.com/api/v1/license/')) { return $pre; }
            $calls++;
            return ['headers' => [], 'body' => wp_json_encode(['status' => 'valid', 'license_details' => ['status' => 'active']]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $server, 10, 3);
        delete_option(ProLicense::OPTION);
        $check('valid' === ProLicense::refresh() && 1 === $calls, 'Refresh asks the license server once through Pro');
        wp_clear_scheduled_hook(ProLicense::HOOK);
        ProLicense::schedule();
        $check(false === wp_next_scheduled(ProLicense::HOOK), 'Nothing is scheduled while the license is fresh');
        $backdate(ProLicense::DUE);
        ProLicense::schedule();
        $check(false !== wp_next_scheduled(ProLicense::HOOK), 'A due license is refreshed in the background');
        wp_clear_scheduled_hook(ProLicense::HOOK);
        remove_filter('pre_http_request', $server, 10);
    } else {
        delete_option(ProLicense::OPTION);
        $check('unverified' === ProLicense::refresh() && false === get_option(ProLicense::OPTION), 'Pro without the client stays locked and records nothing');
    }
    echo "PASS: Pro license verdict, grace period, throttle, answer mapping, notices and detach\n";
} finally {
    remove_filter('option_slimstat_options', $licensed);
    remove_filter('option_active_plugins', $pro);
    remove_filter('option_active_plugins', $noPro);
    null === $verdict ? delete_option(ProLicense::OPTION) : update_option(ProLicense::OPTION, $verdict);
    wp_set_current_user($user);
}
