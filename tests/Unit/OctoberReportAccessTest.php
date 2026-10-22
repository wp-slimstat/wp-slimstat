<?php
/** @license GPL-2.0-or-later */
namespace WpSlimstat\Tests\Unit;

use Brain\Monkey\Functions;
use SlimStat\Ecommerce\Report;
use SlimStat\Utils\ProLinks;

class OctoberReportAccessTest extends WpSlimstatTestCase
{
    public function test_free_response_removes_every_pro_metric_after_a_cache_read(): void
    {
        $row = ['dimension' => 'direct', 'label' => 'Direct', 'net' => '123.40', 'orders' => 17, 'matched' => 12, 'quantity' => 23, 'refund' => 7];
        $data = ['current' => $row, 'previous' => $row, 'journey' => ['buyers' => 4, 'visits' => 20],
            'groups' => ['channel' => [$row, $row, $row], 'product' => [$row], 'landing' => [$row]],
            'series' => ['current' => [['net' => 12, 'orders' => 3, 'aov' => 4, 'rate' => 25, 'buyers' => 2, 'visits' => 8, 'label' => 'Today']], 'previous' => []]];
        $free = Report::freeData($data);
        $this->assertSame(['net' => '123.40'], $free['current']);
        $this->assertSame(3, $free['group_totals']['channel']);
        $this->assertCount(2, $free['groups']['channel']);
        $this->assertArrayNotHasKey('landing', $free['groups']);
        $this->assertArrayNotHasKey('journey', $free);
        $this->assertSame(['net' => 12, 'label' => 'Today'], $free['series']['current'][0]);
        $this->assertSame(['dimension' => 'direct', 'label' => 'Direct', 'net' => '123.40', 'can_filter' => true], $free['groups']['channel'][0]);
        $this->assertTrue($free['has_orders']);
    }

    public function test_previous_only_totals_and_calendar_buckets_reconcile(): void
    {
        Functions\stubs([
            'wp_timezone' => static fn() => new \DateTimeZone('UTC'),
            'wp_date' => static fn($format, $dt) => gmdate($format, $dt),
        ]);
        $db = \Mockery::mock(); $db->shouldReceive('get_var')->andReturn(0);
        $saved = \wp_slimstat::$wpdb; \wp_slimstat::$wpdb = $db;
        try {
            $method = new \ReflectionMethod(\SlimStat\Modules\Chart::class, 'processResults'); $method->setAccessible(true);
            $currentStart = strtotime('2026-06-30 UTC'); $currentEnd = strtotime('2026-09-27 23:59:59 UTC');
            $previousStart = strtotime('2026-04-01 UTC'); $previousEnd = $currentStart - 1;
            foreach (['DAY', 'WEEK'] as $granularity) {
                $dt = strtotime('2026-06-29 UTC');
                $data = $method->invoke(new \SlimStat\Modules\Chart(), [['dt' => $dt, 'v1' => 468, 'v2' => 24, 'period' => 'previous']], [['v1' => 468, 'v2' => 24, 'period' => 'previous']], ['label' => 'Y/m/d', 'gran' => $granularity], $currentStart, $currentEnd, $previousStart, $previousEnd);
                $this->assertSame(0, $data['totals'][0]->v1);
                $this->assertSame(468, $data['totals'][1]->v1);
                $this->assertSame(24, $data['totals'][1]->v2);
                $this->assertSame(468, array_sum($data['datasets_prev']['v1']), $granularity);
                $this->assertSame('2026/06/29', end($data['prev_labels']));
                if ('WEEK' === $granularity) { $this->assertNull(end($data['datasets']['v1']), 'no corresponding current week is not a measured zero'); }
            }
        } finally { \wp_slimstat::$wpdb = $saved; }
    }

    public function test_links_keep_the_origin_and_anchor_but_never_accept_a_destination(): void
    {
        Functions\stubs([
            'sanitize_key' => static function ($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($v)); },
            'admin_url' => static function ($v) { return 'https://site.test/wp-admin/' . $v; },
            'add_query_arg' => static function ($args, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args); },
            'wp_unslash' => static function ($v) { return stripslashes($v); },
        ]);
        $this->assertStringEndsWith('utm_campaign=export&utm_content=slim_p1_12#ss-pro-share', ProLinks::url('export', 'slim_p1_12', true, 'ss-pro-share'));
        $saved = $_GET;
        try {
            $_GET = ['utm_campaign' => 'export', 'utm_content' => 'slim_p1_12', 'url' => 'https://evil.test', 'utm_source' => 'evil'];
            $this->assertSame('https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=export&utm_content=slim_p1_12', ProLinks::fromRequest());
            $_GET['utm_campaign'] = ['invalid'];
            $this->assertStringContainsString('utm_campaign=pro-overview', ProLinks::fromRequest());
        } finally { $_GET = $saved; }
    }
}
