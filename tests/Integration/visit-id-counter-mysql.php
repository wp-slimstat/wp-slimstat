<?php
/** Real WordPress wpdb + MySQL counter control; only disposable qualification databases. */
declare(strict_types=1);

$prefix = (string) getenv('SLIMSTAT_COUNTER_TEST_PREFIX');
if ('1' !== getenv('SLIMSTAT_COUNTER_TEST_ALLOW') || !preg_match('/^slimstat_e2e_counter_[a-f0-9]{8}_$/', $prefix)) {
    throw new RuntimeException('Explicit disposable counter test prefix and authorization required.');
}
$mode = $argv[1] ?? 'increment';
if (!in_array($mode, ['setup', 'setup-zero', 'increment', 'legacy', 'external', 'external-zero', 'seed-low', 'failed-max', 'failed-max-legacy', 'cleanup'], true)) {
    throw new RuntimeException('Unknown control mode.');
}
define('ABSPATH', rtrim((string) getenv('WP_ROOT'), '/') . '/');
define('WPINC', 'wp-includes');
define('WP_DEBUG', false);
require ABSPATH . WPINC . '/plugin.php';
function wp_cache_delete($key, $group = '') { unset($GLOBALS['counter_autoload'][$key]); return true; }
function get_current_blog_id() { return 1; }
function update_option($key, $value, $autoload = null) {
    global $wpdb;
    $ok = false !== $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload=VALUES(autoload)", $key, (string) $value, $autoload ? 'yes' : 'no'));
    if ($ok && $autoload) $GLOBALS['counter_autoload'][$key] = $value;
    return $ok;
}
function get_option($key, $default = false) {
    global $wpdb;
    if (isset($GLOBALS['counter_autoload'][$key])) return $GLOBALS['counter_autoload'][$key];
    $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
    return null === $value ? $default : $value;
}
class wp_slimstat {
    public static $wpdb;
    public static $settings = ['version' => '6.0.0'];
    public static function log($message, $level = 'info') {}
    public static function date_i18n($format) { return time(); }
    public static function update_option($key, $value, $autoload = null) { return update_option($key, is_array($value) ? serialize($value) : $value, $autoload); }
}
require ABSPATH . WPINC . '/class-wpdb.php';
$wpdb = new wpdb((string) getenv('MYSQL_USER'), (string) getenv('MYSQL_PASSWORD'), (string) getenv('MYSQL_DATABASE'), (string) getenv('MYSQL_HOST'));
$wpdb->suppress_errors(true); // Deliberate missing-table controls inspect failure without WordPress rendering.
$wpdb->prefix = $prefix;
$wpdb->options = $prefix . 'options';
wp_slimstat::$wpdb = $wpdb;
define('SLIMSTAT_ANALYTICS_VERSION', '6.0.0');
require dirname(__DIR__, 2) . '/src/Tracker/VisitIdGenerator.php';
require dirname(__DIR__, 2) . '/src/Tracker/Utils.php';
$external = null;
if (getenv('MYSQL_EXTERNAL_DATABASE')) {
    $external = new wpdb((string) getenv('MYSQL_USER'), (string) getenv('MYSQL_PASSWORD'), (string) getenv('MYSQL_EXTERNAL_DATABASE'), (string) getenv('MYSQL_EXTERNAL_HOST'));
    $external->suppress_errors(true);
    if (strpos($mode, 'external') === 0) wp_slimstat::$wpdb = $external;
}

if (in_array($mode, ['setup', 'setup-zero'], true)) {
    $seed = 'setup-zero' === $mode ? 0 : 5000000;
    if (false === $wpdb->query("CREATE TABLE {$wpdb->options} (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL) AUTO_INCREMENT=1000") ||
        false === $wpdb->query("CREATE TABLE {$prefix}slim_stats (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, visit_id BIGINT UNSIGNED NOT NULL, KEY(visit_id))") ||
        false === $wpdb->query("INSERT INTO {$prefix}slim_stats (visit_id) VALUES ({$seed})")) {
        throw new RuntimeException($wpdb->last_error);
    }
    if ($external) {
        $externalSeed = 'setup-zero' === $mode ? 0 : 9000000;
        if (false === $external->query("CREATE TABLE {$prefix}slim_stats (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, visit_id BIGINT UNSIGNED NOT NULL, KEY(visit_id))")
            || false === $external->query("INSERT INTO {$prefix}slim_stats (visit_id) VALUES ({$externalSeed})")) throw new RuntimeException($external->last_error);
    }
    echo "fixture ready\n";
} elseif ('seed-low' === $mode) {
    $wpdb->query("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES ('slimstat_visit_id_counter',3,'no')");
    echo "legacy low counter ready\n";
} elseif ('cleanup' === $mode) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->options}, {$prefix}slim_stats");
    if ($external) $external->query("DROP TABLE IF EXISTS {$prefix}slim_stats");
    echo "fixture removed\n";
} else {
    if (in_array($mode, ['legacy', 'failed-max-legacy'], true)) wp_slimstat::$settings['version'] = '5.5.0';
    $failMax = in_array($mode, ['failed-max', 'failed-max-legacy'], true);
    if ($failMax) $wpdb->query("DROP TABLE {$prefix}slim_stats");
    // Mirror WordPress's autoload read before counting the allocation itself.
    $GLOBALS['counter_autoload'] = [];
    foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE autoload='yes'") as $row) $GLOBALS['counter_autoload'][$row->option_name] = $row->option_value;
    $before = $wpdb->num_queries;
    $id = \SlimStat\Tracker\VisitIdGenerator::generateNextVisitId();
    if ($failMax) {
        $counterRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
        if (0 !== $id || ('failed-max' === $mode && 0 !== $counterRows) || ('failed-max-legacy' === $mode && '3' !== $wpdb->get_var("SELECT option_value FROM {$wpdb->options}"))) throw new RuntimeException('Failed MAX seeded or issued an unverified ID.');
        echo json_encode(['refused_id' => $id, 'counter_rows' => $counterRows]), "\n";
        exit(0);
    }
    $minimum = 'external-zero' === $mode ? 0 : ('external' === $mode ? 9000000 : 5000000);
    if ($id <= $minimum || $wpdb->last_error) {
        throw new RuntimeException('Counter reissued a historical ID or failed: ' . $id . ' ' . $wpdb->last_error);
    }
    echo json_encode(['id' => $id, 'queries' => $wpdb->num_queries - $before, 'driver' => get_class($wpdb->dbh), 'server' => $wpdb->db_server_info()]), "\n";
}
