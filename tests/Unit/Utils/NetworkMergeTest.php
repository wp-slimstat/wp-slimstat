<?php
/**
 * D22 / M1 — how one report's numbers combine across a network, and the rule that keeps a ratio
 * from being scoped on one side only.
 *
 * THE DEFECT THIS EXISTS FOR was measured twice, on the golden fixture, in two different ways.
 *
 *   1. Scoping `count_records()` alone moved the DENOMINATOR 15 → 40 while the numerator stayed
 *      at 15. Every "top" row understated ~2.7x; a report whose rows summed to 100% summed to
 *      ~37%; and nothing caught it, because reports.php clamps only the `> 99` direction.
 *      Reverted — PITFALLS 23.
 *
 *   2. THE SAME STATE REACHED FROM A DIFFERENT DIRECTION, and this one cannot be fixed by
 *      remembering to change both functions. Pro's `slimstat_get_var_sql` rewriter is years old;
 *      `slimstat_network_merge_active` is new. So v6 free against an OLDER Pro had the old
 *      rewriter scoping the denominator while get_top() — which needs the new filter to know a
 *      merge is happening — stayed main-site. Measured on a topology run against a Pro zip built
 *      before the filter existed: `report_denominator: 40, report_numerator: 15, MIXED`.
 *
 * So the property under test is not "network scoping works". It is:
 *
 *     NUMERATOR AND DENOMINATOR MOVE TOGETHER, OR NEITHER MOVES —
 *     including across version combinations nobody controls.
 *
 * 7.4-safe: pure functions plus one filter, no database and no WordPress beyond Brain Monkey.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Utils;

use SlimStat\Utils\NetworkMerge;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class NetworkMergeTest extends WpSlimstatTestCase
{
    // ── M1: the intent comes from the column, and SUM is the exception ──────

    public function test_only_id_sums(): void
    {
        // `id` counts ROWS, and rows are additive across blogs. It is the ONLY column for which
        // a bare SUM over per-blog counts is the right answer.
        $this->assertSame(NetworkMerge::SUM, NetworkMerge::intentForColumn('id'));
    }

    public function test_visit_id_is_distinct_per_blog(): void
    {
        // The visit counter is per-blog, so ids collide: two unrelated visits on two subsites
        // both call themselves 4. Distinct over (blog_id, visit_id), never visit_id alone.
        $this->assertSame(NetworkMerge::DISTINCT_PER_BLOG, NetworkMerge::intentForColumn('visit_id'));
    }

    /**
     * @dataProvider distinctColumns
     */
    public function test_identity_columns_are_distinct(string $column): void
    {
        // The fixture measures 6 distinct visitors network-wide against 7 summed per blog,
        // because 10.0.0.1 is one person on two subsites. No aggregate over per-blog counts
        // recovers the 6 — which is why these union VALUES rather than counts.
        $this->assertSame(NetworkMerge::DISTINCT, NetworkMerge::intentForColumn($column));
    }

    public function distinctColumns(): array
    {
        return [['ip'], ['resource'], ['referer'], ['browser'], ['fingerprint'], ['username']];
    }

    // ── the two halves of a merge have to agree with each other ─────────────

    public function test_sum_counts_inside_the_blog_and_sums_outside(): void
    {
        $this->assertSame('COUNT(id) as counthits', NetworkMerge::innerSelect(NetworkMerge::SUM, 'id'));
        $this->assertSame('SUM(counthits) AS counthits', NetworkMerge::outerAggregate(NetworkMerge::SUM, 'id'));
    }

    public function test_distinct_returns_values_inside_and_counts_them_outside(): void
    {
        // THE PAIR IS THE POINT. An inner query that already counted cannot be counted-distinct
        // afterwards, and an inner query that returns values cannot be summed. Asserting only
        // one half would let the two drift into a shape that is individually plausible and
        // jointly wrong.
        // DISTINCT INSIDE THE ARM TOO, and it is not redundant with the outer count. The bare
        // column returns one row per PAGEVIEW; MySQL cannot merge a UNION derived table, so the
        // whole thing materialises before the outer aggregate runs — fifty blogs at 200k
        // in-range rows is ~10M rows into a temp table that spills to disk, five times per
        // report screen. De-duplicating per arm bounds each by that blog's CARDINALITY, and the
        // outer pass still catches values shared BETWEEN blogs, which is the only thing the
        // union carries values for.
        $this->assertSame('DISTINCT ip', NetworkMerge::innerSelect(NetworkMerge::DISTINCT, 'ip'));
        $this->assertSame(
            'COUNT(DISTINCT ip) AS counthits',
            NetworkMerge::outerAggregate(NetworkMerge::DISTINCT, 'ip')
        );
    }

    public function test_distinct_per_blog_qualifies_the_key_with_the_blog(): void
    {
        // Lossless here for the same reason it is safe above, and one more: blog_id is CONSTANT
        // within an arm, so de-duplicating on visit_id alone inside the arm cannot collapse two
        // rows the outer COUNT(DISTINCT blog_id, visit_id) would have kept apart.
        $this->assertSame('DISTINCT visit_id', NetworkMerge::innerSelect(NetworkMerge::DISTINCT_PER_BLOG, 'visit_id'));
        $this->assertSame(
            'COUNT(DISTINCT blog_id, visit_id) AS counthits',
            NetworkMerge::outerAggregate(NetworkMerge::DISTINCT_PER_BLOG, 'visit_id')
        );
    }

    public function test_an_undeclared_intent_yields_no_aggregate(): void
    {
        // The default has to be "cannot merge", not "sum". An unknown aggregate that got
        // silently summed is precisely how COUNT(DISTINCT ip) would come to report 7 where the
        // answer is 6 — and Query::getVar() applies the union filter only for a NON-EMPTY
        // aggregate, so an empty string here is what keeps such a query on one blog.
        $this->assertSame('', NetworkMerge::outerAggregate('not-an-intent', 'ip'));
    }

    // ── the compatibility property, which is the one that was measured wrong ─

    public function test_no_merge_is_active_by_default(): void
    {
        // No Pro, or a Pro too old to answer: free must conclude that nothing will union its
        // query, and therefore build and scope nothing. This is the branch that makes an
        // old-Pro install fall back to consistent-main-site instead of to MIXED.
        $this->assertFalse(NetworkMerge::isMerging());
    }

    public function test_a_listener_can_declare_a_merge_active(): void
    {
        // Vacuity control for the case above: an isMerging() hardcoded to false would satisfy
        // it perfectly, and would silently disable network scoping everywhere.
        \Brain\Monkey\Filters\expectApplied('slimstat_network_merge_active')
            ->once()
            ->andReturn(true);

        $this->assertTrue(NetworkMerge::isMerging());
    }
}
