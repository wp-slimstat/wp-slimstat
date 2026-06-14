<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the report-placement fix: the Goals & Funnels reports are PINNED to
 * their dedicated page (slimview6), so a saved layout that moved a copy to
 * another screen (e.g. the dashboard) can't leave the Goals & Funnels page empty.
 *
 * Root cause: the report-merge in wp_slimstat_reports::init() only places reports
 * the user hasn't placed anywhere (array_diff against the flattened saved layout),
 * so a report dragged to one screen vanished from its other default locations.
 */
class GoalsFunnelsReportPlacementTest extends TestCase
{
    public function test_goals_and_funnels_reports_are_pinned(): void
    {
        $php = file_get_contents($this->reportsPath());
        // slim_p9_01 (Goals)
        $this->assertMatchesRegularExpression(
            "/'slim_p9_01'\s*=>\s*\[[\s\S]*?'pinned'\s*=>\s*true/",
            $php,
            'Goals report must be pinned to its dedicated page'
        );
        // slim_p9_02 (Funnels)
        $this->assertMatchesRegularExpression(
            "/'slim_p9_02'\s*=>\s*\[[\s\S]*?'pinned'\s*=>\s*true/",
            $php,
            'Funnels report must be pinned to its dedicated page'
        );
    }

    public function test_merge_logic_honors_pinned_reports(): void
    {
        $php = file_get_contents($this->reportsPath());
        // The init() merge must collect pinned reports and fold them back into the
        // placement list so they always land in their default location(s).
        $this->assertMatchesRegularExpression(
            "/!empty\(\\\$a_report\['pinned'\]\)/",
            $php,
            'Merge must detect pinned reports'
        );
        $this->assertStringContainsString(
            'array_merge($merge_reports, $pinned_reports)',
            $php,
            'Pinned reports must be merged into the report-placement list'
        );
    }

    private function reportsPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/view/wp-slimstat-reports.php';
    }
}
