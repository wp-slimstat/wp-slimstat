<?php
/** Regression for #395: render the real controller/template, including the retry boundary. */
namespace SlimStat\Migration {
    class MigrationManager {
        public $forgotten = 0;
        public function getRequiredMigrations() { return []; }
        public function getOfferedMigrations() { return []; }
        public function getMigrations() { return []; }
        public function forgetProbe() { $this->forgotten++; }
    }
    class MigrationService { public static function analyticsConnection() { return new \stdClass(); } }
}
namespace SlimStat\Tracker {
    class VisitIdGenerator {
        public static $ready = false;
        public static function isInitialized() { return self::$ready; }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    define('SLIMSTAT_ANALYTICS_VERSION', '6.1.0');
    class wp_slimstat {
        public static $settings = ['version' => '6.0.0'];
        public static function pro_is_installed() { return false; }
    }
    class wp_slimstat_admin {
        public static $retries = 0, $succeeds = false, $drift = [];
        public static function get_template(...$args) {}
        public static function observe_column_drift() { return self::$drift; }
        public static function update_tables_and_options() {
            self::$retries++;
            if (self::$succeeds) {
                \SlimStat\Tracker\VisitIdGenerator::$ready = true;
                wp_slimstat::$settings['version'] = SLIMSTAT_ANALYTICS_VERSION;
            }
            return self::$succeeds;
        }
    }
    function current_user_can($capability) { return $GLOBALS['authorized'] && $capability === 'manage_options'; }
    function check_admin_referer($action) {
        if (($_POST['_wpnonce'] ?? '') !== 'valid' || $action !== 'slimstat_retry_upgrade') { throw new \RuntimeException('nonce denied'); }
    }
    function wp_die($message) { throw new \RuntimeException('permission denied'); }
    function plugin_dir_path($file) { return dirname($file) . '/'; }
    function __($text, $domain = '') { return $text; }
    function esc_html__($text, $domain = '') { return htmlspecialchars($text, ENT_QUOTES); }
    function esc_attr__($text, $domain = '') { return esc_html__($text); }
    function esc_html_e($text, $domain = '') { echo esc_html__($text); }
    function esc_html($text) { return esc_html__($text); }
    function esc_url($text) { return htmlspecialchars($text, ENT_QUOTES); }
    function admin_url($path) { return '/wp-admin/' . $path; }
    function wp_nonce_field($action) { echo '<input name="_wpnonce" value="valid">'; }
    require dirname(__DIR__) . '/src/Migration/Admin/MigrationAdmin.php';
    $manager = new \SlimStat\Migration\MigrationManager();
    $admin = new \SlimStat\Migration\Admin\MigrationAdmin($manager);
    $GLOBALS['authorized'] = true;
    function render($admin) {
        ob_start();
        try { $admin->renderPage(); return ob_get_contents(); } finally { ob_end_clean(); }
    }
    function expect_text($text, $html, $present = true) {
        if ((strpos($html, $text) !== false) !== $present) { throw new \RuntimeException('Unexpected rendered status: ' . $text); }
    }
    $html = render($admin);
    expect_text('Database is up to date', $html, false);
    expect_text('Visit counter initialization has not been verified', $html);
    expect_text('Retry database upgrade', $html);
    if (wp_slimstat_admin::$retries) { throw new \RuntimeException('Read-only diagnosis attempted repair'); }
    $_POST = ['slimstat_retry_upgrade' => '1', '_wpnonce' => 'valid'];
    foreach ([false, false, true] as $success) {
        wp_slimstat_admin::$succeeds = $success;
        $html = render($admin);
        expect_text('Database is up to date', $html, $success);
        expect_text('Retry database upgrade', $html, !$success);
    }
    if ($manager->forgotten !== 3) { throw new \RuntimeException('Retry left cached migration status'); }
    // Stale error records do not drive current health; readiness and version do.
    $_POST = [];
    expect_text('Database is up to date', render($admin));
    wp_slimstat::$settings['version'] = '6.0.0';
    expect_text('Database is up to date', render($admin), false);
    wp_slimstat::$settings['version'] = SLIMSTAT_ANALYTICS_VERSION;
    \SlimStat\Tracker\VisitIdGenerator::$ready = false;
    expect_text('Database is up to date', render($admin), false);
    \SlimStat\Tracker\VisitIdGenerator::$ready = true;
    wp_slimstat_admin::$drift = null;
    expect_text('Database is up to date', render($admin), false);
    foreach ([[false, 'valid'], [true, 'invalid']] as [$authorized, $nonce]) {
        $GLOBALS['authorized'] = $authorized;
        $_POST = ['slimstat_retry_upgrade' => '1', '_wpnonce' => $nonce];
        try { render($admin); throw new \LogicException('Unauthorized retry accepted'); }
        catch (\RuntimeException $e) {
            if (!in_array($e->getMessage(), ['permission denied', 'nonce denied'], true)) { throw $e; }
        }
    }
    if (wp_slimstat_admin::$retries !== 3) { throw new \RuntimeException('Unauthorized retry reached upgrade'); }
    echo "PASS: counter failure, persistent failure, recovery, pending upgrade, unknown schema and nonce/capability boundaries render honestly\n";
}
