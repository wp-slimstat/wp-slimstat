<?php
/** Real SQL checks for review findings: aggregate accuracy, session races and erasure. */
if (PHP_SAPI !== 'cli' || !defined('DB_NAME') || !preg_match('/^tests?[-_]/', DB_NAME)) { throw new RuntimeException('Disposable test database required'); }
$db = wp_slimstat::$wpdb;
if ($db->get_var('SELECT DATABASE()') !== DB_NAME) { throw new RuntimeException('Disposable analytics database required'); }
if (!class_exists('wp_slimstat_db')) { require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php'; }
$saved = wp_slimstat::$settings;
$user = get_current_user_id();
$cookies = $_COOKIE;
$stat = wp_slimstat::get_stat();
$data = wp_slimstat::get_data_js();
$heatmap = get_option(\SlimStat\Heatmap\Store::STATE, []);
$prefix = $GLOBALS['wpdb']->prefix;
$tag = 'privacy-review-' . wp_generate_uuid4();
$ids = [];
$failures = [];
$check = static function ($ok, $message) use (&$failures) { if (!$ok) { $failures[] = $message; } };
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
try {
    wp_slimstat::$settings = array_merge($saved, ['mask_personal_data' => 'on', 'restrict_authors_view' => 'off', 'geolocation_country' => 'off', 'gdpr_enabled' => 'on', 'anonymous_tracking' => 'on', 'use_slimstat_banner' => 'on', 'consent_integration' => 'slimstat_banner', 'do_not_track' => 'off', 'auto_purge' => 0]);
    $row = ['dt' => wp_slimstat::now() - 5, 'resource' => '/' . $tag, 'username' => $tag, 'email' => $tag . '@example.test', 'visit_id' => 992123, 'browser_type' => 0];
    if (!$db->insert($prefix . 'slim_stats', $row)) { throw new RuntimeException($db->last_error); }
    $id = $ids[] = $db->insert_id;
    if (!$db->insert($prefix . 'slim_events', ['id' => $id, 'dt' => wp_slimstat::now() - 4, 'notes' => $tag])) { throw new RuntimeException($db->last_error); }
    $event = ['id' => $tag, 'name' => 'Event', 'dimension' => 'event_notes', 'operator' => 'equals', 'value' => $tag];
    $resource = ['id' => $tag . '-resource', 'name' => 'Page', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/' . $tag];
    $funnel = ['steps' => [$resource, $event]];
    wp_set_current_user(0);
    wp_slimstat_db::init('resource equals /' . $tag, false, false);
    $check(1 === wp_slimstat_db::get_goal_results($event)['total'], 'masked viewer retains the exact stored event-goal count');
    $steps = wp_slimstat_db::get_funnel_results($funnel);
    $check([1, 1] === array_map('intval', array_column($steps, 'visitors')), 'masked viewer retains both funnel step counts');
    // Same personal filter, same scope, both cache fill orders. Stored rules stay authorized.
    foreach ([[$admin->ID, 0], [0, $admin->ID]] as $order => $viewers) {
        $resource['id'] = $tag . '-cache-' . $order;
        $funnel['steps'][0]['name'] = 'Page ' . $order;
        foreach ($viewers as $viewer) {
            wp_set_current_user($viewer);
            wp_slimstat_db::init('username equals ' . $tag, false, false);
            $expected = $viewer ? 1 : 0;
            $chart_filter = new ReflectionMethod(\SlimStat\Modules\Chart::class, 'buildFilterWhere');
            $chart_filter->setAccessible(true);
            $check(($viewer ? false : true) === (strpos($chart_filter->invoke(new \SlimStat\Modules\Chart()), '1=0') !== false), 'chart personal filters preserve the masking boundary');
            $check($expected === wp_slimstat_db::get_goal_results($resource)['total'], 'goal cache isolates masking in fill order ' . $order . ' viewer ' . $viewer);
            $steps = wp_slimstat_db::get_funnel_results($funnel);
            $check([$expected, $expected] === array_map('intval', array_column($steps, 'visitors')), 'funnel cache isolates masking in fill order ' . $order . ' viewer ' . $viewer);
        }
    }

    // Let an ordinary anonymous request prepare its session before the consent write.
    wp_set_current_user(0);
    $_COOKIE = ['slimstat_gdpr_consent' => 'denied'];
    wp_slimstat::set_data_js([]);
    wp_slimstat::set_stat(['ip' => '198.51.100.73', 'dt' => wp_slimstat::now(), 'notes' => []]);
    \SlimStat\Tracker\Session::ensureVisitId(true);
    $stale = wp_slimstat::get_stat();
    $old_visit = (int) $stale['visit_id'];
    foreach (['earlier', 'current'] as $which) {
        if (!$db->insert($prefix . 'slim_stats', ['visit_id' => $old_visit, 'vid_hash' => hex2bin($stale['vid_hash']), 'dt' => wp_slimstat::now(), 'resource' => '/' . $tag . '-' . $which])) { throw new RuntimeException($db->last_error); }
        $ids[] = $db->insert_id;
    }
    $current = end($ids);
    $_COOKIE['slimstat_gdpr_consent'] = 'accepted';
    $new_visit = \SlimStat\Tracker\Session::upgradePageview($current, ['username' => $tag, 'email' => $row['email']]);
    $check($new_visit > 0 && $new_visit !== $old_visit, 'consent detaches the current pageview');
    $export = wp_json_encode(\SlimStat\Services\Privacy\DataExporter::exportPageviews($row['email']));
    $check(strpos($export, '[consent:accepted:') === false && strpos($export, '[consent:accepted]') !== false, 'privacy export keeps the consent annotation without its internal proof');
    $check(strpos($export, $row['email']) !== false, 'privacy exports still include the verified visitor identity when report masking is enabled');
    \SlimStat\Tracker\Storage::updateRow(['id' => $current, 'visit_id' => $old_visit, 'dt_out' => wp_slimstat::now()]);
    $actual = $db->get_row($db->prepare("SELECT visit_id,username,email,dt_out FROM {$prefix}slim_stats WHERE id=%d", $current), ARRAY_A);
    $check($new_visit === (int) $actual['visit_id'] && $tag === $actual['username'] && $row['email'] === $actual['email'], 'in-flight anonymous follow-up cannot reattach identified history');
    $check((int) $actual['dt_out'] > 0, 'protected ordinary follow-up still records duration');
    $check($old_visit === (int) $db->get_var($db->prepare("SELECT visit_id FROM {$prefix}slim_stats WHERE id=%d", $ids[1])), 'earlier anonymous session remains separate');

    // The actual eraser must remove a label inserted between its two delete passes,
    // even with automatic retention disabled.
    \SlimStat\Heatmap\Store::setup();
    $selector = random_bytes(8);
    $db->insert($prefix . 'slim_heatmap_elements', ['sel' => $selector, 'selector' => '#review', 'label' => $tag]);
    $db->insert($prefix . 'slim_heatmap', ['id' => $id, 'page' => random_bytes(8), 'sel' => $selector, 'dt' => wp_slimstat::now()]);
    $race = null;
    $race = static function ($sql) use (&$race, $db, $prefix, $selector, $tag) {
        if (strpos($sql, "DELETE h FROM {$prefix}slim_heatmap h") === 0) {
            remove_filter('query', $race);
            $db->replace($prefix . 'slim_heatmap_elements', ['sel' => $selector, 'selector' => '#review', 'label' => $tag]);
        }
        return $sql;
    };
    add_filter('query', $race);
    try { \SlimStat\Heatmap\Store::erase($row['email']); } finally { remove_filter('query', $race); }
    $check(!$db->get_var($db->prepare("SELECT label FROM {$prefix}slim_heatmap_elements WHERE sel=%s", $selector)), 'erasure cleans concurrent orphan label with retention disabled');
    // Also finish erasure before a paused ingest writes its dictionary entry.
    wp_slimstat::$settings['heatmap_capture'] = 'full';
    wp_slimstat::$settings['heatmap_rate'] = 10000;
    wp_slimstat::$settings['heatmap_pages'] = '';
    add_filter('slimstat_heatmap_row_url', '__return_true');
    $late_label = 'Late ' . substr($tag, -20);
    $race = static function ($sql) use (&$race, $db, $prefix, $id) {
        if (strpos($sql, "INSERT IGNORE INTO {$prefix}slim_heatmap_elements") === 0) {
            remove_filter('query', $race);
            $db->delete($prefix . 'slim_heatmap', ['id' => $id]);
        }
        return $sql;
    };
    add_filter('query', $race);
    try {
        \SlimStat\Heatmap\Store::ingest($id, wp_json_encode(['v' => 2, 's' => [['#review-late', $late_label]], 'r' => [[0, \SlimStat\Heatmap\Store::FLAG_INTERACTIVE, 0, 1, 1, 1, 1]]]));
        $check(!$db->get_var($db->prepare("SELECT label FROM {$prefix}slim_heatmap_elements WHERE label=%s", $late_label)), 'late ingest cannot recreate an erased orphan label');
        $written = \SlimStat\Heatmap\Store::ingest($id, wp_json_encode(['v' => 2, 's' => [['#' . $tag . '-a', $late_label], ['#' . $tag . '-b', $late_label]], 'r' => [[0, \SlimStat\Heatmap\Store::FLAG_INTERACTIVE, 0, 1, 1, 1, 1], [1, \SlimStat\Heatmap\Store::FLAG_INTERACTIVE, 1, 2, 2, 2, 2]]]));
        $check(2 === $written && 2 === (int) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$prefix}slim_heatmap_elements WHERE label=%s", $late_label)), 'ordinary multi-selector heatmap ingestion retains exact rows and labels');
    } finally {
        remove_filter('query', $race);
        remove_filter('slimstat_heatmap_row_url', '__return_true');
        $db->delete($prefix . 'slim_heatmap_elements', ['label' => $late_label]);
    }
    $break_labels = static function ($sql) use ($prefix) {
        return strpos($sql, "DELETE FROM {$prefix}slim_heatmap_elements WHERE NOT EXISTS") === 0 ? 'SELECT * FROM missing_privacy_label_fixture' : $sql;
    };
    $suppressed = $db->suppress_errors(true);
    add_filter('query', $break_labels);
    $failed = false;
    try { \SlimStat\Heatmap\Store::erase($row['email']); } catch (RuntimeException $error) { $failed = true; }
    finally { remove_filter('query', $break_labels); $db->suppress_errors($suppressed); }
    $check($failed, 'failed orphan cleanup cannot report completed erasure');
} finally {
    foreach ($ids as $id) {
        $db->delete($prefix . 'slim_events', ['id' => $id]);
        $db->delete($prefix . 'slim_stats', ['id' => $id]);
    }
    if (isset($selector)) { $db->delete($prefix . 'slim_heatmap_elements', ['sel' => $selector]); }
    wp_slimstat::$settings = $saved;
    wp_set_current_user($user);
    $_COOKIE = $cookies;
    wp_slimstat::set_stat($stat);
    wp_slimstat::set_data_js($data);
    update_option(\SlimStat\Heatmap\Store::STATE, $heatmap, false);
}
if ($failures) { throw new RuntimeException(implode("\n", $failures)); }
echo "PASS: exact masked goal/funnel totals, cache isolation, in-flight anonymous update and concurrent heatmap erasure\n";
