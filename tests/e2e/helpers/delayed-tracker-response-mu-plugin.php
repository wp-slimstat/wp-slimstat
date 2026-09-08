<?php
/** Deliberate first-response latency for the isolated rapid-navigation control. */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    return;
}
add_action('slimstat_track_success', static function () {
    $resource = \wp_slimstat::get_stat()['resource'] ?? '';
    if (is_string($resource) && preg_match('/session-delayed-[0-9]+-p1(?:&|$)/', $resource)) {
        $proof = ['resource' => $resource, 'started' => microtime(true)];
        update_option('slimstat_e2e_response_delay', wp_json_encode($proof), false);
        usleep(1000000);
        $proof['finished'] = microtime(true);
        update_option('slimstat_e2e_response_delay', wp_json_encode($proof), false);
    }
});
