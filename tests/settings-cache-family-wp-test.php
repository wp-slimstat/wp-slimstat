<?php
/** Run with wp eval-file in a disposable qualification site. Exercises the real callback. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
if (!function_exists('wp_slimstat_clear_cache_handler')) { fwrite(STDERR, "Load WordPress and the candidate Free plugin with wp eval-file.\n"); exit(2); }
class SlimstatCacheCanaryExit extends RuntimeException {}
global $wpdb;
$owned = '_transient_wp_slimstat_query_qualification_canary';
$unrelated = '_transientXwpYslimstatZquery_qualification_canary';
foreach ([$owned, $unrelated] as $key) {
    if (null !== $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key))) {
        throw new RuntimeException('Canary already exists; refusing to overwrite it');
    }
}
$admins = get_users(['role' => 'administrator', 'number' => 1]);
if (!$admins) { throw new RuntimeException('Disposable site administrator required'); }
wp_set_current_user($admins[0]->ID);
$_POST['security'] = wp_create_nonce('slimstat_clear_cache');
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', static function () {
    return static function () { throw new SlimstatCacheCanaryExit(); };
});
$proof = [];
try {
    foreach ([$owned, $unrelated] as $key) {
        if (false === $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, 'canary'))) {
            throw new RuntimeException('Could not seed canary');
        }
    }
    ob_start();
    try {
        wp_slimstat_clear_cache_handler();
    } catch (SlimstatCacheCanaryExit $expected) {
        // Capture WordPress's actual JSON response without terminating the control.
    } finally {
        $response = json_decode(ob_get_clean(), true);
    }
    $proof['callback_success'] = $response['success'] ?? false;
    $proof['callback_message'] = $response['data'] ?? null;
    $proof['owned_removed'] = null === $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $owned));
    $proof['near_matching_unrelated_preserved'] = 'canary' === $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $unrelated));
    $proof['mysql_version'] = $wpdb->get_var('SELECT VERSION()');
    echo wp_json_encode($proof, JSON_PRETTY_PRINT) . "\n";
} finally {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $owned, $unrelated));
}
if (empty($proof['callback_success']) || empty($proof['owned_removed']) || empty($proof['near_matching_unrelated_preserved'])) {
    fwrite(STDERR, "FAIL: real callback must delete only its owned cache family\n");
    exit(1);
}
echo "PASS: real authorized cache callback preserves unrelated option families\n";
