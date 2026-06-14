<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the goals/funnels AJAX DB-init fix (#6, #8).
 *
 * The funnel-step "Test" and funnel lazy-load AJAX actions are not covered by
 * the admin bootstrap that calls wp_slimstat_db::init(). Without init() the
 * date filter collapsed to `dt BETWEEN 0 AND 0`, so the Test returned
 * "0 matches" for pages that clearly exist (#6) and every lazily-loaded
 * (non-first) funnel showed 0 (#8). Both handlers now call
 * ensure_goals_db_initialized(), which init()s the DB layer and pins the date
 * window to the report's selected range.
 *
 * Source-shape test (mirrors FunnelOrderingSqlTest) — no live MySQL.
 */
class GoalsFunnelsAjaxInitTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
        if ($this->src === '') {
            $this->fail('Could not read admin/index.php');
        }
    }

    private function methodBody(string $name): string
    {
        if (!preg_match('/function ' . preg_quote($name, '/') . '\([^{]*\{.*?^    \}/sm', $this->src, $m)) {
            $this->fail("Could not extract {$name}() body");
        }
        return $m[0];
    }

    public function test_test_handler_initializes_db_layer(): void
    {
        $this->assertStringContainsString(
            'ensure_goals_db_initialized(',
            $this->methodBody('ajax_test_funnel_step'),
            'ajax_test_funnel_step must initialize the DB layer or the date filter collapses to dt BETWEEN 0 AND 0'
        );
    }

    public function test_load_handler_initializes_db_layer(): void
    {
        $this->assertStringContainsString(
            'ensure_goals_db_initialized(',
            $this->methodBody('ajax_load_funnel_data'),
            'ajax_load_funnel_data must initialize the DB layer so non-first funnels compute against the real range'
        );
    }

    public function test_initializer_calls_init_and_pins_utime_window(): void
    {
        $body = $this->methodBody('ensure_goals_db_initialized');
        $this->assertStringContainsString('resolve_requested_date_range(', $body, 'Initializer must resolve the requested window');
        $this->assertStringContainsString('wp_slimstat_db::init(', $body, 'Initializer must call wp_slimstat_db::init() to populate columns + defaults');
        $this->assertStringContainsString("filters_normalized['utime']", $body, 'Initializer must pin the utime window');
    }

    public function test_range_resolver_reads_request_with_preset_fallback(): void
    {
        // Shared resolver used by both the initializer and get_filter_options.
        $body = $this->methodBody('resolve_requested_date_range');
        $this->assertStringContainsString('time_range_type', $body, 'Resolver must read the requested time range');
        $this->assertStringContainsString('DateRangeHelper::get_range_by_preset', $body, 'Resolver must resolve presets via DateRangeHelper');
    }

    public function test_js_sends_date_range_with_test_and_load_requests(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/js/goals-funnels.js');
        // Both AJAX calls must forward the on-screen range so the backend can
        // match the visible window rather than a server default.
        $this->assertMatchesRegularExpression(
            '/slimstat_test_funnel_step[\s\S]{0,400}time_range_type/',
            $js,
            'Test request must include time_range_type'
        );
        $this->assertMatchesRegularExpression(
            '/slimstat_load_funnel_data[\s\S]{0,400}time_range_type/',
            $js,
            'Funnel lazy-load request must include time_range_type'
        );
    }
}
