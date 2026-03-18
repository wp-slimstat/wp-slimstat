<?php
/**
 * Minimal stubs for Tracker unit tests.
 *
 * Provides just enough scaffolding for SlimStat\Tracker\* classes to load
 * without a live WordPress or database connection.
 */
declare(strict_types=1);

// ── WordPress constants needed by source files ────────────────────────────
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

// ── wp_slimstat global stub ───────────────────────────────────────────────
if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        /** @var array<string,mixed> */
        public static array $settings = [
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
            'anonymize_ip'             => 'off',
            'secret'                   => 'test-secret',
        ];

        /** @var bool */
        public static bool $is_programmatic_tracking = false;

        /** @var array<string,mixed> */
        private static array $_stat = ['dt' => 0];

        /** @var array<string,mixed> */
        private static array $_data_js = [];

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
