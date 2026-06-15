<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the paused-goal tier behavior (#11).
 *
 * Free advertises "one active goal". The card now:
 *  - SHOWS every goal on both tiers ($visible_goals = $goals); paused goals stay
 *    visible (with their badge) rather than being hidden on Free.
 *  - On Free, auto-pauses all but the newest active goal via
 *    pause_excess_free_goals() and persists it — so the "one active goal"
 *    contract holds in storage even after a Pro→Free downgrade left several
 *    active. The usage pill counts active goals only.
 *  - goals-card.php skips get_goal_results() for a paused goal and shows a
 *    "Paused — not being measured" placeholder instead of numbers.
 *
 * Source-shape guards (no DB). The auto-pause logic is exercised directly by
 * tests/goals-free-active-limit-test.php; rendered behavior by the e2e spec.
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

    public function test_free_tier_shows_all_goals_and_auto_pauses_excess(): void
    {
        $php = $this->reports();
        // Both tiers expose the full goals list now (paused goals stay visible).
        $this->assertStringContainsString(
            '$visible_goals = $goals;',
            $php,
            'get_goals_card_state must show all goals (paused ones stay visible)'
        );
        // On Free, excess active goals are auto-paused and the change persisted.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\$is_pro\s*\)\s*\{[\s\S]{0,300}self::pause_excess_free_goals\(/',
            $php,
            'Free must auto-pause excess active goals via the helper'
        );
        $this->assertStringContainsString(
            "update_option('slimstat_goals', \$normalized)",
            $php,
            'the auto-pause result must be persisted'
        );
        $this->assertMatchesRegularExpression(
            "/'goals'\s*=>\s*\\\$visible_goals/",
            $php,
            "Card state must expose \$visible_goals as 'goals'"
        );
    }

    public function test_pause_helper_keeps_newest_active_by_id(): void
    {
        $php = $this->reports();
        $this->assertStringContainsString(
            'public static function pause_excess_free_goals(array $goals, int $max_goals)',
            $php,
            'pause_excess_free_goals helper must exist'
        );
        // Keeps the newest (highest-id) active goals, pauses the rest.
        $this->assertMatchesRegularExpression(
            '/arsort\(\$active\)[\s\S]{0,200}array_slice\(\s*array_keys\(\$active\)/',
            $php,
            'helper must keep the newest (highest-id) active goals'
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
