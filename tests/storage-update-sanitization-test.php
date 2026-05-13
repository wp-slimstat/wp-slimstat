<?php

/**
 * Tests for Storage::updateRow() sanitization parity with Storage::insertRow().
 *
 * Covers: CVE-2026-7634 — Storage::updateRow() lacked the sanitize_text_field()
 * loop that Storage::insertRow() applies, allowing raw HTML/JS in user_agent
 * (and other columns) to overwrite the sanitized row when a request triggered
 * a redirect (Processor::updateContentType) or AJAX update (Ajax::process).
 *
 * Run: php tests/storage-update-sanitization-test.php
 */

declare(strict_types=1);

// ─── Fake Query / wpdb that captures the SQL payload ───────────────

namespace SlimStat\Utils {

    class FakeQueryRecorder
    {
        public static array $setClauses = [];
        public static array $setRawClauses = [];
        public static array $setRawParams = [];
        public static ?int $where_id = null;
        public static int $executeCalls = 0;

        public static function reset(): void
        {
            self::$setClauses     = [];
            self::$setRawClauses  = [];
            self::$setRawParams   = [];
            self::$where_id       = null;
            self::$executeCalls   = 0;
        }
    }

    class Query
    {
        public static function update($table)
        {
            return new self();
        }

        public function ignore($flag = true)
        {
            return $this;
        }

        public function where($field, $operator, $value)
        {
            if ('id' === $field) {
                FakeQueryRecorder::$where_id = (int) $value;
            }
            return $this;
        }

        public function set($values)
        {
            FakeQueryRecorder::$setClauses = $values;
            return $this;
        }

        public function setRaw($column, $expression, $params = [])
        {
            FakeQueryRecorder::$setRawClauses[$column] = $expression;
            FakeQueryRecorder::$setRawParams[$column]  = $params;
            return $this;
        }

        public function execute()
        {
            FakeQueryRecorder::$executeCalls++;
            return 1;
        }
    }
}

namespace {

    $assertions = 0;

    function assert_same($expected, $actual, string $message): void
    {
        global $assertions;
        $assertions++;

        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
            exit(1);
        }
    }

    function assert_true($actual, string $message): void
    {
        global $assertions;
        $assertions++;

        if ($actual !== true) {
            fwrite(STDERR, "FAIL: {$message} (expected true, got " . var_export($actual, true) . ")\n");
            exit(1);
        }
    }

    function assert_false($actual, string $message): void
    {
        global $assertions;
        $assertions++;

        if ($actual !== false) {
            fwrite(STDERR, "FAIL: {$message} (expected false, got " . var_export($actual, true) . ")\n");
            exit(1);
        }
    }

    function assert_not_contains(string $needle, string $haystack, string $message): void
    {
        global $assertions;
        $assertions++;

        if (strpos($haystack, $needle) !== false) {
            fwrite(STDERR, "FAIL: {$message}\n  Expected NOT to contain: '{$needle}'\n  In: '{$haystack}'\n");
            exit(1);
        }
    }

    function assert_contains(string $needle, string $haystack, string $message): void
    {
        global $assertions;
        $assertions++;

        if (strpos($haystack, $needle) === false) {
            fwrite(STDERR, "FAIL: {$message}\n  Expected to contain: '{$needle}'\n  In: '{$haystack}'\n");
            exit(1);
        }
    }

    // ─── WordPress function stubs ──────────────────────────────────────

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str)
        {
            $str = (string) $str;
            $str = strip_tags($str);
            $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
            return trim($str);
        }
    }

    if (!function_exists('sanitize_url')) {
        function sanitize_url($url)
        {
            $url = (string) $url;
            $url = trim($url);
            if (preg_match('#^(javascript|data|vbscript):#i', $url)) {
                return '';
            }
            return strip_tags($url);
        }
    }

    if (!function_exists('wp_unslash')) {
        function wp_unslash($value)
        {
            if (is_array($value)) {
                return array_map('wp_unslash', $value);
            }
            return is_string($value) ? stripslashes($value) : $value;
        }
    }

    // Stub global $wpdb so Storage doesn't crash on prefix lookup.
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
    };

    // Load the SUT.
    require_once __DIR__ . '/../src/Tracker/Storage.php';

    // ─── Test 1: user_agent with XSS payload is stripped ───────────────

    \SlimStat\Utils\FakeQueryRecorder::reset();
    $result = \SlimStat\Tracker\Storage::updateRow([
        'id'         => 42,
        'user_agent' => 'Mozilla/5.0 <img src=x onerror=alert(/XSS/)>',
    ]);
    assert_same(42, $result, 'updateRow returns the id on success');
    assert_same(42, \SlimStat\Utils\FakeQueryRecorder::$where_id, 'WHERE id is bound to the input id');
    assert_same(1, \SlimStat\Utils\FakeQueryRecorder::$executeCalls, 'execute() is called exactly once');
    $ua = \SlimStat\Utils\FakeQueryRecorder::$setClauses['user_agent'] ?? null;
    assert_same('Mozilla/5.0', $ua, 'user_agent has HTML tags stripped via sanitize_text_field');
    assert_not_contains('<img', $ua ?? '', 'user_agent must not retain <img tag');
    assert_not_contains('onerror', $ua ?? '', 'user_agent must not retain onerror handler');

    // ─── Test 2: <script> in user_agent is fully removed ──────────────

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::updateRow([
        'id'         => 1,
        'user_agent' => '<script>alert(1)</script>Mozilla/5.0',
    ]);
    $ua = \SlimStat\Utils\FakeQueryRecorder::$setClauses['user_agent'] ?? null;
    assert_same('alert(1)Mozilla/5.0', $ua, 'sanitize_text_field strips <script> tags but keeps inner text');
    assert_not_contains('<script', $ua ?? '', 'no script tag survives');

    // ─── Test 3: referer is sanitized as URL (sanitize_url) ───────────

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::updateRow([
        'id'      => 1,
        'referer' => 'https://example.com/?q=<script>alert(1)</script>',
    ]);
    $referer = \SlimStat\Utils\FakeQueryRecorder::$setClauses['referer'] ?? null;
    assert_not_contains('<script', $referer ?? '', 'referer must not contain script tag');

    // ─── Test 4: notes array — each element sanitized then imploded ───

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::updateRow([
        'id'    => 1,
        'notes' => ['user:1', 'pre:yes', '<script>alert(1)</script>'],
    ]);
    $notesParams = \SlimStat\Utils\FakeQueryRecorder::$setRawParams['notes'] ?? [];
    assert_true(!empty($notesParams), 'setRaw was called for notes column');
    $notesString = $notesParams[0] ?? '';
    assert_contains('[user:1]', $notesString, 'notes preserves benign markers');
    assert_contains('[pre:yes]', $notesString, 'notes preserves benign markers');
    assert_not_contains('<script', $notesString, 'notes stripped of script tag (HTML tags removed by sanitize_text_field)');
    assert_not_contains('</script', $notesString, 'no closing script tag survives');

    // ─── Test 5a: outbound_resource — javascript: scheme rejected entirely ─

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::updateRow([
        'id'                => 1,
        'outbound_resource' => 'javascript:alert(1)',
    ]);
    // sanitize_url returns '' for javascript: scheme; the empty value then
    // fails the !empty($data['outbound_resource']) gate, so no UPDATE is
    // performed for this field. This is stricter (and safer) than pre-fix.
    assert_true(empty(\SlimStat\Utils\FakeQueryRecorder::$setRawParams['outbound_resource'] ?? []), 'javascript: outbound_resource must not reach setRaw');
    assert_true(!array_key_exists('outbound_resource', \SlimStat\Utils\FakeQueryRecorder::$setClauses), 'javascript: outbound_resource must not appear in SET');
    assert_same(0, \SlimStat\Utils\FakeQueryRecorder::$executeCalls, 'no SQL is executed when the only update field is sanitized away');

    // ─── Test 5b: outbound_resource — valid URL still flows through setRaw ─

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::updateRow([
        'id'                => 1,
        'outbound_resource' => 'https://example.com/landing',
    ]);
    $outboundParams = \SlimStat\Utils\FakeQueryRecorder::$setRawParams['outbound_resource'] ?? [];
    assert_true(!empty($outboundParams), 'valid outbound_resource still uses setRaw');
    assert_same('https://example.com/landing', $outboundParams[0] ?? null, 'valid URL preserved through both sanitization passes');

    // ─── Test 6: locale codes preserved exactly ───────────────────────

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::updateRow([
        'id'       => 1,
        'language' => 'en-US',
        'country'  => 'us',
        'browser'  => 'Chrome',
        'platform' => 'windows',
    ]);
    assert_same('en-US', \SlimStat\Utils\FakeQueryRecorder::$setClauses['language'] ?? null, 'language code preserved');
    assert_same('us', \SlimStat\Utils\FakeQueryRecorder::$setClauses['country'] ?? null, 'country code preserved');
    assert_same('Chrome', \SlimStat\Utils\FakeQueryRecorder::$setClauses['browser'] ?? null, 'browser name preserved');
    assert_same('windows', \SlimStat\Utils\FakeQueryRecorder::$setClauses['platform'] ?? null, 'platform name preserved');

    // ─── Test 7: empty data returns false (no SQL run) ────────────────

    \SlimStat\Utils\FakeQueryRecorder::reset();
    $result = \SlimStat\Tracker\Storage::updateRow([]);
    assert_false($result, 'updateRow returns false on empty input');
    assert_same(0, \SlimStat\Utils\FakeQueryRecorder::$executeCalls, 'execute() not called for empty input');

    // ─── Test 8: missing id returns false ─────────────────────────────

    \SlimStat\Utils\FakeQueryRecorder::reset();
    $result = \SlimStat\Tracker\Storage::updateRow(['user_agent' => 'Mozilla/5.0']);
    assert_false($result, 'updateRow returns false when id missing');
    assert_same(0, \SlimStat\Utils\FakeQueryRecorder::$executeCalls, 'execute() not called when id missing');

    // ─── Test 9: redirect content_type passes through unchanged ───────

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::updateRow([
        'id'           => 1,
        'content_type' => 'redirect:301',
    ]);
    assert_same('redirect:301', \SlimStat\Utils\FakeQueryRecorder::$setClauses['content_type'] ?? null, 'content_type redirect marker preserved');

    // ─── Test 10: id is unset before sanitization (never appears in SET) ─

    \SlimStat\Utils\FakeQueryRecorder::reset();
    \SlimStat\Tracker\Storage::updateRow([
        'id'         => 99,
        'user_agent' => 'Mozilla',
    ]);
    assert_true(!array_key_exists('id', \SlimStat\Utils\FakeQueryRecorder::$setClauses), 'id must not appear in SET clauses');
    assert_same(99, \SlimStat\Utils\FakeQueryRecorder::$where_id, 'id is bound to WHERE clause only');

    fwrite(STDOUT, "OK: {$assertions} assertions passed (Storage::updateRow sanitization)\n");
    exit(0);
}
