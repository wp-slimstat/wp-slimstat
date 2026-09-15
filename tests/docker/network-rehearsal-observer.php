<?php
/** Test instrumentation only: delay between completed sites and pause at a durable boundary. */
if (!defined('SLIMSTAT_NETWORK_REHEARSAL') || 'disposable' !== SLIMSTAT_NETWORK_REHEARSAL) { return; }
add_action('update_site_option', static function ($option, $value) {
    if ('slimstat_network_activation_pending' !== $option || !is_array($value)) { return; }
    file_put_contents('/tmp/network-progress.jsonl', json_encode(['pid' => getmypid(), 'pending' => array_values($value), 'at' => microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);
    if (is_file('/tmp/network-kill-target.json')) {
        $target = json_decode(file_get_contents('/tmp/network-kill-target.json'), true);
        if (count($value) <= $target['remaining']) {
            file_put_contents('/tmp/network-paused.json', json_encode(['pid' => getmypid(), 'pending' => array_values($value), 'at' => microtime(true)]));
            // The host kills this actual Apache worker. No exit/exception simulates termination.
            while (true) { usleep(100000); }
        }
    }
    // Deterministic pressure on the real ten-second budget, without slowing a site's DDL.
    usleep(250000);
}, 10, 2);
add_filter('flush_rewrite_rules_hard', static function ($hard) {
    file_put_contents('/tmp/network-flushes.jsonl', json_encode(['blog' => get_current_blog_id(), 'hard' => $hard, 'at' => microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);
    return $hard;
});
add_action('admin_footer', static function () {
    if (is_network_admin() && current_user_can('manage_network')) {
        echo '<!-- NETWORK-ADMIN:' . json_encode(['pending' => count((array) get_site_option('slimstat_network_activation_pending', []))]) . ' -->';
    }
});
