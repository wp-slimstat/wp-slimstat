<?php

namespace SlimStat\Utils;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

/**
 * How one report's numbers combine across the blogs of a network, and where free asks.
 *
 * D22 / R11. The Network View is a Pro addon that rewrites report SQL into a `UNION ALL` over
 * every in-scope blog. Free owns the queries; Pro owns the union. This class is the seam between
 * them, and it exists because scoping HALF of a ratio is worse than scoping neither.
 *
 * THE MEASURED FAILURE THAT SHAPES THIS. An earlier attempt routed `count_records()` — the
 * DENOMINATOR of every "top" report — through Pro's rewriter, and left the numerator alone
 * because `get_top()` builds a `Query` and `src/Utils/Query.php` applied no filters at all. On
 * the golden fixture the denominator moved 15 → 40 while the numerator stayed at 15: every row
 * understated ~2.7x, a report whose rows summed to 100% now summing to ~37%, and no clamp in
 * that direction because `reports.php` guards only `> 99`. Reverted. PITFALLS 23.
 *
 * SO THE RULE IS: numerator and denominator move together, or neither moves.
 *
 * ─── the four merge intents, from ratified M1–M4 ───────────────────────────────────────────
 *
 * `SUM` is correct for exactly one thing: a count of ROWS. Everything else needs its own answer,
 * and the reason is always the same — the inner queries have already collapsed to a number, and
 * no aggregate over a number restores the identities behind it.
 *
 *   SUM        COUNT(*), COUNT(id), SUM(x). Additive. Outer re-aggregate is SUM().
 *
 *   DISTINCT   COUNT(DISTINCT ip), COUNT(DISTINCT resource). NOT additive: the golden fixture
 *              measures 6 distinct visitors network-wide against 7 summed per blog, because
 *              10.0.0.1 is one person on two subsites. The inner query must therefore select
 *              the VALUES, not count them, and the outer level counts distinct over the union.
 *
 *   DISTINCT_PER_BLOG
 *              COUNT(DISTINCT visit_id). The counter is per-blog, so ids collide across
 *              subsites and two unrelated visits share the number 4. Distinct over
 *              (blog_id, visit_id), never over visit_id alone.
 *
 *   NONE       Not declared, therefore not scoped. The query stays main-site and says so.
 *              This is the default ON PURPOSE: an undeclared aggregate that got silently summed
 *              is precisely how COUNT(DISTINCT ip) would come to report 7 where the answer is 6.
 *
 * `AVG` has no intent here because M4 removes the need for one: the inner query returns
 * `SUM(pageviews)` and `SUM(visits)` separately and the division happens once, at the outer
 * level. A mean of means is not a mean unless every blog has equal weight.
 *
 * @since 6.0.0
 */
class NetworkMerge
{
    /** Additive over rows — the only intent for which a bare SUM is correct. */
    public const SUM = 'sum';

    /** Distinct values, which must be unioned as VALUES and counted once at the outer level. */
    public const DISTINCT = 'distinct';

    /** Distinct values whose identity is only unique WITHIN a blog. */
    public const DISTINCT_PER_BLOG = 'distinct_per_blog';

    /**
     * Is a network-wide merge actually being performed for this request?
     *
     * Free asks; Pro answers. Free must not assume, and must not go looking: the gate is a
     * network-level capability plus an explicit request, and both of those are Pro's to judge
     * (`NetworkScope::isRequested()`).
     *
     * WHY FREE ASKS AT ALL, rather than always building the union-friendly query shape: the
     * DISTINCT intent needs an inner query that returns ROWS instead of a count. On a single
     * site that would turn one `COUNT(DISTINCT ip)` — a single number, computed in the server —
     * into a transfer of every distinct ip on the site, on a path that runs on every report
     * screen. So the expensive shape is built only when something is actually going to union it.
     */
    public static function isMerging(): bool
    {
        return (bool) apply_filters('slimstat_network_merge_active', false);
    }

    /**
     * The merge intent for `count_records($column)`, per M1.
     *
     * Written as a lookup on the column rather than on the SQL text, because the SQL is built
     * from the column and reading the intent back out of the string would be a second parser
     * that can disagree with the first.
     */
    public static function intentForColumn(string $column): string
    {
        if ('id' === $column) {
            return self::SUM;
        }

        if ('visit_id' === $column) {
            return self::DISTINCT_PER_BLOG;
        }

        return self::DISTINCT;
    }

    /**
     * The OUTER aggregate that recombines the union, given an intent and the column beneath it.
     *
     * Returned as the `$_aggregate_value` Pro's `slimstat_get_var_sql` filter already takes, so
     * this adds a vocabulary rather than a second rewriting mechanism.
     *
     * @return string SQL aggregate expression aliased `counthits`, or '' when nothing may merge.
     */
    public static function outerAggregate(string $intent, string $column): string
    {
        switch ($intent) {
            case self::SUM:
                return 'SUM(counthits) AS counthits';

            case self::DISTINCT:
                // `t_union_all` is the alias Pro's rewriter gives the union; the column is
                // whatever the inner query selected.
                return sprintf('COUNT(DISTINCT %s) AS counthits', $column);

            case self::DISTINCT_PER_BLOG:
                // blog_id is added to every inner SELECT by the rewriter, which is what makes
                // this expressible at all.
                return sprintf('COUNT(DISTINCT blog_id, %s) AS counthits', $column);
        }

        return '';
    }

    /**
     * The INNER select list for an intent — the half that decides whether merging is possible.
     *
     * M1's finding in one line: a DISTINCT count cannot be recovered from per-blog counts, so
     * for that intent the inner query must return the values themselves. For SUM it must not:
     * counting in the server and summing N numbers is the whole point.
     *
     * @return string SQL select list for the per-blog inner query.
     */
    public static function innerSelect(string $intent, string $column): string
    {
        if (self::SUM === $intent) {
            return sprintf('COUNT(%s) as counthits', $column);
        }

        // DISTINCT INSIDE EACH ARM TOO, and it is not redundant with the outer COUNT(DISTINCT).
        //
        // The bare column returns one row per PAGEVIEW, not one per distinct value, and MySQL
        // cannot merge a UNION derived table — so the whole thing materialises before the outer
        // aggregate runs. Fifty blogs at 200k in-range rows is ~10M rows into a temporary table
        // that spills to disk, five times per report screen.
        //
        // De-duplicating per arm is semantically identical under an outer COUNT(DISTINCT) — the
        // outer pass still catches values shared BETWEEN blogs, which is the whole reason the
        // union carries values rather than counts (the fixture's 6 network-wide against 7
        // summed) — and it bounds each arm by that blog's cardinality instead of its row count.
        return sprintf('DISTINCT %s', $column);
    }
}
