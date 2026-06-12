<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Admin;

use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/**
 * Behavioral pinning for the post-fix conditionals at admin/index.php:891, 906.
 *
 * Loading admin/index.php in isolation is infeasible (thousands of lines of
 * side-effectful WP-hook registrations). Instead, this test exercises the
 * exact post-fix conditional expressions against a matrix of inputs, proving
 * the refactor preserves the original str_contains semantics. The source
 * itself is pinned by tests/php74-no-php80-functions-test.php, which runs on
 * the PHP 7.4 CI lane where PHPUnit cannot.
 */
class EnqueueScriptsCompatTest extends WpSlimstatTestCase
{
    public static function screenIdProvider(): array
    {
        return [
            'slim_analytics is slim'             => ['slim_analytics', true],
            'toplevel_page_slimview1 is slim'    => ['toplevel_page_slimview1', true],
            'dashboard is NOT slim'              => ['dashboard', false],
            'empty string is NOT slim'           => ['', false],
            'null id is NOT slim'                => [null, false],
        ];
    }

    /** @dataProvider screenIdProvider */
    public function test_screen_gate_matches_str_contains_semantics($screen_id, bool $expected_match): void
    {
        $current_screen = $screen_id !== null ? (object) ['id' => $screen_id] : null;
        $matched = $current_screen && false !== strpos((string) ($current_screen->id ?? ''), 'slim');

        $this->assertSame($expected_match, $matched, "Screen gate mismatch for screen_id=" . var_export($screen_id, true));
    }

    public static function pageQueryProvider(): array
    {
        return [
            'wp-slim-view-1 is slim'             => ['wp-slim-view-1', true],
            'slimstat is slim'                   => ['slimstat', true],
            'slimstat-setting is NOT'            => ['slimstat-setting', false],
            'slim_settings is NOT'               => ['slim_settings', false],
            'edit is NOT slim'                   => ['edit', false],
            'empty is NOT slim'                  => ['', false],
        ];
    }

    /** @dataProvider pageQueryProvider */
    public function test_datepicker_gate_matches_str_contains_semantics(string $page, bool $expected_match): void
    {
        $matched = false !== strpos($page, 'slim') && false === strpos($page, 'setting');

        $this->assertSame($expected_match, $matched, "Datepicker gate mismatch for page={$page}");
    }
}
