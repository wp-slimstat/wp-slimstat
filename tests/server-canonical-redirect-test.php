<?php
/** Exercise the production hook registration in WordPress request order. */
namespace SlimStat\Tracker {
    class Tracker {
        public static $pageviews = [];
        public static function slimtrack() { self::$pageviews[] = $GLOBALS['resource']; }
    }
}
namespace {
    class CanonicalRedirect extends \RuntimeException {}
    function is_admin() { return $GLOBALS['admin_request']; }
    function add_action($hook, $callback, $priority = 10) { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
    function run_hook($hook) {
        $priorities = $GLOBALS['hooks'][$hook] ?? [];
        ksort($priorities);
        foreach ($priorities as $callbacks) { foreach ($callbacks as $callback) { $callback(); } }
    }
    $source = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
    $start = strpos($source, '// Is server-side tracking active?');
    $end = strpos($source, '// Slimstat tracks screen resolutions', $start);
    if ($start === false || $end === false) { throw new \RuntimeException('Tracking registration not found'); }
    eval('class HookProbe { public static $settings; public static function register() {' . substr($source, $start, $end - $start) . '} }');
    function register_tracker($client = false, $admin = false, $ignore_users = false) {
        $GLOBALS['hooks'] = [];
        $GLOBALS['admin_request'] = $admin;
        HookProbe::$settings = ['javascript_mode' => $client ? 'on' : 'off', 'ignore_wp_users' => $ignore_users ? 'on' : 'off'];
        HookProbe::register();
    }
    function request_page($resource, $redirect = false) {
        $GLOBALS['resource'] = $resource;
        register_tracker();
        add_action('template_redirect', static function () use ($redirect) { if ($redirect) { throw new CanonicalRedirect(); } }, 10);
        try { run_hook('wp'); run_hook('template_redirect'); } catch (CanonicalRedirect $e) {}
    }
    request_page('/sample-page?campaign=one', true);
    request_page('/sample-page/?campaign=one');
    if (\SlimStat\Tracker\Tracker::$pageviews !== ['/sample-page/?campaign=one']) { throw new \RuntimeException('Canonical navigation counted twice'); }
    request_page('/sample-page/?campaign=one');
    request_page('/sample-page/?campaign=two');
    request_page('/route-without-slash'); // No redirect: do not normalize or discard it.
    if (\SlimStat\Tracker\Tracker::$pageviews !== ['/sample-page/?campaign=one', '/sample-page/?campaign=one', '/sample-page/?campaign=two', '/route-without-slash']) {
        throw new \RuntimeException('Repeat views, meaningful queries or distinct routes changed');
    }
    register_tracker(true);
    if ($GLOBALS['hooks']) { throw new \RuntimeException('Client mode registered Server tracking'); }
    register_tracker(false, true);
    if (!isset($GLOBALS['hooks']['admin_init'][5], $GLOBALS['hooks']['login_init'][10])) { throw new \RuntimeException('Admin/login tracking changed'); }
    register_tracker(false, false, true);
    if (isset($GLOBALS['hooks']['login_init'])) { throw new \RuntimeException('Excluded users received login tracking'); }
    echo "PASS: canonical redirect hops skipped; destination, repeats, queries, distinct paths, Client mode and admin/login hooks preserved\n";
}
