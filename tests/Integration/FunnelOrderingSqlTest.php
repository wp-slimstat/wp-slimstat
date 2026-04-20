<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the funnel SQL ordering fix.
 *
 * CodeRabbit Critical (PR #289) flagged that the original `get_funnel_results()`
 * implementation computed a set intersection across steps — a visitor who hit
 * step 3 before step 1 was still counted as "converted." The fix enforces
 * per-visitor temporal ordering: step N+1 only counts rows whose dt is strictly
 * greater than the MIN(dt) recorded for the same visitor at step N.
 *
 * This test pins the key SQL-shape markers so a future refactor can't silently
 * revert the fix without the test failing. It doesn't execute SQL (that needs
 * a live MySQL); it parses the source and asserts the algorithm structure.
 */
class FunnelOrderingSqlTest extends TestCase
{
    private string $body;

    protected function setUp(): void
    {
        parent::setUp();
        $src = file_get_contents(dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php');
        if ($src === false) {
            $this->fail('Could not read admin/view/wp-slimstat-db.php');
        }
        // Match from the method signature to the first column-4 closing brace.
        if (!preg_match('/public static function get_funnel_results\([^{]*\{.*?^    \}/sm', $src, $m)) {
            $this->fail('Could not extract get_funnel_results() body');
        }
        $this->body = $m[0];
    }

    public function test_temp_table_carries_timestamp_column(): void
    {
        // The temp table now stores (vid, t) so step N+1 can compare against
        // the visitor's first-seen timestamp at step N.
        $this->assertStringContainsString(
            't INT UNSIGNED NOT NULL',
            $this->body,
            'Funnel temp table must include a `t INT UNSIGNED` column to store per-visitor first-seen timestamps'
        );
    }

    public function test_step_aggregates_min_dt_per_visitor(): void
    {
        // The SQL selects MIN(dt_expr) AS t and groups by vid to collapse to
        // one row per visitor keyed on their first qualifying timestamp.
        $this->assertMatchesRegularExpression(
            '/MIN\(%s\)\s+AS\s+t/i',
            $this->body,
            'Funnel SQL must use MIN(...) AS t (per-visitor earliest timestamp)'
        );
        $this->assertStringContainsString(
            'GROUP BY vid',
            $this->body,
            'Funnel SQL must GROUP BY visitor id to compute per-visitor MIN(dt)'
        );
    }

    public function test_step_two_plus_joins_temp_read_and_enforces_time_ordering(): void
    {
        // Step N>1 must JOIN temp_read on visitor id and require the new row's
        // dt to be strictly greater than the stored timestamp. Without this
        // constraint, the query would fall back to a set intersection (the bug).
        $this->assertStringContainsString(
            'INNER JOIN %s r ON r.vid = %s',
            $this->body,
            'Step N>1 must JOIN the temp_read table on visitor id (format-string form)'
        );
        $this->assertStringContainsString(
            '%s > r.t',
            $this->body,
            'Step N>1 must compare the current dt against r.t from temp_read (format-string form)'
        );
    }

    public function test_no_unordered_in_subquery_fallback_remains(): void
    {
        // The pre-fix implementation filtered with `vid IN (SELECT vid FROM temp_read)`.
        // That pattern is a set-intersection shortcut without time ordering and
        // must not reappear in any real statement. Strip PHP comments first —
        // the body legitimately references the pattern in a `//` comment that
        // explains a MySQL 5.6 self-reference limitation.
        $code_only = preg_replace('#//[^\n]*#', '', $this->body);
        $this->assertDoesNotMatchRegularExpression(
            '/IN\s*\(\s*SELECT\s+vid\s+FROM\s+/i',
            $code_only,
            'Pre-fix `vid IN (SELECT vid FROM temp_read)` pattern would defeat ordering; must not reappear'
        );
    }

    public function test_event_and_pageview_dimension_have_distinct_time_expressions(): void
    {
        // Event-dimension steps must order by the event's own dt (te.dt);
        // pageview-dimension steps order by the parent row's dt (t1.dt).
        // Both literals appear in the source because $dt_expr ternaries between them.
        $this->assertStringContainsString('te.dt', $this->body, 'Event steps must reference te.dt');
        $this->assertStringContainsString('t1.dt', $this->body, 'Pageview steps must reference t1.dt');
    }
}
