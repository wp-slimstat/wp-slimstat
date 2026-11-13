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
$saved_cookies = $_COOKIE;
$saved_stat = wp_slimstat::get_stat();
$saved_data_js = wp_slimstat::get_data_js();
$saved_user = get_current_user_id();
$saved_heatmap = get_option(\SlimStat\Heatmap\Store::STATE, []);
$saved_purge = get_option(wp_slimstat::LAST_PURGE_OK_OPTION, 0);
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
    wp_slimstat::$settings['mask_personal_data'] = 'off';
    foreach (['notes', 'location'] as $personal) {
        $check('' === do_shortcode('[slimstat f="count"]' . $personal . ' equals private[/slimstat]'), 'public count cannot probe ' . $personal);
    }
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
    $raw[0]['column_group'] = 'secret-group';
    $raw[0]['outbound_resource'] = '/first?_wpnonce=secret&keep=one;;;/second?token=secret&keep=two';
    wp_slimstat::$settings['mask_personal_data'] = 'on';
    wp_set_current_user(0);
    $args = ['column_group' => 'username', 'raw' => static function () use ($raw) { return $raw; }];
    $masked = \SlimStat\Reports\ReportData::rawData($args);
    foreach ([$email, '198.51.100.12', '198.51.100.13', 'private-visitor', 'personal note', '12,13'] as $secret) {
        $check(strpos(wp_json_encode($masked), $secret) === false, 'masked report excludes ' . $secret);
    }
    $check($raw[0]['counthits'] === $masked[0]['counthits'] && $raw[0]['dt'] === $masked[0]['dt'] && 'Berlin' === $masked[0]['city'], 'masking preserves exact counts, dates and geographic report dimensions');
    $check('' === $masked[0]['column_group'], 'raw grouped identities are masked');
    $check('/first?keep=one;;;/second?keep=two' === $masked[0]['outbound_resource'], 'redaction preserves multiple outbound links');
    require_once dirname(__DIR__) . '/admin/view/wp-slimstat-db.php';
    wp_slimstat::$settings['geolocation_country'] = 'off';
    foreach (['username', 'notes', 'location', 'ip'] as $personal) {
        wp_slimstat_db::init($personal . ' equals secret-group', false, false);
        $check(isset(wp_slimstat_db::$filters_normalized['columns'][$personal]), 'personal filter remains serializable');
        $check(strpos(wp_slimstat_db::get_combined_where('', '', false, 't1'), '1=0') !== false, 'personal report filter denies results');
        $scope = \SlimStat\Controllers\Rest\HeatmapRestController::filters($personal . ' equals secret-group');
        $check('1=0' === $scope['scope'], 'heatmap personal filter cannot become an unfiltered request');
    }

    require_once dirname(__DIR__) . '/admin/view/wp-slimstat-reports.php';
    foreach (['on', 'off'] as $mask) {
        wp_slimstat::$settings['mask_personal_data'] = $mask;
        foreach (['top', 'recent'] as $mode) {
            $event = ['notes' => '{"text":"private-event-label"}', 'counthits' => 7];
            if ('recent' === $mode) { $event += ['dt' => wp_slimstat::now(), 'ip' => '198.51.100.44', 'resource' => '/event-page', 'position' => '1,2']; }
            ob_start();
            wp_slimstat_reports::show_events(['type' => $mode, 'raw' => static function () use ($event) { return [$event]; }]);
            $event_html = ob_get_clean();
            $check(('off' === $mask) === (strpos($event_html, 'private-event-label') !== false), $mode . ' event labels honor report masking');
            if ('recent' === $mode) { $check(('off' === $mask) === (strpos($event_html, '198.51.100.44') !== false), 'recent event IP respects masking'); }
            $check(strpos($event_html, '<span>7</span>') !== false, $mode . ' masked event count remains exact');
        }
    }
    wp_slimstat::$settings['mask_personal_data'] = 'on';
    $admin = get_user_by('login', 'admin');
    wp_set_current_user($admin->ID);
    $check($raw === \SlimStat\Reports\ReportData::rawData($args), 'administrator still sees original report');
    wp_set_current_user(0);
    wp_slimstat::$settings['mask_personal_data'] = 'off';
    $check($raw === \SlimStat\Reports\ReportData::rawData($args), 'explicit masking opt-out preserves existing reports');
    wp_set_current_user($saved_user);


    // Real direct endpoints must detach only the signed current anonymous pageview.
    wp_slimstat::$settings = array_merge(wp_slimstat::$settings, ['gdpr_enabled' => 'on', 'anonymous_tracking' => 'on',
        'set_tracker_cookie' => 'on', 'use_slimstat_banner' => 'on', 'consent_integration' => 'slimstat_banner']);
    wp_set_current_user($admin->ID);
    foreach (['banner', 'rest'] as $route) {
        unset($_COOKIE['slimstat_tracking_code']);
        $_COOKIE['slimstat_gdpr_consent'] = 'denied';
        $anonymous = ['email' => $email, 'visit_id' => 991234, 'vid_hash' => random_bytes(16), 'dt' => wp_slimstat::now(), 'resource' => '/consent-direct-' . $route];
        $check((bool) $db->insert($prefix . 'slim_stats', $anonymous), 'insert prior anonymous pageview');
        $prior_id = $db->insert_id;
        $check((bool) $db->insert($prefix . 'slim_stats', $anonymous), 'insert current anonymous pageview');
        $current_id = $db->insert_id;
        $signed = $current_id . '.' . hash_hmac('sha256', (string) $current_id, wp_slimstat::$settings['secret']);
        $grant = static function () use ($route, $signed, $check) {
            wp_slimstat::set_stat([]);
            if ('banner' === $route) {
                $check(true === \SlimStat\Services\Privacy\ConsentHandler::handleBannerConsent(false, ['nonce' => wp_create_nonce('wp_rest'), 'consent' => 'accepted', 'pageview_id' => $signed]), 'banner consent succeeds');
            } else {
                $_COOKIE['slimstat_gdpr_consent'] = 'accepted';
                wp_cache_set('slimstat_consent_state_' . md5($signed), ['statistics' => 'deny'], 'slimstat');
                $request = new WP_REST_Request('POST', '/slimstat/v1/consent-change');
                foreach (['nonce' => wp_create_nonce('wp_rest'), 'source' => 'slimstat_banner', 'parsed' => ['statistics' => 'allow'], 'pageview_id' => $signed] as $key => $value) { $request->set_param($key, $value); }
                $response = (new \SlimStat\Controllers\Rest\ConsentChangeRestController())->handle_consent_change($request);
                $check(!is_wp_error($response) && 200 === $response->get_status(), 'REST consent succeeds');
            }
        };
        try {
            $grant();
            $prior = $db->get_row($db->prepare("SELECT * FROM {$prefix}slim_stats WHERE id=%d", $prior_id), ARRAY_A);
            $current = $db->get_row($db->prepare("SELECT * FROM {$prefix}slim_stats WHERE id=%d", $current_id), ARRAY_A);
            $check(991234 === (int) $prior['visit_id'] && $anonymous['vid_hash'] === $prior['vid_hash'] && empty($prior['username']), $route . ' leaves prior anonymous row untouched');
            $check(991234 !== (int) $current['visit_id'] && (int) $current['visit_id'] > 0 && null === $current['vid_hash'], $route . ' detaches current visit and anonymous hash');
            $check($admin->user_login === $current['username'], $route . ' enriches the current pageview only');
            $check((int) wp_slimstat::get_stat()['visit_id'] === (int) $current['visit_id'], $route . ' exposes the new session for its tracking cookie');
            $grant();
            $check((int) $db->get_var($db->prepare("SELECT visit_id FROM {$prefix}slim_stats WHERE id=%d", $current_id)) === (int) $current['visit_id'], $route . ' repeated grant keeps session stable');
            $_COOKIE['slimstat_tracking_code'] = $current['visit_id'] . '.' . hash_hmac('sha256', (string) $current['visit_id'], wp_slimstat::$settings['secret']);
            wp_slimstat::set_stat(['dt' => wp_slimstat::now(), 'resource' => '/next-consented-page', 'notes' => []]);
            wp_slimstat::set_data_js([]);
            \SlimStat\Tracker\Session::ensureVisitId(true);
            $check((int) wp_slimstat::get_stat()['visit_id'] === (int) $current['visit_id'], $route . ' next consented navigation keeps the new session');
        } finally {
            $db->delete($prefix . 'slim_stats', ['id' => $prior_id]);
            $db->delete($prefix . 'slim_stats', ['id' => $current_id]);
        }
    }
    $_COOKIE = $saved_cookies;
    wp_set_current_user($saved_user);

    \SlimStat\Heatmap\Store::setup();
    update_option(\SlimStat\Heatmap\Store::STATE, ['schema' => 1, 'error' => '']);
    wp_slimstat::$settings['heatmap_capture'] = 'off';
    $check(\SlimStat\Heatmap\Store::ready(), 'legacy heatmaps remain available with capture disabled');
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
    $heatmap_export = \SlimStat\Heatmap\Store::exportPersonalData($email);
    $check(!is_wp_error($heatmap_export) && strpos(wp_json_encode($heatmap_export), 'Personal label') !== false, 'legacy heatmaps are included in personal exports');
    \SlimStat\Heatmap\Store::erase($email);
    $check(!$db->get_var($db->prepare("SELECT label FROM {$prefix}slim_heatmap_elements WHERE sel=%s", $selector)), 'personal erasure removes labels too');
    // An archive failure must not refresh retention health, even without live work.
    $last_ok = wp_slimstat::now() - 3 * wp_slimstat::PURGE_INTERVAL;
    update_option(wp_slimstat::LAST_PURGE_OK_OPTION, $last_ok, false);
    $break_archive = static function ($sql) {
        return strpos($sql, 'SHOW TABLES LIKE') === 0 && strpos($sql, 'archive') !== false ? 'SELECT * FROM missing_archive_privacy_fixture' : $sql;
    };
    add_filter('query', $break_archive);
    $suppressed = $db->suppress_errors(true);
    try {
        foreach ([0, 999999] as $live_retention) {
            wp_slimstat::$settings['auto_purge'] = $live_retention;
            wp_slimstat::wp_slimstat_purge();
            $check((int) get_option(wp_slimstat::LAST_PURGE_OK_OPTION) === $last_ok, 'failed archive cleanup cannot report success');
            $check(wp_slimstat::purge_is_stale(), 'failed archive-only retention remains visible');
        }
    } finally { remove_filter('query', $break_archive); $db->suppress_errors($suppressed); }
    wp_slimstat::wp_slimstat_purge();
    $check((int) get_option(wp_slimstat::LAST_PURGE_OK_OPTION) > $last_ok, 'successful archive cleanup restores health');
    echo "PASS: complete export, archived-only erasure, 1001-event paging, cache invalidation, independent archive retention and heatmap label cleanup\n";
} finally {
    update_option(\SlimStat\Heatmap\Store::STATE, $saved_heatmap);
    update_option(wp_slimstat::LAST_PURGE_OK_OPTION, $saved_purge, false);
    $_COOKIE = $saved_cookies;
    wp_slimstat::set_stat($saved_stat);
    wp_slimstat::set_data_js($saved_data_js);
    wp_slimstat::$settings = $saved;
    wp_set_current_user($saved_user);
    update_option('slimstat_tracker_error_detail', $saved_detail, false);
    \SlimStat\Services\Privacy\DataEraser::eraseEvents($email);
    \SlimStat\Services\Privacy\DataEraser::erasePageviews($email);
}
