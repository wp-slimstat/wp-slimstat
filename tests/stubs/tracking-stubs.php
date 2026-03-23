<?php
/**
 * Stubs for TrackingRestController unit tests.
 * Provides minimal WP and SlimStat class/function stubs.
 */
declare(strict_types=1);

// ─── WordPress function stubs ────────────────────────────────────
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return is_string($str) ? trim(strip_tags($str)) : ''; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return $text; }
}
if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($data) { return $data; }
}
if (!function_exists('status_header')) {
    function status_header($code, $description = '') {}
}

// ─── WP_REST_Request stub ────────────────────────────────────────
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $params;
        public function __construct(array $params = []) { $this->params = $params; }
        public function get_params(): array { return $this->params; }
        public function get_param(string $key) { return $this->params[$key] ?? null; }
        public function get_body_params(): array { return []; }
    }
}

// ─── wp_slimstat stub ────────────────────────────────────────────
if (!class_exists('wp_slimstat')) {
    class wp_slimstat {
        public static array $raw_post_array = [];
    }
}

// ─── WP_Error stub ───────────────────────────────────────────────
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct($code = '', $message = '', $data = '') {}
    }
}
