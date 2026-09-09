<?php
/** Real WordPress wpdb + MySQL counter control; only disposable qualification databases. */
declare(strict_types=1);

$prefix = (string) getenv('SLIMSTAT_COUNTER_TEST_PREFIX');
if ('1' !== getenv('SLIMSTAT_COUNTER_TEST_ALLOW') || !preg_match('/^slimstat_e2e_counter_[a-f0-9]{8}_$/', $prefix)) {
    throw new RuntimeException('Explicit disposable counter test prefix and authorization required.');
}
$mode = $argv[1] ?? 'increment';
if (!in_array($mode, ['setup', 'increment', 'legacy', 'seed-low', 'failed-max', 'failed-max-legacy', 'cleanup'], true)) {
    throw new RuntimeException('Unknown control mode.');
}
define('ABSPATH', rtrim((string) getenv('WP_ROOT'), '/') . '/');
define('WPINC', 'wp-includes');
define('WP_DEBUG', false);
require ABSPATH . WPINC . '/plugin.php';
function wp_cache_delete($key, $group = '') { return true; }
function get_option($key, $default = false) {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
    return null === $value ? $default : $value;
}
class wp_slimstat {
    public static $wpdb;
    public static $settings = ['version' => '6.0.0'];
    public static function log($message, $level = 'info') {}
}
require ABSPATH . WPINC . '/class-wpdb.php';
$wpdb = new wpdb((string) getenv('MYSQL_USER'), (string) getenv('MYSQL_PASSWORD'), (string) getenv('MYSQL_DATABASE'), (string) getenv('MYSQL_HOST'));
$wpdb->suppress_errors(true); // Deliberate missing-table controls inspect failure without WordPress rendering.
$wpdb->prefix = $prefix;
$wpdb->options = $prefix . 'options';
wp_slimstat::$wpdb = $wpdb;
define('SLIMSTAT_ANALYTICS_VERSION', '6.0.0');
require dirname(__DIR__, 2) . '/src/Tracker/VisitIdGenerator.php';

if ('setup' === $mode) {
    if (false === $wpdb->query("CREATE TABLE {$wpdb->options} (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL) AUTO_INCREMENT=1000") ||
        false === $wpdb->query("CREATE TABLE {$prefix}slim_stats (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, visit_id BIGINT UNSIGNED NOT NULL, KEY(visit_id))") ||
        false === $wpdb->query("INSERT INTO {$prefix}slim_stats (visit_id) VALUES (5000000)")) {
        throw new RuntimeException($wpdb->last_error);
    }
    echo "fixture ready\n";
} elseif ('seed-low' === $mode) {
    $wpdb->query("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES ('slimstat_visit_id_counter',3,'no')");
    echo "legacy low counter ready\n";
} elseif ('cleanup' === $mode) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->options}, {$prefix}slim_stats");
    echo "fixture removed\n";
} else {
    if (in_array($mode, ['legacy', 'failed-max-legacy'], true)) wp_slimstat::$settings['version'] = '5.5.0';
    $failMax = in_array($mode, ['failed-max', 'failed-max-legacy'], true);
    if ($failMax) $wpdb->query("DROP TABLE {$prefix}slim_stats");
    $before = $wpdb->num_queries;
    $id = \SlimStat\Tracker\VisitIdGenerator::generateNextVisitId();
    if ($failMax) {
        $counterRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
        if (0 !== $id || ('failed-max' === $mode && 0 !== $counterRows) || ('failed-max-legacy' === $mode && '3' !== $wpdb->get_var("SELECT option_value FROM {$wpdb->options}"))) throw new RuntimeException('Failed MAX seeded or issued an unverified ID.');
        echo json_encode(['refused_id' => $id, 'counter_rows' => $counterRows]), "\n";
        exit(0);
    }
    if ($id <= 5000000 || $wpdb->last_error) {
        throw new RuntimeException('Counter reissued a historical ID or failed: ' . $id . ' ' . $wpdb->last_error);
    }
    echo json_encode(['id' => $id, 'queries' => $wpdb->num_queries - $before, 'driver' => get_class($wpdb->dbh), 'server' => $wpdb->db_server_info()]), "\n";
}
