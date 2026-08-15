<?php
/**
 * The golden fixture is its own oracle, and this proves the oracle is not circular.
 *
 * Every number in `spec.php`'s `expected` block was computed BY HAND, by counting the tables in
 * EXPECTED.md. This file recomputes each one from the expanded rows using trivial array code —
 * no plugin, no SQL, no report class — and fails when the two derivations disagree.
 *
 * That is the entire mechanism, and it is worth stating why it is not ceremony. A fixture whose
 * expected values are produced by running the code under test proves nothing: it asserts the
 * code agrees with itself, which it always will, including on the day it is wrong. Phase D and
 * F9 are exactly the area where wrong answers are plausible — a network total that is off by
 * one shared visitor looks completely normal — so the oracle has to come from somewhere the
 * implementation cannot reach.
 *
 * The traps this fixture is shaped around, each of which yields a DIFFERENT number rather than
 * an error:
 *
 *   - unique visitors are not additive (6 network-wide vs 7 summed per blog: one visitor,
 *     10.0.0.1, is the same person on two subsites)
 *   - bounce rate is not averageable (1/7 = 14.29% vs a 16.67% mean of per-blog rates)
 *   - an archived subsite must contribute nothing (40 vs 46, and /about/ gaining a third row)
 *   - a shared path is TWO rows per P3, not one merged row of 11
 *
 * 7.4-safe: plain PHP, no PHPUnit, no WordPress, no vendor tree.
 */

declare(strict_types=1);

$fixture_dir = __DIR__ . '/fixtures/golden';

require_once $fixture_dir . '/expand.php';

$spec     = require $fixture_dir . '/spec.php';
$expected = $spec['expected'];

$failures = [];

/** Assert a recomputed value equals the hand-computed one. */
$check = static function (string $what, $hand, $recomputed) use (&$failures): void {
    if (is_float($hand) || is_float($recomputed)) {
        if (abs((float) $hand - (float) $recomputed) < 0.000001) {
            return;
        }
    } elseif ($hand === $recomputed) {
        return;
    }

    $failures[] = sprintf(
        '%s: EXPECTED.md says %s, recomputing from the rows gives %s',
        $what,
        var_export($hand, true),
        var_export($recomputed, true)
    );
};

// ── Expand, and sanity-check the expansion itself ───────────────────────────
$all     = slimstat_golden_rows($spec);
$counted = slimstat_golden_counted_rows($spec);

// Vacuity guard, first. Every assertion below is satisfied by an empty row set, so an expander
// that silently returned [] would make this file print PASS while checking nothing.
if (count($all) < 40) {
    fwrite(STDERR, sprintf(
        "FAIL: the expander produced %d rows. Every count below would be vacuously consistent\n"
            . "with an empty fixture — refusing to report on it.\n",
        count($all)
    ));
    exit(1);
}

// ── 1. Volume ───────────────────────────────────────────────────────────────
$check('network pageviews', $expected['pageviews'], count($counted));
$check('pageviews including the archived blog', $expected['pageviews_including_archived'], count($all));

// ── 2. Visitors — the non-additive one ──────────────────────────────────────
$network_ips = [];
$ips_by_blog = [];
foreach ($counted as $row) {
    $network_ips[$row['ip']]                  = true;
    $ips_by_blog[$row['blog_id']][$row['ip']] = true;
}

$summed = 0;
foreach ($ips_by_blog as $ips) {
    $summed += count($ips);
}

$check('network distinct visitors', $expected['distinct_visitors'], count($network_ips));
$check('sum of per-blog distinct visitors', $expected['distinct_visitors_summed_per_blog'], $summed);

// The whole reason the fixture exists: these two must NOT be equal, or it cannot catch the
// defect it was built for.
if (count($network_ips) === $summed) {
    $failures[] = 'network visitors and the per-blog sum are EQUAL, so the fixture cannot '
        . 'distinguish a correct implementation from one that adds per-blog counts. The shared '
        . 'visitor (10.0.0.1 on blogs 1 and 2) has been lost';
}

// ── 3. Visits and bounces — the non-averageable one ─────────────────────────
//
// One pass builds both nested maps; every check below reads them. The first draft re-scanned
// all forty rows once per blog to rebuild facts this pass already holds — in the one file whose
// whole job is to be obviously trivial arithmetic, where being auditable by eye matters more
// than anywhere else in the tree.
$hits_by_blog = [];
$res_by_blog  = [];
foreach ($counted as $row) {
    $hits_by_blog[$row['blog_id']][$row['visit_id']] = ($hits_by_blog[$row['blog_id']][$row['visit_id']] ?? 0) + 1;
    $res_by_blog[$row['blog_id']][$row['resource']]  = ($res_by_blog[$row['blog_id']][$row['resource']] ?? 0) + 1;
}

$is_bounce = static function ($n) {
    return 1 === $n;
};

$visits  = 0;
$bounces = 0;
$rates   = [];
foreach ($hits_by_blog as $blog_visits) {
    $blog_bounces = count(array_filter($blog_visits, $is_bounce));
    $visits      += count($blog_visits);
    $bounces     += $blog_bounces;
    $rates[]      = ($blog_bounces / count($blog_visits)) * 100;
}

$check('network distinct visits', $expected['distinct_visits'], $visits);
$check('bounces', $expected['bounces'], $bounces);
$check('network bounce rate %', $expected['bounce_rate_pct'], ($bounces / $visits) * 100);

// The mean of per-blog rates, which is the WRONG answer, recomputed so the gap is pinned too.
$check('mean of per-blog bounce rates %', $expected['bounce_rate_mean_of_blogs_pct'], array_sum($rates) / count($rates));

if (abs($expected['bounce_rate_pct'] - $expected['bounce_rate_mean_of_blogs_pct']) < 0.01) {
    $failures[] = 'the network bounce rate and the mean of per-blog rates are equal, so the '
        . 'fixture cannot catch an implementation that averages them';
}

// M4 — pages per visit, from the same per-visit counts: the network answer (total over
// total), the mean-of-blog-averages TRAP (what an outer AVG over unioned per-blog AVGs
// computes), and the per-visit MAX, which composes over blogs where AVG does not.
// Total pageviews is count($counted), already pinned above — not re-accumulated here.
$blog_avgs  = [];
$max_single = 0;
foreach ($hits_by_blog as $blog_visits) {
    $blog_avgs[]  = array_sum($blog_visits) / count($blog_visits);
    $max_single   = max($max_single, max($blog_visits));
}
$check('network pages per visit', $expected['pages_per_visit_network'], count($counted) / $visits);
$check('mean of per-blog pages-per-visit (the trap)', $expected['pages_per_visit_mean_of_blogs'], array_sum($blog_avgs) / count($blog_avgs));
$check('max pages in a single visit', $expected['max_pages_single_visit'], $max_single);

if (abs($expected['pages_per_visit_network'] - $expected['pages_per_visit_mean_of_blogs']) < 0.01) {
    $failures[] = 'network pages-per-visit equals the mean of per-blog averages, so the fixture '
        . 'cannot catch an implementation that averages the averages';
}

// M3 — per-blog DISTINCT lists for /about/ (ip as the concat column), plus the trap: the
// cross-blog union a concat over unioned rows would produce.
$about_ips_by_blog = [];
$about_ips_union   = [];
foreach ($counted as $row) {
    if ('/about/' !== $row['resource']) {
        continue;
    }
    $about_ips_by_blog[$row['blog_id']][$row['ip']] = true;
    $about_ips_union[$row['ip']] = true;
}
$about_lists = [];
foreach ($about_ips_by_blog as $blog_id => $ips) {
    $ips = array_keys($ips);
    sort($ips);
    $about_lists[$blog_id] = $ips;
}
ksort($about_lists);
$check('per-blog /about/ ip lists', $expected['about_ip_lists_per_blog'], $about_lists);
$check('cross-blog /about/ ip union (the trap)', $expected['about_ips_merged_wrongly'], count($about_ips_union));

if (count($about_lists) < 2) {
    $failures[] = '/about/ has ip lists on fewer than two blogs, so the fixture cannot catch a '
        . 'concat that mixes visitors across blogs';
}

// ── 4. The shared path — P3 says two rows, not one of eleven ────────────────
$about_by_blog = [];
foreach ($counted as $row) {
    if ('/about/' === $row['resource']) {
        $about_by_blog[$row['blog_id']] = ($about_by_blog[$row['blog_id']] ?? 0) + 1;
    }
}

$check('rows for /about/ in a network report', $expected['about_rows_in_network_report'], count($about_by_blog));
$check('largest single /about/ figure', $expected['about_largest_single_figure'], max($about_by_blog));
$check('/about/ if wrongly merged', $expected['about_merged_wrongly'], array_sum($about_by_blog));

// ── 5. The archived blog contributes nothing ────────────────────────────────
foreach ($counted as $row) {
    if (4 === $row['blog_id']) {
        $failures[] = 'a row from the archived blog 4 survived into the counted set';
        break;
    }
}

$archived_rows = count($all) - count($counted);
if ($archived_rows < 1) {
    $failures[] = 'the archived blog contributes no rows at all, so "it must be excluded" is '
        . 'vacuously true and the exclusion is untested';
}

// ── 6. Per-blog breakdown ───────────────────────────────────────────────────
foreach ($expected['per_blog'] as $blog => $want) {
    $rows = array_values(array_filter($all, static function ($r) use ($blog) {
        return $r['blog_id'] === $blog;
    }));

    $visits = [];
    $ips    = [];
    foreach ($rows as $row) {
        $visits[$row['visit_id']] = ($visits[$row['visit_id']] ?? 0) + 1;
        $ips[$row['ip']]          = true;
    }

    $check("blog {$blog} rows", $want['rows'], count($rows));
    $check("blog {$blog} visits", $want['visits'], count($visits));
    $check("blog {$blog} visitors", $want['visitors'], count($ips));
    $check("blog {$blog} bounces", $want['bounces'], count(array_filter($visits, static function ($n) {
        return 1 === $n;
    })));
}

// ── 7. Per-resource breakdown ───────────────────────────────────────────────
foreach ($expected['resources_per_blog'] as $blog => $want) {
    $seen = [];
    foreach ($all as $row) {
        if ($row['blog_id'] === $blog) {
            $seen[$row['resource']] = ($seen[$row['resource']] ?? 0) + 1;
        }
    }

    ksort($seen);
    ksort($want);
    $check("blog {$blog} resource counts", $want, $seen);
}

// Both sides of the merge trap, from the map built above. The largest per-blog figure is what a
// P3-correct network report shows; the sum is what a merged one shows. Pinning only the first
// left the second as an unread number carrying the authority of "independently recomputed" —
// which is the vacuity this fixture exists to refuse.
$per_resource_totals = [];
foreach ($res_by_blog as $resources) {
    foreach ($resources as $resource => $count) {
        $per_resource_totals[$resource] = ($per_resource_totals[$resource] ?? 0) + $count;
    }
}

$check('top per-blog resource figure', $expected['top_resource_per_blog_max'], max(array_map('max', $res_by_blog)));
$check('top resource if wrongly merged', $expected['top_resource_merged_wrongly'], max($per_resource_totals));

// ── 8. The time axis actually separates the ranges ──────────────────────────
$window = strtotime($spec['window']['ends'] . ' UTC');
$last30 = 0;
$dayC   = 0;
foreach ($counted as $row) {
    if ($row['dt'] >= ($window - 30 * 86400) && $row['dt'] <= $window) {
        $last30++;
    }
    if ('C' === $row['day']) {
        $dayC++;
    }
}

$check('pageviews in the last 30 days', $expected['pageviews_last_30d'], $last30);
$check('pageviews on day C', $expected['pageviews_day_c'], $dayC);

// I8's defect, stated as a property of THIS fixture: a 30-day report and an all-time report
// must return different totals, or no date-range conclusion drawn on it is falsifiable.
if ($last30 === count($counted)) {
    $failures[] = 'the 30-day total equals the all-time total, so this fixture cannot tell a '
        . 'range-filtered report from an unfiltered one — the exact defect that makes the 443k '
        . 'reference dataset useless for range conclusions (I8)';
}

// ── 9. EXPECTED.md must not drift from the spec it documents ────────────────
//
// EXPECTED.md is the document a human reads to check a number, and until this assertion existed
// it was the one with no gate behind it. It had ALREADY drifted: the note celebrating a caught
// 28-vs-35 error still asserted 28 four lines further down. A fixture whose entire claim is
// "two independent derivations" cannot have a third, ungated one (PITFALLS #5).
//
// Every bolded figure in the prose must be a number this fixture actually holds — a right
// answer, a deliberately-stated wrong answer, or a structural count. Bold is the convention the
// document already uses for "this is a claim", so the rule needs no new markup.
$md = (string) file_get_contents($fixture_dir . '/EXPECTED.md');

if ('' === $md) {
    $failures[] = 'EXPECTED.md is unreadable, so its numbers are unchecked';
} else {
    $known = [];
    foreach ($expected as $value) {
        if (is_scalar($value)) {
            $known[] = (float) $value;
        }
    }

    // Structural figures the prose legitimately states that are not aggregate answers: blog
    // ids, per-visit pageview counts from the per-visit table, and the per-day split.
    foreach ([3, 4, 10, 23, 12] as $structural) {
        $known[] = (float) $structural;
    }
    foreach ($expected['per_blog'] as $blog) {
        foreach ($blog as $n) {
            $known[] = (float) $n;
        }
    }
    foreach ($expected['resources_per_blog'] as $resources) {
        foreach ($resources as $n) {
            $known[] = (float) $n;
        }
    }

    preg_match_all('/\*\*([0-9][0-9,]*(?:\.[0-9]+)?)%?\*\*/', $md, $bold);

    foreach (array_unique($bold[1]) as $claim) {
        $value = (float) str_replace(',', '', $claim);

        // Compared numerically at the PRECISION THE PROSE USES, not as a string. The document
        // legitimately rounds — it writes 16.667% for 16.666666666666668 — so a string match
        // would reject correct prose and force the text to carry full float noise. Rounding
        // both sides to the claim's own decimal places accepts any faithful rendering and still
        // rejects a genuinely different number.
        $dot       = strpos($claim, '.');
        $precision = false === $dot ? 0 : strlen($claim) - $dot - 1;

        foreach ($known as $candidate) {
            if (round($candidate, $precision) === round($value, $precision)) {
                continue 2;
            }
        }

        $failures[] = sprintf(
            'EXPECTED.md states **%s**, which is not a value this fixture holds. Either the '
                . 'prose is stale (it has been once) or spec.php is missing a figure the '
                . 'document relies on',
            $claim
        );
    }
}

// ── Report ──────────────────────────────────────────────────────────────────
if ($failures) {
    fwrite(STDERR, 'FAIL: golden fixture (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    fwrite(STDERR, "\nEXPECTED.md holds the hand computation. If a number there is wrong, fix it\n");
    fwrite(STDERR, "there and here together — never silently adopt whatever the code now returns.\n");
    exit(1);
}

printf(
    "PASS: golden fixture — %d counted rows over %d blogs; hand-computed and recomputed values agree\n",
    count($counted),
    count($ips_by_blog)
);
printf(
    "      traps live: visitors %d != %d summed · bounce %.4f%% != %.4f%% averaged · /about/ %d rows not 1 · 30d %d != %d all-time\n",
    $expected['distinct_visitors'],
    $expected['distinct_visitors_summed_per_blog'],
    $expected['bounce_rate_pct'],
    $expected['bounce_rate_mean_of_blogs_pct'],
    $expected['about_rows_in_network_report'],
    $expected['pageviews_last_30d'],
    $expected['pageviews']
);
