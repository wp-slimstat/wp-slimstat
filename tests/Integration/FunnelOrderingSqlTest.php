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
        // The temp table carries (vid, t, rid, rkind) so step N+1 can compare against the
        // visitor's first-seen timestamp at step N *and* exclude the row that produced it.
        //
        // The columns are DERIVED from the SELECT, not declared. This assertion used to
        // require the literal `t INT UNSIGNED NOT NULL`, which pinned the declaration —
        // and the declaration was the defect: an explicit `vid VARCHAR(64)` took the
        // database's default collation, so the step-2 join against the visitor-identity
        // expression threw ER_CANT_AGGREGATE_2COLLATIONS whenever the two differed, and
        // every step after the first silently reported 0 visitors.
        //
        // What must hold is that the column exists and carries the timestamp, which the
        // SELECT alias establishes and test_step_aggregates_min_dt_per_visitor() pins.
        $this->assertMatchesRegularExpression(
            '/CREATE\s+TEMPORARY\s+TABLE\s+\$temp_write\s*\(\s*KEY\s*\(\s*vid\s*\)\s*\)\s*AS/i',
            $this->body,
            'The funnel temp table must derive its columns from the SELECT — declaring '
                . '`vid VARCHAR(...)` gives it the database default collation and breaks the '
                . 'step-2 join on any install whose column collation differs'
        );
        $this->assertMatchesRegularExpression(
            '/MIN\(%s\)\s+AS\s+t\b/i',
            $this->body,
            'The temp table must still carry the per-visitor first-seen timestamp as `t`'
        );
    }

    public function test_step_two_plus_excludes_the_row_that_satisfied_the_previous_step(): void
    {
        // `dt >= r.t` alone lets ONE physical pageview satisfy TWO steps whenever the step
        // rules overlap — "contains shop" then "contains shop/cart" against a single visit
        // to /shop/cart — and report a conversion that never happened. Measured through
        // get_funnel_results() on scratch tables: 3 converted visitors where 2 converted.
        //
        // Tightening to `>` is NOT the fix; it drops a real conversion whose two separate
        // pageviews land in the same second (also measured). The row identity is the test.
        $this->assertMatchesRegularExpression(
            '/%s\s*<>\s*r\.rid/i',
            $this->body,
            'Step N>1 must exclude the physical row that satisfied step N, or one pageview '
                . 'matching two overlapping step rules is counted as a conversion'
        );
        $this->assertStringContainsString(
            'AS rid',
            $this->body,
            'The temp table must carry the id of the row that satisfied the step'
        );
    }

    public function test_row_id_is_an_argmin_not_a_second_aggregate(): void
    {
        // `MIN(dt) AS t, MIN(id) AS rid` looks right and is not: independent aggregates
        // over the same group need not describe the same row. For a visitor with rows
        // (id 5, dt 100) and (id 8, dt 90) it stores t=90 with rid=5 — an id belonging to a
        // row that did not achieve t. Step N+1 then excludes a row that never satisfied
        // step N while the row that did stays eligible.
        //
        // Measured through get_funnel_results() on scratch tables, with the two rows
        // inserted so id order and dt order disagree: the paired form reported 0 converted
        // visitors where 1 genuinely converted; the argmin reported 1. Reachable in normal
        // operation — dt is stamped by PHP before the INSERT, so a request that starts
        // later can commit first and take a lower auto-increment id.
        $this->assertDoesNotMatchRegularExpression(
            '/MIN\(%s\)\s+AS\s+rid/i',
            $this->body,
            '`rid` must not be a plain MIN() beside MIN(dt) — see this test\'s comment'
        );
        $this->assertMatchesRegularExpression(
            '/SUBSTRING_INDEX\(GROUP_CONCAT\(%s ORDER BY %s ASC, %s ASC\)/i',
            $this->body,
            '`rid` must be an argmin over (dt, id) so it names the row that achieved `t`'
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
        // dt to be at or after the stored timestamp. We use `>=` (not `>`) so two
        // genuinely ordered steps that land in the same one-second dt bucket still
        // count; without any time constraint the query would fall back to a set
        // intersection (the original bug).
        $this->assertStringContainsString(
            'INNER JOIN %s r ON r.vid = %s',
            $this->body,
            'Step N>1 must JOIN the temp_read table on visitor id (format-string form)'
        );
        $this->assertStringContainsString(
            '%s >= r.t',
            $this->body,
            'Step N>1 must compare the current dt against r.t from temp_read (>= for same-second ordering)'
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
