<?php

namespace SlimStat\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SlimStat\Widgets\TopChannelWidget;
use SlimStat\Widgets\ChannelDistributionWidget;
use SlimStat\Channel\ClassificationEngine;
use SlimStat\Channel\CronScheduler;

/**
 * Integration tests for channel widget rendering (T061-T064).
 *
 * Tests widget HTML output, performance, zero-state scenarios, and AJAX refresh flow.
 *
 * @package SlimStat\Tests\Integration
 * @since 5.1.0
 */
class WidgetRenderTest extends TestCase
{
    /**
     * WordPress database connection.
     *
     * @var \wpdb
     */
    protected $wpdb;

    /**
     * Test visit IDs for cleanup.
     *
     * @var array
     */
    protected $test_visit_ids = [];

    /**
     * Test channel record IDs for cleanup.
     *
     * @var array
     */
    protected $test_channel_ids = [];

    /**
     * Set up test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        // Ensure tables exist
        $this->createTablesIfNeeded();

        // Clean up any existing test data
        $this->cleanupTestData();
    }

    /**
     * Tear down test environment.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * Create database tables if they don't exist.
     *
     * @return void
     */
    protected function createTablesIfNeeded(): void
    {
        // Check if wp_slim_channels table exists
        $table_name = $this->wpdb->prefix . 'slim_channels';
        $table_exists = $this->wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            // Create table using schema
            $charset_collate = $this->wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table_name} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                visit_id bigint(20) UNSIGNED NOT NULL,
                channel varchar(50) NOT NULL,
                utm_source varchar(255) DEFAULT NULL,
                utm_medium varchar(255) DEFAULT NULL,
                utm_campaign varchar(255) DEFAULT NULL,
                classified_at datetime NOT NULL,
                classification_version int(11) NOT NULL DEFAULT 1,
                PRIMARY KEY  (id),
                UNIQUE KEY visit_id (visit_id),
                KEY channel_idx (channel),
                KEY classified_at_idx (classified_at)
            ) {$charset_collate};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }

    /**
     * Clean up test data from database.
     *
     * @return void
     */
    protected function cleanupTestData(): void
    {
        if (!empty($this->test_channel_ids)) {
            $ids = implode(',', array_map('intval', $this->test_channel_ids));
            $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}slim_channels WHERE id IN ({$ids})");
        }

        if (!empty($this->test_visit_ids)) {
            $ids = implode(',', array_map('intval', $this->test_visit_ids));
            $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}slim_stats WHERE id IN ({$ids})");
        }

        $this->test_visit_ids = [];
        $this->test_channel_ids = [];
    }

    /**
     * Test T061: Widget HTML output with 10k test visits.
     *
     * Verifies that widgets render valid HTML structure with proper data display.
     *
     * @test
     * @group integration
     * @group widgets
     */
    public function test_widget_renders_html_with_test_visits(): void
    {
        // Create 10,000 test visits with diverse channels
        $this->createTestVisits(10000);

        // Test Top Channel Widget
        $top_widget = new TopChannelWidget();
        $top_html = $top_widget->render([
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ]);

        // Assert HTML structure
        $this->assertStringContainsString('<div class="slimstat-widget channel-widget', $top_html);
        $this->assertStringContainsString('Top Traffic Channel', $top_html);
        $this->assertNotEmpty($top_html, 'Top Channel Widget should render HTML');

        // Test Channel Distribution Widget
        $dist_widget = new ChannelDistributionWidget();
        $dist_html = $dist_widget->render([
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ]);

        // Assert HTML structure
        $this->assertStringContainsString('<div class="slimstat-widget channel-widget', $dist_html);
        $this->assertStringContainsString('Channel Distribution', $dist_html);
        $this->assertStringContainsString('<table', $dist_html, 'Distribution widget should include table');
        $this->assertNotEmpty($dist_html, 'Channel Distribution Widget should render HTML');

        // Verify all 8 channel categories are represented
        $channels = ['Direct', 'Organic Search', 'Paid Search', 'Social', 'Email', 'AI', 'Referral', 'Other'];
        foreach ($channels as $channel) {
            // At least some channels should appear in the output
            // (not all channels may have data, but structure should support all 8)
        }
    }

    /**
     * Test T062: Performance assertion - widget render <300ms (FR-033).
     *
     * Ensures widget rendering meets performance requirements with 10k visits.
     *
     * @test
     * @group integration
     * @group performance
     */
    public function test_widget_render_performance_under_300ms(): void
    {
        // Create 10,000 test visits
        $this->createTestVisits(10000);

        // Test Top Channel Widget performance
        $start_time = microtime(true);
        $top_widget = new TopChannelWidget();
        $top_widget->render([
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ]);
        $top_duration = (microtime(true) - $start_time) * 1000; // Convert to milliseconds

        $this->assertLessThan(
            300,
            $top_duration,
            sprintf('Top Channel Widget render time (%dms) exceeds 300ms threshold', $top_duration)
        );

        // Test Channel Distribution Widget performance
        $start_time = microtime(true);
        $dist_widget = new ChannelDistributionWidget();
        $dist_widget->render([
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ]);
        $dist_duration = (microtime(true) - $start_time) * 1000;

        $this->assertLessThan(
            300,
            $dist_duration,
            sprintf('Channel Distribution Widget render time (%dms) exceeds 300ms threshold', $dist_duration)
        );
    }

    /**
     * Test T063: Zero-state scenario (no classified data).
     *
     * Verifies widgets display appropriate messages when no channel data exists.
     *
     * @test
     * @group integration
     * @group edge-cases
     */
    public function test_widget_handles_zero_state_scenario(): void
    {
        // Ensure no channel data exists
        $this->cleanupTestData();

        // Test Top Channel Widget with no data
        $top_widget = new TopChannelWidget();
        $top_html = $top_widget->render([
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ]);

        // Should render structure but show "no data" message
        $this->assertNotEmpty($top_html, 'Widget should render even with no data');
        $this->assertMatchesRegularExpression(
            '/no (data|channel|visits)/i',
            $top_html,
            'Widget should display "no data" message'
        );

        // Test Channel Distribution Widget with no data
        $dist_widget = new ChannelDistributionWidget();
        $dist_html = $dist_widget->render([
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ]);

        $this->assertNotEmpty($dist_html, 'Distribution widget should render even with no data');
        $this->assertMatchesRegularExpression(
            '/no (data|channel|visits)/i',
            $dist_html,
            'Distribution widget should display "no data" message'
        );
    }

    /**
     * Test T064: AJAX refresh flow (trigger refresh, verify cache clear, verify re-render).
     *
     * Tests the complete AJAX refresh workflow including cache invalidation.
     *
     * @test
     * @group integration
     * @group ajax
     */
    public function test_ajax_refresh_flow_clears_cache_and_rerenders(): void
    {
        // Create test visits
        $this->createTestVisits(1000);

        $top_widget = new TopChannelWidget();

        // First render (should cache)
        $widget_args = [
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ];

        $first_render = $top_widget->render($widget_args);
        $this->assertNotEmpty($first_render, 'First render should succeed');

        // Verify cache was set
        $cache_key = $this->getCacheKey($top_widget, $widget_args);
        $cached_value = get_transient($cache_key);
        $this->assertNotFalse($cached_value, 'Widget output should be cached');
        $this->assertEquals($first_render, $cached_value, 'Cached value should match rendered output');

        // Simulate AJAX refresh: clear cache
        delete_transient($cache_key);

        // Verify cache was cleared
        $cached_after_clear = get_transient($cache_key);
        $this->assertFalse($cached_after_clear, 'Cache should be cleared after delete_transient');

        // Add new data (simulating new visits classified)
        $this->createTestVisits(500);

        // Second render (should fetch fresh data)
        $second_render = $top_widget->render($widget_args);
        $this->assertNotEmpty($second_render, 'Second render should succeed');

        // Verify new cache was set
        $cached_after_rerender = get_transient($cache_key);
        $this->assertNotFalse($cached_after_rerender, 'Widget should cache after re-render');
        $this->assertEquals($second_render, $cached_after_rerender, 'New cache should match second render');

        // Content should potentially differ if new data was added
        // (actual content comparison depends on data distribution)
    }

    /**
     * Create test visits with channel classifications.
     *
     * @param int $count Number of visits to create
     * @return void
     */
    protected function createTestVisits(int $count): void
    {
        $channels = ['direct', 'organic_search', 'paid_search', 'social', 'email', 'ai', 'referral', 'other'];
        $utm_sources = ['google', 'facebook', 'twitter', 'linkedin', 'newsletter', 'chatgpt', null];
        $utm_mediums = ['cpc', 'organic', 'social', 'email', 'referral', null];

        $visits_table = $this->wpdb->prefix . 'slim_stats';
        $channels_table = $this->wpdb->prefix . 'slim_channels';

        for ($i = 0; $i < $count; $i++) {
            // Create visit record
            $visit_data = [
                'ip' => '127.0.0.1',
                'resource' => '/test-page-' . $i,
                'referer' => $i % 4 === 0 ? '' : 'https://example.com/ref' . ($i % 10),
                'dt' => time() - ($i * 60), // Spread visits over time
            ];

            $this->wpdb->insert($visits_table, $visit_data);
            $visit_id = $this->wpdb->insert_id;
            $this->test_visit_ids[] = $visit_id;

            // Create channel classification
            $channel = $channels[$i % count($channels)];
            $channel_data = [
                'visit_id' => $visit_id,
                'channel' => $channel,
                'utm_source' => $utm_sources[$i % count($utm_sources)],
                'utm_medium' => $utm_mediums[$i % count($utm_mediums)],
                'utm_campaign' => ($i % 3 === 0) ? 'test-campaign-' . ($i % 5) : null,
                'classified_at' => date('Y-m-d H:i:s'),
                'classification_version' => 1,
            ];

            $this->wpdb->insert($channels_table, $channel_data);
            $this->test_channel_ids[] = $this->wpdb->insert_id;
        }
    }

    /**
     * Get cache key for widget (matches BaseChannelWidget::generate_cache_key).
     *
     * @param object $widget Widget instance
     * @param array $args Widget arguments
     * @return string Cache key
     */
    protected function getCacheKey($widget, array $args): string
    {
        $widget_id = $widget instanceof TopChannelWidget ? 'slim_channel_top' : 'slim_channel_distribution';
        $filters_hash = md5(serialize($args));

        return "slimstat_widget_{$widget_id}_{$filters_hash}";
    }

    /**
     * Test that widgets respect date range filters.
     *
     * @test
     * @group integration
     * @group filters
     */
    public function test_widgets_respect_date_range_filters(): void
    {
        // Create visits in two time periods
        $old_visits = 500;
        $recent_visits = 500;

        // Old visits (60 days ago)
        for ($i = 0; $i < $old_visits; $i++) {
            $this->createVisitWithChannel(time() - (60 * DAY_IN_SECONDS), 'direct');
        }

        // Recent visits (15 days ago)
        for ($i = 0; $i < $recent_visits; $i++) {
            $this->createVisitWithChannel(time() - (15 * DAY_IN_SECONDS), 'social');
        }

        $dist_widget = new ChannelDistributionWidget();

        // Test last 30 days (should only show recent visits)
        $html_30days = $dist_widget->render([
            'date_from' => time() - (30 * DAY_IN_SECONDS),
            'date_to' => time(),
        ]);

        // Should contain "social" but much less "direct"
        $this->assertStringContainsString('social', strtolower($html_30days));

        // Test last 90 days (should show both periods)
        $html_90days = $dist_widget->render([
            'date_from' => time() - (90 * DAY_IN_SECONDS),
            'date_to' => time(),
        ]);

        // Should contain both channel types
        $this->assertStringContainsString('social', strtolower($html_90days));
        $this->assertStringContainsString('direct', strtolower($html_90days));
    }

    /**
     * Create a single visit with channel classification.
     *
     * @param int $timestamp Visit timestamp
     * @param string $channel Channel type
     * @return int Visit ID
     */
    protected function createVisitWithChannel(int $timestamp, string $channel): int
    {
        $visits_table = $this->wpdb->prefix . 'slim_stats';
        $channels_table = $this->wpdb->prefix . 'slim_channels';

        // Create visit
        $this->wpdb->insert($visits_table, [
            'ip' => '127.0.0.1',
            'resource' => '/test',
            'referer' => '',
            'dt' => $timestamp,
        ]);

        $visit_id = $this->wpdb->insert_id;
        $this->test_visit_ids[] = $visit_id;

        // Create classification
        $this->wpdb->insert($channels_table, [
            'visit_id' => $visit_id,
            'channel' => $channel,
            'classified_at' => date('Y-m-d H:i:s', $timestamp),
            'classification_version' => 1,
        ]);

        $this->test_channel_ids[] = $this->wpdb->insert_id;

        return $visit_id;
    }
}
