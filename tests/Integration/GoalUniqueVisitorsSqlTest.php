<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the goal "unique visitors" identity fix (#3).
 *
 * Goal uniques used to be COUNT(DISTINCT fingerprint) with an implicit
 * `fingerprint IS NOT NULL`, so a segment dominated by NULL-fingerprint rows
 * (bots, consent-limited sessions, pre-fingerprint data) reported a correct
 * Total but 0 Uniques (e.g. a "Country = gb" goal). The fix counts distinct
 * visitors with the same NULL-safe COALESCE identity funnels use, so goal
 * uniques match funnel step-1 counts and never drop NULL-fingerprint visitors.
 *
 * Like FunnelOrderingSqlTest, this parses the source and pins the SQL-shape
 * markers rather than executing SQL (which needs a live MySQL).
 */
class GoalUniqueVisitorsSqlTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $src = file_get_contents(dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php');
        if ($src === false) {
            $this->fail('Could not read admin/view/wp-slimstat-db.php');
        }
        $this->src = $src;
    }

    private function methodBody(string $name): string
    {
        if (!preg_match('/function ' . preg_quote($name, '/') . '\([^{]*\{.*?^    \}/sm', $this->src, $m)) {
            $this->fail("Could not extract {$name}() body");
        }
        return $m[0];
    }

    public function test_count_unique_visitors_uses_coalesce_identity(): void
    {
        $body = $this->methodBody('count_unique_visitors');
        // Distinct count over the COALESCE visitor id (via visitor_id_expr),
        // decomposed as COUNT(*) FROM (SELECT DISTINCT ...) for the index win.
        $this->assertStringContainsString('visitor_id_expr', $body, 'count_unique_visitors must use the COALESCE visitor_id_expr identity');
        $this->assertStringContainsString('SELECT DISTINCT %s AS vid', $body, 'count_unique_visitors must DISTINCT the visitor id');
        $this->assertStringContainsString('SELECT COUNT(*) FROM (', $body, 'count_unique_visitors must keep the subquery-decomposition form');
    }

    public function test_unique_count_does_not_filter_out_null_fingerprints(): void
    {
        $body = $this->methodBody('count_unique_visitors');
        // The COALESCE id is never NULL, so no IS NOT NULL filter — that filter
        // is exactly what dropped NULL-fingerprint visitors before the fix.
        $this->assertStringNotContainsStringIgnoringCase(
            'fingerprint IS NOT NULL',
            $body,
            'count_unique_visitors must not re-introduce the NULL-fingerprint exclusion'
        );
    }

    public function test_goal_results_count_distinct_visitors_not_fingerprints(): void
    {
        $body = $this->methodBody('get_goal_results');
        $this->assertStringContainsString(
            'count_unique_visitors(',
            $body,
            'get_goal_results must count uniques via count_unique_visitors (COALESCE identity)'
        );
        $this->assertStringNotContainsString(
            'count_unique_fingerprints(',
            $body,
            'get_goal_results must no longer use the fingerprint-only counter'
        );
    }

    public function test_goal_results_returns_cr_denominator(): void
    {
        // get_goal_results must hand the card the CR denominator (total visitors in
        // range) so it can show "N of M uniques" and make the percentage legible. (#13)
        $body = $this->methodBody('get_goal_results');
        $this->assertStringContainsString(
            "'total_visitors'",
            $body,
            'get_goal_results must return total_visitors (the CR denominator)'
        );
    }

    public function test_total_unique_visitors_denominator_uses_same_identity(): void
    {
        // Numerator (goal uniques) and denominator (total uniques for CR) must
        // share one identity, or conversion rates drift.
        $body = $this->methodBody('get_total_unique_visitors');
        $this->assertStringContainsString(
            'count_unique_visitors(',
            $body,
            'get_total_unique_visitors must use the same COALESCE identity as the goal numerator'
        );
    }

    public function test_fingerprint_only_counter_is_retired(): void
    {
        // The old fingerprint-only counter had no remaining callers; leaving it
        // would be dead code that invites re-introducing the undercount.
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+count_unique_fingerprints\s*\(/',
            $this->src,
            'count_unique_fingerprints (fingerprint-only, NULL-excluding) should be removed'
        );
    }
}
