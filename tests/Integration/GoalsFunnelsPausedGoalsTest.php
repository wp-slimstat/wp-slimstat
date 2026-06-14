<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the paused-goal tier behavior (#11).
 *
 * Free advertises "one active goal", yet paused goals used to remain visible AND
 * keep running live queries on the Free goals card. The fix:
 *  - get_goals_card_state() returns only ACTIVE goals on Free (mirrors the
 *    funnels gate); Pro keeps all goals, with the usage pill still derived from
 *    the full active count.
 *  - goals-card.php skips get_goal_results() for a paused goal and shows a
 *    "Paused — not being measured" placeholder (so Pro paused goals don't run
 *    needless per-goal COUNT queries either).
 *
 * Source-shape guards (no DB); rendered behavior is covered by the e2e spec.
 */
class GoalsFunnelsPausedGoalsTest extends TestCase
{
    private function reports(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/admin/view/wp-slimstat-reports.php');
    }

    private function goalsCard(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/admin/view/partials/goals-funnels/goals-card.php');
    }

    public function test_free_tier_goal_state_hides_paused_goals(): void
    {
        $php = $this->reports();
        // Active goals are filtered once, then Free ($is_pro false) is shown only
        // those while Pro keeps the full list.
        $this->assertMatchesRegularExpression(
            '/\$active_goals\s*=\s*array_values\(\s*array_filter\(/',
            $php,
            'get_goals_card_state must compute the active-goals list'
        );
        $this->assertMatchesRegularExpression(
            '/\$visible_goals\s*=\s*\$is_pro\s*\?\s*\$goals\s*:\s*\$active_goals/',
            $php,
            'get_goals_card_state must show only active goals on Free'
        );
        $this->assertMatchesRegularExpression(
            "/'goals'\s*=>\s*\\\$visible_goals/",
            $php,
            "Card state must expose the gated \$visible_goals as 'goals'"
        );
    }

    public function test_usage_pill_count_still_uses_full_active_count(): void
    {
        $php = $this->reports();
        // active_count must come from the FULL goals list (computed before the
        // visible-goals filter), so "N of M used" stays accurate on Free.
        $this->assertMatchesRegularExpression(
            "/'active_count'\s*=>\s*\\\$active_count/",
            $php,
            'Card state must report the full active_count for the usage pill'
        );
    }

    public function test_paused_goals_skip_results_query(): void
    {
        $php = $this->goalsCard();
        // get_goal_results() is only called for active goals; paused goals get zeros.
        $this->assertMatchesRegularExpression(
            '/\$goal_active\s*\?\s*wp_slimstat_db::get_goal_results\(\$goal\)\s*:\s*\[/',
            $php,
            'goals-card must skip get_goal_results() for paused goals'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$results\s*=\s*wp_slimstat_db::get_goal_results\(\$goal\);/',
            $php,
            'goals-card must not unconditionally call get_goal_results()'
        );
    }

    public function test_paused_goal_shows_placeholder_not_metrics(): void
    {
        $php = $this->goalsCard();
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\$goal_active\s*\)\s*:[\s\S]{0,400}Paused — not being measured/',
            $php,
            'goals-card must render a paused placeholder before the metrics block'
        );
        // The paused branch must precede the existing "no matches yet" branch.
        $this->assertMatchesRegularExpression(
            '/Paused — not being measured[\s\S]{0,400}No matches in this date range yet/',
            $php,
            'paused placeholder must come before the active "no matches" branch'
        );
    }
}
