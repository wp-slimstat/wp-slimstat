<?php
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
/** Live MySQL regression; invoked through the browser suite's disposable WordPress. */
if (!defined('DB_NAME') || !preg_match('/^tests?[-_]/', DB_NAME)) {
    throw new RuntimeException('Disposable test database required');
}
$db = wp_slimstat::$wpdb;
$prefix = $GLOBALS['wpdb']->prefix;
$email = 'privacy-' . wp_generate_uuid4() . '@example.test';
$saved = wp_slimstat::$settings;
$saved_user = get_current_user_id();
$saved_detail = get_option('slimstat_tracker_error_detail', '');
$check = static function ($condition, $message) { if (!$condition) { throw new RuntimeException($message); } };
try {
    $check('' === wp_slimstat::get_fresh_defaults()['rest_api_tokens'], 'new installs do not enable report token access');
    $request = new WP_REST_Request('GET', '/slimstat/v1/get');
    $request->set_header('authorization', 'Bearer privacy-test-token');
    wp_slimstat::$settings['rest_api_tokens'] = '';
    $check(true !== wp_slimstat::rest_api_authorization($request), 'empty token configuration denies access');
    wp_slimstat::$settings['rest_api_tokens'] = 'privacy-test-token';
    $check(true === wp_slimstat::rest_api_authorization($request), 'report API accepts an authorization header');
    $request->set_header('authorization', 'Bearer incorrect-token');
    $check(true !== wp_slimstat::rest_api_authorization($request), 'incorrect header token is rejected');
    $check('' === do_shortcode('[slimstat f="recent" w="dt,resource"]username equals privacy-audit[/slimstat]'), 'public columns cannot disclose personally filtered history');
    foreach (['slim_stats', 'slim_events'] as $table) {
        $check(false !== $db->query("CREATE TABLE IF NOT EXISTS {$prefix}{$table}_archive LIKE {$prefix}{$table}"), 'create archive');
    }
    $fields = ['email' => $email, 'ip' => '198.51.100.12', 'other_ip' => '198.51.100.13',
        'resource' => '/private', 'city' => 'Berlin', 'location' => '12,13', 'searchterms' => 'personal search', 'language' => 'de',
        'notes' => 'personal note', 'outbound_resource' => 'https://example.test/path', 'utm_source' => 'newsletter', 'dt' => time()];
    $check((bool) $db->insert($prefix . 'slim_stats_archive', $fields), 'insert archived-only person');
    $id = $db->insert_id;
    for ($i = 0; $i < 1001; $i++) {
        $check((bool) $db->insert($prefix . 'slim_events_archive', ['id' => $id, 'notes' => 'private event', 'dt' => time()]), 'insert archive event');
    }
    $export = \SlimStat\Services\Privacy\DataExporter::exportPageviews($email);
    $check(!is_wp_error($export), 'export succeeds');
    $values = array_column($export['data'][0]['data'], 'value');
    foreach (['198.51.100.13', '12,13', 'personal search', 'de', 'personal note', 'https://example.test/path', 'newsletter'] as $value) {
        $check(in_array($value, $values, true), 'export includes ' . $value);
    }
    $before = get_option('slimstat_data_ver');
    for ($page = 1; $page <= 4; $page++) {
        $result = \SlimStat\Services\Privacy\DataEraser::eraseEvents($email, $page);
        $check(!is_wp_error($result), 'event erasure succeeds');
        if ($result['done']) { break; }
    }
    $check($page === 3, '1001 archived events drain in three batches');
    $check((int) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$prefix}slim_events_archive WHERE id=%d", $id)) === 0, 'no skipped events');
    $result = \SlimStat\Services\Privacy\DataEraser::erasePageviews($email);
    $check(!is_wp_error($result) && $result['done'] && $result['items_removed'], 'archived-only pageview erased');
    $check($before !== get_option('slimstat_data_ver'), 'erasure invalidates report caches');

    $old = wp_slimstat::now() - 31 * DAY_IN_SECONDS;
    $fields['dt'] = $old;
    $check((bool) $db->insert($prefix . 'slim_stats_archive', $fields), 'insert expired archive');
    $expired = $db->insert_id;
    $check((bool) $db->insert($prefix . 'slim_events_archive', ['id' => $expired, 'dt' => $old]), 'insert expired event');
    $fields['dt'] = wp_slimstat::now();
    $check((bool) $db->insert($prefix . 'slim_stats_archive', $fields), 'insert retained archive');
    $retained = $db->insert_id;
    wp_slimstat::$settings['auto_purge'] = 0;
    wp_slimstat::$settings['archive_retention_days'] = 30;
    wp_slimstat::wp_slimstat_purge();
    $check(!$db->get_var($db->prepare("SELECT id FROM {$prefix}slim_stats_archive WHERE id=%d", $expired)), 'expired archive deleted without live-table work');
    $check((int) $db->get_var($db->prepare("SELECT id FROM {$prefix}slim_stats_archive WHERE id=%d", $retained)) === $retained, 'recent archive retained');

    foreach (['slim_events_archive', 'slim_stats_archive'] as $missing) {
        $backup = $prefix . 'privacy_backup_' . bin2hex(random_bytes(4));
        $check(false !== $db->query("RENAME TABLE {$prefix}{$missing} TO {$backup}"), 'simulate one missing archive table');
        try {
            $survivor = 'slim_events_archive' === $missing ? 'slim_stats_archive' : 'slim_events_archive';
            $check((bool) $db->insert($prefix . $survivor, ['dt' => $old]), 'insert expired surviving archive');
            $key = 'slim_stats_archive' === $survivor ? 'id' : 'event_id';
            $expired_id = $db->insert_id;
            wp_slimstat::wp_slimstat_purge();
            $check(!$db->get_var($db->prepare("SELECT {$key} FROM {$prefix}{$survivor} WHERE {$key}=%d", $expired_id)), 'partial archive retention removes expired row');
        } finally {
            $check(false !== $db->query("RENAME TABLE {$backup} TO {$prefix}{$missing}"), 'restore archive table');
        }
    }

    // Exercise database diagnostics with a real duplicate value, not a manufactured message.
    $temp = $prefix . 'privacy_error_' . bin2hex(random_bytes(4));
    $check(false !== $db->query("CREATE TEMPORARY TABLE {$temp} (email varchar(191) UNIQUE)"), 'create error fixture');
    $db->insert($temp, ['email' => $email]);
    $suppressed = $db->suppress_errors(true);
    $db->insert($temp, ['email' => $email]);
    $detail = $db->last_error;
    $db->suppress_errors($suppressed);
    $check(strpos($detail, $email) !== false, 'database error contains personal value');
    update_option('slimstat_tracker_error_detail', $detail, false);
    \SlimStat\Tracker\Utils::sanitizeStoredErrorDetail();
    $check('duplicate_key' === get_option('slimstat_tracker_error_detail'), 'legacy diagnostic drops personal value');
    \SlimStat\Tracker\Utils::recordErrorDetail($detail);
    $check('duplicate_key' === get_option('slimstat_tracker_error_detail'), 'new diagnostic keeps only fixed code');
    $db->query("DROP TEMPORARY TABLE {$temp}");

    $raw = $db->get_results($db->prepare("SELECT *, 7 AS counthits FROM {$prefix}slim_stats_archive WHERE id=%d", $retained), ARRAY_A);
    $raw[0]['username'] = 'private-visitor';
    wp_slimstat::$settings['mask_personal_data'] = 'on';
    wp_set_current_user(0);
    $args = ['raw' => static function () use ($raw) { return $raw; }];
    $masked = \SlimStat\Reports\ReportData::rawData($args);
    foreach ([$email, '198.51.100.12', '198.51.100.13', 'private-visitor', 'personal note', '12,13'] as $secret) {
        $check(strpos(wp_json_encode($masked), $secret) === false, 'masked report excludes ' . $secret);
    }
    $check($raw[0]['counthits'] === $masked[0]['counthits'] && $raw[0]['dt'] === $masked[0]['dt'] && 'Berlin' === $masked[0]['city'], 'masking preserves exact counts, dates and geographic report dimensions');
    $admin = get_user_by('login', 'admin');
    wp_set_current_user($admin->ID);
    $check($raw === \SlimStat\Reports\ReportData::rawData($args), 'administrator still sees original report');
    wp_set_current_user(0);
    wp_slimstat::$settings['mask_personal_data'] = 'off';
    $check($raw === \SlimStat\Reports\ReportData::rawData($args), 'explicit masking opt-out preserves existing reports');
    wp_set_current_user($saved_user);

    \SlimStat\Heatmap\Store::setup();
    $fields['resource'] = '/privacy-heatmap';
    $check((bool) $db->insert($prefix . 'slim_stats', $fields), 'insert heatmap parent');
    $live = $db->insert_id;
    $selector = random_bytes(8);
    $orphan = random_bytes(8);
    $check((bool) $db->insert($prefix . 'slim_heatmap_elements', ['sel' => $selector, 'selector' => '#private', 'label' => 'Personal label']), 'insert personal label');
    $check((bool) $db->insert($prefix . 'slim_heatmap_elements', ['sel' => $orphan, 'selector' => '#orphan', 'label' => 'Expired label']), 'insert orphan label');
    $check((bool) $db->insert($prefix . 'slim_heatmap', ['id' => $live, 'page' => random_bytes(8), 'sel' => $selector, 'dt' => wp_slimstat::now()]), 'insert heatmap click');
    wp_slimstat::$settings['auto_purge'] = 30;
    \SlimStat\Heatmap\Store::purge();
    $check(!$db->get_var($db->prepare("SELECT label FROM {$prefix}slim_heatmap_elements WHERE sel=%s", $orphan)), 'retention removes orphaned labels');
    $check('Personal label' === $db->get_var($db->prepare("SELECT label FROM {$prefix}slim_heatmap_elements WHERE sel=%s", $selector)), 'retention keeps referenced labels');
    \SlimStat\Heatmap\Store::erase($email);
    $check(!$db->get_var($db->prepare("SELECT label FROM {$prefix}slim_heatmap_elements WHERE sel=%s", $selector)), 'personal erasure removes labels too');
    echo "PASS: complete export, archived-only erasure, 1001-event paging, cache invalidation, independent archive retention and heatmap label cleanup\n";
} finally {
    wp_slimstat::$settings = $saved;
    wp_set_current_user($saved_user);
    update_option('slimstat_tracker_error_detail', $saved_detail, false);
    \SlimStat\Services\Privacy\DataEraser::eraseEvents($email);
    \SlimStat\Services\Privacy\DataEraser::erasePageviews($email);
}
