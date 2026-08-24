<?php
/**
 * The golden fixture, declared once.
 *
 * EXPECTED.md is the prose and the hand computation; this is the same facts in a form the
 * generator, the arithmetic gate and the environment loader all read. There is exactly one
 * declaration so the data cannot drift from the table people actually read — the same rule the
 * Schema manifest applies to the schema.
 *
 * Rows are declared as (blog, visit, ip, [resource => count]) rather than as forty literal
 * rows. Forty literals are unreadable, and a reviewer checking "does blog 1 have five /about/
 * hits" against a wall of INSERTs is checking nothing. Expanded by expand() below.
 *
 * 7.4-safe, dependency-free: loaded by a standalone gate and by a WP-CLI loader alike.
 */

declare(strict_types=1);

return [
    /**
     * Days chosen so a -30d report and a -90d report differ. A and B fall inside a 30-day
     * window ending 2026-02-10; C falls outside it but inside 90 days. The 443k reference
     * dataset cannot make this distinction at all (its 20x duplication compressed the time
     * axis to ~33 days), which is why every range conclusion on it is currently unfalsifiable.
     */
    'days' => [
        'A' => '2026-01-15 09:00:00',
        'B' => '2026-01-16 14:30:00',
        'C' => '2026-02-20 11:15:00',
    ],

    // Only `ends` is declared: which days fall inside the window is derivable from `days` plus
    // this, and the gate derives it. A stored in_30d/outside_30d list would be a second
    // statement of the same fact, free to disagree with the timestamps it describes.
    'window' => [
        'ends' => '2026-02-10 00:00:00',
    ],

    /**
     * `counted` is the whole point of blog 4: it is archived, so every network figure must
     * exclude it. Its rows sit on the shared path precisely so that including it changes a
     * number a human is looking at.
     */
    'blogs' => [
        1 => ['status' => 'public',   'counted' => true,  'note' => 'main site'],
        2 => ['status' => 'public',   'counted' => true,  'note' => ''],
        3 => ['status' => 'public',   'counted' => true,  'note' => 'holds the only bounce'],
        4 => ['status' => 'archived', 'counted' => false, 'note' => 'must contribute nothing, anywhere'],
    ],

    /**
     * 10.0.0.1 is the SAME person on blogs 1 and 2. That single fact is what makes unique
     * visitors non-additive: 6 network-wide against 7 summed per blog.
     *
     * Visit 302 is the only single-pageview visit, so it is the only bounce: 1/7 network-wide
     * against a 16.667% mean of per-blog rates.
     */
    'visits' => [
        ['blog' => 1, 'visit' => 101, 'ip' => '10.0.0.1', 'day' => 'A', 'hits' => ['/' => 3, '/about/' => 2, '/pricing/' => 1]],
        ['blog' => 1, 'visit' => 102, 'ip' => '10.0.0.2', 'day' => 'B', 'hits' => ['/' => 2, '/about/' => 2, '/pricing/' => 1]],
        ['blog' => 1, 'visit' => 103, 'ip' => '10.0.0.3', 'day' => 'C', 'hits' => ['/' => 1, '/about/' => 1, '/pricing/' => 2]],

        ['blog' => 2, 'visit' => 201, 'ip' => '10.0.0.1', 'day' => 'A', 'hits' => ['/' => 4, '/shop/' => 2, '/contact/' => 1]],
        ['blog' => 2, 'visit' => 202, 'ip' => '10.0.0.4', 'day' => 'B', 'hits' => ['/' => 3, '/shop/' => 2, '/contact/' => 2]],

        ['blog' => 3, 'visit' => 301, 'ip' => '10.0.0.5', 'day' => 'A', 'hits' => ['/about/' => 6, '/' => 2, '/team/' => 2]],
        ['blog' => 3, 'visit' => 302, 'ip' => '10.0.0.6', 'day' => 'C', 'hits' => ['/' => 1]],

        ['blog' => 4, 'visit' => 401, 'ip' => '10.0.0.9', 'day' => 'A', 'hits' => ['/about/' => 4, '/' => 2]],
    ],

    /**
     * Hand-computed in EXPECTED.md by counting the tables there. NOT produced by running the
     * plugin, and not produced by the expander either — golden-fixture-test.php recomputes each
     * from the expanded rows with trivial array code and fails if the two disagree.
     *
     * Two independent derivations of the same numbers is the entire mechanism. One derivation
     * is an assertion about itself.
     */
    'expected' => [
        'pageviews'                 => 40,
        'pageviews_including_archived' => 46,
        'distinct_visitors'         => 6,
        'distinct_visitors_summed_per_blog' => 7,
        'distinct_visits'           => 7,
        'bounces'                   => 1,
        'bounce_rate_pct'           => 14.285714285714286,
        'bounce_rate_mean_of_blogs_pct' => 16.666666666666668,
        'about_rows_in_network_report'  => 2,
        'about_largest_single_figure'   => 6,
        'about_merged_wrongly'      => 11,
        'top_resource_per_blog_max' => 7,
        'top_resource_merged_wrongly' => 16,
        // Hand-ranked network answer for slim_p1_08 (Top Web Pages). P3 preserves blog grain:
        // the same resource on two sites is two rows, not one summed row. Counts descend;
        // resource then blog_id break ties. LIMIT 7 deliberately cuts through a tie at three,
        // so changing either the ordering or the limit changes this fixture's answer.
        'top_resource_ranked' => [
            'report_id' => 'slim_p1_08',
            'limit' => 7,
            'rows' => [
                ['blog_id' => 2, 'resource' => '/',         'counthits' => 7],
                ['blog_id' => 1, 'resource' => '/',         'counthits' => 6],
                ['blog_id' => 3, 'resource' => '/about/',   'counthits' => 6],
                ['blog_id' => 1, 'resource' => '/about/',   'counthits' => 5],
                ['blog_id' => 1, 'resource' => '/pricing/', 'counthits' => 4],
                ['blog_id' => 2, 'resource' => '/shop/',    'counthits' => 4],
                ['blog_id' => 3, 'resource' => '/',         'counthits' => 3],
            ],
        ],
        // M4's three distinguishable answers. Network pages/visit is total pageviews over
        // total visits: 40/7. The TRAP is the mean of per-blog averages — (15/3 + 14/2 +
        // 11/2) / 3 — which weights a two-visit blog the same as a three-visit one; an
        // outer AVG over unioned per-blog AVGs computes exactly that. Main-site-only
        // (today's unrouted answer) is 15/3 = 5 with max 6. Max is a per-visit figure and
        // MAX composes over blogs: visit 301's 10.
        'pages_per_visit_network'       => 5.714285714285714,
        'pages_per_visit_mean_of_blogs' => 5.833333333333333,
        'max_pages_single_visit'        => 10,
        // M3 (Users by Page's mechanism, exercised with ip as the concat column since the
        // fixture tracks no usernames). Under P3's per-blog grain each row's DISTINCT list
        // is ITS OWN blog's — /about/ is two rows with separate lists, never one row whose
        // list mixes visitors across blogs. The TRAP is the cross-blog union: 4 distinct
        // ips in one merged row, which is what a concat over unioned rows would produce.
        'about_ip_lists_per_blog' => [
            1 => ['10.0.0.1', '10.0.0.2', '10.0.0.3'],
            3 => ['10.0.0.5'],
        ],
        'about_ips_merged_wrongly' => 4,
        // Day A = 6 + 7 + 10 = 23, day B = 5 + 7 = 12, day C = 4 + 1 = 5. A+B = 35, and
        // 35 + 5 = 40. The first draft of this said 28 and 12, which do not even sum to 40 —
        // written without doing the arithmetic, and caught by the recomputation, which is the
        // whole reason there are two derivations.
        'pageviews_last_30d'        => 35,
        'pageviews_day_c'           => 5,
        'per_blog' => [
            1 => ['rows' => 15, 'visits' => 3, 'visitors' => 3, 'bounces' => 0],
            2 => ['rows' => 14, 'visits' => 2, 'visitors' => 2, 'bounces' => 0],
            3 => ['rows' => 11, 'visits' => 2, 'visitors' => 2, 'bounces' => 1],
            4 => ['rows' => 6,  'visits' => 1, 'visitors' => 1, 'bounces' => 0],
        ],
        'resources_per_blog' => [
            1 => ['/' => 6, '/about/' => 5, '/pricing/' => 4],
            2 => ['/' => 7, '/shop/' => 4, '/contact/' => 3],
            3 => ['/about/' => 6, '/' => 3, '/team/' => 2],
            4 => ['/about/' => 4, '/' => 2],
        ],
    ],
];
