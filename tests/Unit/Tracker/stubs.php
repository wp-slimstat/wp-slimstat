<?php
/**
 * Minimal stubs for Tracker unit tests.
 *
 * Provides just enough scaffolding for SlimStat\Tracker\* classes to load
 * without a live WordPress or database connection.
 */
declare(strict_types=1);

// ── WordPress functions needed by source files ─────────────────────────────
// Seedable, because it is declared here as a real function and therefore cannot be
// redefined by Brain Monkey — Patchwork refuses with DefinedTooEarly. A test that
// needs to control an option writes $GLOBALS['slimstat_test_options'][$key] and
// clears it in tearDown. An empty store returns $default, so every test that
// predates this sees exactly the old behaviour.
if (!isset($GLOBALS['slimstat_test_options'])) {
    $GLOBALS['slimstat_test_options'] = [];
}
if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return $GLOBALS['slimstat_test_options'][$option] ?? $default;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($option)
    {
        // Mirrors get_option()'s store so a test can observe a deletion.
        unset($GLOBALS['slimstat_test_options'][$option]);
        return true;
    }
}

// ── WordPress constants needed by source files ────────────────────────────
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('COOKIEHASH')) {
    define('COOKIEHASH', 'test');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'test_db');
}
if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', str_repeat('a', 64));
}
if (!defined('SLIMSTAT_ANALYTICS_DIR')) {
    define('SLIMSTAT_ANALYTICS_DIR', dirname(__DIR__, 3) . '/');
}
// wpdb result-format constants (wp-includes/wp-db.php).
foreach (['OBJECT', 'OBJECT_K', 'ARRAY_A', 'ARRAY_N'] as $slimstat_wpdb_const) {
    if (!defined($slimstat_wpdb_const)) {
        define($slimstat_wpdb_const, $slimstat_wpdb_const);
    }
}
unset($slimstat_wpdb_const);

// ── wp_slimstat global stub ───────────────────────────────────────────────
if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        /** @var string */
        public static string $upload_dir = '/tmp/fake-browscap';

        /** @var object|null Stand-in for the WP $wpdb handle (set by tests that need it). */
        public static $wpdb = null;

        /**
         * Degradations recorded during a test, keyed by step.
         *
         * Tests that assert "this failure leaves a trace" read this. Whether the real
         * recorder persists and surfaces correctly is pinned separately by
         * tests/failsoft-visibility-test.php; here the property under test is only
         * that the failing code path reports itself at all.
         *
         * @var array<string,string>
         */
        public static array $degradations = [];

        public static function record_degradation($step, $e): void
        {
            self::$degradations[$step] = $e instanceof \Throwable ? $e->getMessage() : (string) $e;
        }

        /** @var array<string,mixed> */
        public static array $settings = [
            'enable_browscap'          => 'off',
            'anonymous_tracking'       => 'off',
            'gdpr_enabled'             => 'off',   // GDPR off → tracking always allowed
            'javascript_mode'          => 'off',
            'session_duration'         => 1800,
            'set_tracker_cookie'       => 'off',
            'anonymize_ip'             => 'off',
            'hash_ip'                  => 'off',
            'ignore_ip'                => '',
            'ignore_resources'         => '',
            'ignore_referers'          => '',
            'ignore_content_types'     => '',
            'ignore_browsers'          => '',
            'ignore_platforms'         => '',
            'ignore_bots'              => 'off',
            'ignore_spammers'          => 'off',
            'ignore_languages'         => '',
            'ignore_users'             => '',
            'ignore_capabilities'      => '',
            'ignore_wp_users'          => 'off',
            'ignore_prefetch'          => 'off',
            'extend_session'           => 'off',
            'track_same_domain_referers' => 'off',
            'secret'                   => 'test-secret',
        ];

        /** @var bool */
        public static bool $is_programmatic_tracking = false;

        /** @var array<string,mixed> */
        private static array $_stat = ['dt' => 0];

        /** @var array<string,mixed> */
        private static array $_data_js = [];

        public static function log($message, string $level = 'info'): void
        {
            // Test no-op — production gates on WP_DEBUG, not relevant for unit tests.
        }

        public static function get_stat(): array
        {
            return self::$_stat;
        }

        public static function set_stat(array $stat): void
        {
            self::$_stat = $stat;
        }

        public static function get_data_js(): array
        {
            return self::$_data_js;
        }

        public static function set_data_js(array $data): void
        {
            self::$_data_js = $data;
        }

        public static function date_i18n(string $format): int
        {
            return (int) date($format);
        }

        public static function get_request_uri(): string
        {
            return '/';
        }

        public static function string_to_array(string $str): array
        {
            return array_filter(array_map('trim', explode(',', $str)));
        }

        public static function resolve_geolocation_provider(): bool
        {
            return false;
        }

        public static function get_geolocation_precision(): string
        {
            return 'city';
        }

        public static function update_option(string $key, $value): void {}

        public static function get_lossy_url(string $url): string
        {
            return $url;
        }
    }
}

// ── Minimal wpdb stub ─────────────────────────────────────────────────────
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb']         = new stdClass();
    $GLOBALS['wpdb']->prefix = 'wp_';
    $GLOBALS['wpdb']->comments = 'comments';
}
