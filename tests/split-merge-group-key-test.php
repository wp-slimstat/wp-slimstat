<?php
/**
 * Merging the two halves of a midnight-straddling range must not invent or lose rows.
 *
 * `Query::getAll()` splits any range that crosses today's midnight into an immutable
 * historical half and a live half, runs each separately, and merges the results. The merge
 * grouped rows by a key it GUESSED — "the first column that isn't `counthits`" — a single
 * column, regardless of how many columns the query actually grouped by.
 *
 * So a report grouping on (browser, browser_version) was re-keyed on `browser` alone, and
 * every version of a browser collapsed onto whichever one the live half happened to list
 * first. Measured on the real 443k-row table over a straddling range:
 *
 *     key                MERGED    TRUTH
 *     Chrome|150           1952     1195     <- absorbed the others
 *     Chrome|144        MISSING      315
 *     Chrome|125        MISSING      178
 *     Chrome|142        MISSING       59
 *     ... 7 more Chrome rows gone
 *     HeadlessChrome|148      1   absent     <- phantom
 *
 * 1195 + 315 + 178 + 60 + 59 + 40 + 26 + 20 + 20 + 20 + 19 = 1952. The total is preserved,
 * which is why this was invisible: the summary line stays right while the breakdown
 * underneath it is fiction.
 *
 * This is not an edge case. 45 of the 66 registered reports take the split path, and the
 * DEFAULT dashboard view straddles midnight — it is a 28-day window ending *now*.
 *
 * The second casualty is GROUP_CONCAT: `get_group_by()` builds a `;;;`-joined list per
 * group, and the merge kept one half's list and discarded the other's, in whichever
 * direction the arguments happened to be passed.
 *
 * Defect id (D5) lives in the workspace performance notes, outside this repository —
 * deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Utils/Query.php';

$failures = [];

$class = new ReflectionClass(\SlimStat\Utils\Query::class);
$merge = $class->getMethod('mergeGroupResults');

// Required on PHP 7.4, which this plugin still supports; a deprecated no-op from 8.1.
$reflect = static function ($member) {
    if (PHP_VERSION_ID < 80100) {
        $member->setAccessible(true);
    }
    return $member;
};
$reflect($merge);

/** Build a Query whose select list and GROUP BY describe the report being simulated. */
$queryFor = static function (string $fields, string $groupBy) use ($class, $reflect) {
    $q = $class->newInstanceWithoutConstructor();

    $reflect($class->getProperty('fields'))->setValue($q, $fields);
    $reflect($class->getProperty('groupByClause'))->setValue($q, 'GROUP BY ' . $groupBy);

    return $q;
};

/** Index merged rows by a composite key so assertions read like the report does. */
$index = static function (array $rows, array $keyCols): array {
    $out = [];
    foreach ($rows as $r) {
        $k = [];
        foreach ($keyCols as $c) {
            $k[] = (string) ($r[$c] ?? '');
        }
        $out[implode('|', $k)] = $r;
    }
    return $out;
};

// ── 1. A multi-column GROUP BY must stay multi-column ───────────────────────
$q = $queryFor('browser, browser_version, COUNT(*) AS counthits', 'browser, browser_version');

// Chrome 150 appears on both sides; 144 and 125 only in the historical half.
$historical = [
    ['browser' => 'Chrome', 'browser_version' => '150', 'counthits' => 1000],
    ['browser' => 'Chrome', 'browser_version' => '144', 'counthits' => 315],
    ['browser' => 'Chrome', 'browser_version' => '125', 'counthits' => 178],
];
$live = [
    ['browser' => 'Chrome', 'browser_version' => '150', 'counthits' => 195],
];

$rows = $merge->invoke($q, $live, $historical);
$byKey = $index($rows, ['browser', 'browser_version']);

if (count($rows) !== 3) {
    $failures[] = sprintf(
        'merging (browser, browser_version) produced %d row(s) where 3 were grouped — the '
            . 'versions collapsed onto a single browser key. Rows present: %s',
        count($rows),
        implode(', ', array_keys($byKey))
    );
}
foreach (['Chrome|150' => 1195, 'Chrome|144' => 315, 'Chrome|125' => 178] as $key => $expected) {
    $got = isset($byKey[$key]) ? (int) $byKey[$key]['counthits'] : null;
    if ($got !== $expected) {
        $failures[] = sprintf(
            '%s should carry %d hits, got %s — hits from one version are being attributed to '
                . 'another',
            $key,
            $expected,
            null === $got ? 'a missing row' : $got
        );
    }
}

// ── 2. A single-column GROUP BY still works ────────────────────────────────
// The counterweight: fixing the composite key must not break the common case.
$q1 = $queryFor('country, COUNT(*) AS counthits', 'country');
$rows1 = $merge->invoke(
    $q1,
    [['country' => 'us', 'counthits' => 7]],
    [['country' => 'us', 'counthits' => 500], ['country' => 'gb', 'counthits' => 3]]
);
$by1 = $index($rows1, ['country']);

if (count($rows1) !== 2 || (int) ($by1['us']['counthits'] ?? 0) !== 507) {
    $failures[] = 'a single-column GROUP BY no longer merges correctly: expected us=507 and 2 '
        . 'rows, got ' . json_encode($rows1);
}

// ── 2b. A group whose key is NULL is still a group ─────────────────────────
//
// The old merge tested `isset($row[$groupKey])`, which is false for NULL, so any group
// with a NULL key was dropped from the result entirely. On the reference dataset that is
// one country group covering 175 rows: the unsplit query returns 67 countries, the merged
// one returned 66. Silent, and invisible unless you count.
$qn = $queryFor('country, COUNT(*) AS counthits', 'country');
$rowsN = $merge->invoke(
    $qn,
    [['country' => null, 'counthits' => 5], ['country' => 'us', 'counthits' => 2]],
    [['country' => null, 'counthits' => 170], ['country' => 'us', 'counthits' => 8]]
);

if (count($rowsN) !== 2) {
    $failures[] = 'a group with a NULL key was dropped: expected 2 groups, got ' . count($rowsN)
        . ' — isset() is false for NULL, so those rows vanish from the report';
}
$nullRow = null;
foreach ($rowsN as $r) {
    // array_key_exists, not ??: the coalesce operator fires on null, which is exactly the
    // value being looked for — the same confusion between "absent" and "null" that caused
    // the defect this section covers.
    if (array_key_exists('country', $r) && null === $r['country']) {
        $nullRow = $r;
    }
}
if (null === $nullRow || (int) $nullRow['counthits'] !== 175) {
    $failures[] = 'the NULL-key group should carry 175 hits, got '
        . (null === $nullRow ? 'no row at all' : $nullRow['counthits']);
}

// ── 3. MAX() takes the later value, whichever half it came from ────────────
// This was right only by accident: the live half was passed first, so its row won. A
// report whose group appears only in the historical half, or any future caller that passes
// the halves the other way round, silently got a stale timestamp.
$q2 = $queryFor('country, COUNT(*) AS counthits, MAX(dt) AS dt', 'country');
$rows2 = $merge->invoke(
    $q2,
    [['country' => 'us', 'counthits' => 7, 'dt' => 2000]],
    [['country' => 'us', 'counthits' => 500, 'dt' => 1000]]
);
if ((int) ($rows2[0]['dt'] ?? 0) !== 2000) {
    $failures[] = 'MAX(dt) merged to ' . ($rows2[0]['dt'] ?? 'nothing') . ', expected 2000 — '
        . 'the later of the two halves must win';
}

// And the same the other way round, which is what makes it a rule rather than luck.
$rows3 = $merge->invoke(
    $q2,
    [['country' => 'us', 'counthits' => 500, 'dt' => 1000]],
    [['country' => 'us', 'counthits' => 7, 'dt' => 2000]]
);
if ((int) ($rows3[0]['dt'] ?? 0) !== 2000) {
    $failures[] = 'MAX(dt) depends on which half is passed first (got '
        . ($rows3[0]['dt'] ?? 'nothing') . ' instead of 2000) — it must be the larger value, '
        . 'not the first one seen';
}

// ── 4. GROUP_CONCAT is a set, and must be unioned ──────────────────────────
$q3 = $queryFor(
    "ip, COUNT(*) AS counthits, GROUP_CONCAT( DISTINCT resource SEPARATOR ';;;' ) as column_group",
    'ip'
);
$rows4 = $merge->invoke(
    $q3,
    [['ip' => '1.2.3.4', 'counthits' => 3, 'column_group' => '/c']],
    [['ip' => '1.2.3.4', 'counthits' => 100, 'column_group' => '/a;;;/b']]
);
$group = (string) ($rows4[0]['column_group'] ?? '');
$parts = array_filter(explode(';;;', $group));
sort($parts);

if ($parts !== ['/a', '/b', '/c']) {
    $failures[] = 'GROUP_CONCAT merged to "' . $group . '" — one half\'s list was discarded. '
        . 'The two lists describe the same group either side of midnight and must be unioned';
}

// ── 5. Shapes that must not be merged on a guess ───────────────────────────
//
// Every one of these was a real failure mode of an earlier draft that derived the key by
// parsing the GROUP BY clause. Reading the key off the row instead removes all of them at
// once, and these cases are here so that a future "optimisation" back to clause-parsing
// cannot pass quietly.
$shapes = [
    // A qualified GROUP BY name that does not appear as a result column. Parsing the clause
    // yielded the key "t1.country", no row had it, and EVERY row was dropped — a blank
    // report. Reachable through wp_slimstat_db::get_results(), which builds a Query from
    // arbitrary SQL, and through any add-on registering columns => 'x.y'.
    [
        'label'    => 'a GROUP BY naming a qualified column',
        'fields'   => 't1.country, COUNT(*) AS counthits',
        'group'    => 't1.country',
        'a'        => [['country' => 'us', 'counthits' => 5]],
        'b'        => [['country' => 'us', 'counthits' => 2]],
        'expect'   => 1,
        'counts'   => [7],
    ],
    // DISTINCT in the select list. The clause parser recognised browser_version but not
    // "DISTINCT browser", so the key was partial — and a partial key is worse than no key:
    // Chrome/150 and Firefox/150 were fused into one row labelled Chrome carrying Firefox's
    // hits. Fabricated data, not merely lost data.
    [
        'label'    => 'DISTINCT in the select list',
        'fields'   => 'DISTINCT browser, browser_version, COUNT(*) AS counthits',
        'group'    => 'browser, browser_version',
        'a'        => [['browser' => 'Chrome', 'browser_version' => '150', 'counthits' => 10]],
        'b'        => [['browser' => 'Firefox', 'browser_version' => '150', 'counthits' => 20]],
        'expect'   => 2,
        'counts'   => [10, 20],
    ],
    // A GROUP BY expression containing top-level commas — the real shape of slim_p1_16
    // (Languages) and slim_p1_10 (Top Referring Domains).
    [
        'label'    => 'a GROUP BY expression containing commas',
        'fields'   => 'SUBSTRING( language, 1, 2 ) AS lang, COUNT(*) AS counthits',
        'group'    => 'SUBSTRING( language, 1, 2 )',
        'a'        => [['lang' => 'en', 'counthits' => 7]],
        'b'        => [['lang' => 'en', 'counthits' => 3]],
        'expect'   => 1,
        'counts'   => [10],
    ],
];

foreach ($shapes as $shape) {
    $rows = $merge->invoke($queryFor($shape['fields'], $shape['group']), $shape['a'], $shape['b']);
    if (count($rows) !== $shape['expect']) {
        $failures[] = sprintf(
            '%s: expected %d row(s), got %d — %s',
            $shape['label'],
            $shape['expect'],
            count($rows),
            0 === count($rows)
                ? 'every row was dropped, which renders the report blank'
                : 'rows were fused or split incorrectly'
        );
        continue;
    }
    $got = array_map(static function ($r) {
        return (int) $r['counthits'];
    }, $rows);
    sort($got);
    $want = $shape['counts'];
    sort($want);
    if ($got !== $want) {
        $failures[] = $shape['label'] . ': hits came out as ' . json_encode($got)
            . ' instead of ' . json_encode($want);
    }
}

// COUNT(DISTINCT x) is not additive — a visitor seen in both halves is one visitor.
$qd = $queryFor('country, COUNT(DISTINCT ip) AS uniques, COUNT(*) AS counthits', 'country');
$rowsD = $merge->invoke(
    $qd,
    [['country' => 'us', 'uniques' => 10, 'counthits' => 1]],
    [['country' => 'us', 'uniques' => 7, 'counthits' => 1]]
);
if ((int) ($rowsD[0]['uniques'] ?? 0) === 17) {
    $failures[] = 'COUNT(DISTINCT ip) was summed to 17 — a value present in both halves gets '
        . 'counted twice, so the two partition results cannot be added';
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: split-merge group key (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: split-merge group key (composite GROUP BY preserved; MAX takes the larger value; "
    . "GROUP_CONCAT unioned)\n";
exit(0);
