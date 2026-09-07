<?php
// wp eval-file instrument; reads the real deferred-window hit through Free and Pro APIs.
if (!defined('ABSPATH')) { exit(2); }
try {
    require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
    wp_slimstat_db::init();
    $class = '\\WpSlimstatPro\\Addon\\Addons\\EmailReportsAddon';
    if (!is_callable([$class, 'get_report_output'])) { throw new RuntimeException('Pro report API unavailable'); }
    $rows = wp_slimstat_db::get_recent('resource', "resource = '/rehearse-deferred-window'", '', false);
    if (!is_array($rows) || count($rows) !== 1 || $rows[0]['resource'] !== '/rehearse-deferred-window') {
        throw new RuntimeException('Free report did not return the single tracked marker');
    }
    $csv = call_user_func([$class, 'get_report_output'], $rows, ['resource'], 'Mixed window', false);
    $lines = preg_split('/\r?\n/', trim($csv));
    if (count($lines) !== 2 || str_getcsv($lines[1]) !== ['/rehearse-deferred-window']) {
        throw new RuntimeException('Pro CSV did not preserve the Free report value');
    }
    foreach (array_keys((array) get_option('slimstat_degradations', [])) as $key) {
        if (strpos($key, 'pro_') === 0) { throw new RuntimeException('Pro degraded: ' . $key); }
    }
    echo 'PRO-MIXED-WINDOW:' . json_encode(['rows' => count($rows), 'resource' => $rows[0]['resource'], 'csv_sha256' => hash('sha256', $csv)]) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
