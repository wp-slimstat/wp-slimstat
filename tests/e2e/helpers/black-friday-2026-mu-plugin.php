<?php
/**
 * E2E Test MU-Plugin: Black Friday 2026 strip.
 *
 * The strip's date gate follows the slimstat_e2e_bf2026 option ('on' shows it), so the rest of
 * the suite never sees it, even when E2E runs during Nov 23 to Nov 30. Also registers an
 * index.php?page= subpage (a screen the strip must skip) and prints the query count in the admin
 * footer, so a spec can compare strip on and off.
 *
 * Guarded by SLIMSTAT_E2E_TESTING, like every other helper here.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    return;
}

add_filter('slimstat_black_friday_2026_banner', static function () {
    return 'on' === get_option('slimstat_e2e_bf2026');
}, 99);

add_action('admin_menu', static function () {
    add_dashboard_page('BF26 E2E', 'BF26 E2E', 'manage_options', 'slimstat-e2e-bf26', static function () {
        echo '<div class="wrap"><h1>BF26 E2E</h1></div>';
    });
});

add_action('admin_footer', static function () {
    printf('<!-- slimstat-e2e-queries:%d -->', (int) get_num_queries());
}, PHP_INT_MAX);
