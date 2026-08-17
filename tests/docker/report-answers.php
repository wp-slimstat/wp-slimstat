<?php
// Dump the ANSWERS a set of reports gives, as stable JSON, for before/after comparison.
//
// The opening PHP tag IS required here and `declare(strict_types=1)` is NOT. WP-CLI's eval-file
// evaluates a close-tag followed by the file's contents, so a file lacking an opening tag is
// echoed as text — which is how this first ran, with the comparison reading this file's own
// source as its data — and a declare() inside that wrapper is no longer the script's first
// statement and fatals.
//
// This comment does not spell that close-tag literally, because doing so ends the PHP block
// even inside a line comment. That was the second failure of this file, and it produced the
// identical symptom as the first: source text where JSON was expected.
//
// WHY. Counting statements tells you what a change COST. It says nothing about whether the
// numbers moved. A refactor that halves the query count and quietly changes a total is worse
// than the code it replaced, and no gate in this programme could have seen it: the unit tests
// exercise the new path only, and the topology probe checks a single aggregate.
//
// So this asks the real reports, against the real seeded corpus, and prints the answers. Two
// arms, same database, diffed. Any difference is either a defect or an Expected-Diff entry —
// never a shrug.
//
// DETERMINISM is the whole contract here. Every report below is ordered, and the values are
// emitted in a fixed key order, so a byte diff between arms means the ANSWER changed and not
// that a hash map iterated differently. Reports that depend on the current clock are pinned to
// an explicit range for the same reason.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

$db_file = WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
if (!class_exists('wp_slimstat_db') && is_readable($db_file)) {
    require_once $db_file;
}

if (!class_exists('wp_slimstat_db')) {
    WP_CLI::error('wp_slimstat_db is not loadable');
}

if (method_exists('wp_slimstat_db', 'init')) {
    wp_slimstat_db::init();
}

// ── era-blind helpers ───────────────────────────────────────────────────────
//
// NO VERSION SNIFFING, anywhere below. SLIMSTAT_ANALYTICS_VERSION is read exactly once, for
// _arm_version, and never for a branch. A version compare answers "which era am I in", which is
// the instrument stating a belief about the arm and then measuring its own belief; reflection
// answers "is this method here, and how many parameters does it declare", which is checkable
// against the arm in front of us and is what every decision here actually needs.

/**
 * Feature detection, memoised.
 *
 * `params` is load-bearing rather than decoration. get_goal_results() declares ONE parameter on
 * origin/development (admin/view/wp-slimstat-db.php:1729) and TWO here (:2227), and PHP accepts a
 * surplus argument to a userland function without error — so a try/catch around a two-argument
 * call succeeds on both arms and silently drops the scope on one. Arity is the only honest gate.
 *
 * Two fields, not four: the spec's shape also declared `names` and `static`, and nothing reads
 * them. A probe result nothing consults is the same premature seam as a helper with no call site —
 * ADR-E1's rule, applied to a return value. Add them at the surface that needs them.
 *
 * @return array{exists:bool, params:int}
 */
function slimstat_probe($class, $method)
{
    static $memo = [];

    $k = $class . '::' . $method;
    if (isset($memo[$k])) {
        return $memo[$k];
    }

    // method_exists() answers false for an undefined CLASS as well as an undefined method, so the
    // absent-class arm needs no separate guard — which is why the callers below do not carry one.
    if (!method_exists($class, $method)) {
        return $memo[$k] = ['exists' => false, 'params' => 0];
    }

    $r = new ReflectionMethod($class, $method);

    return $memo[$k] = [
        'exists' => true,
        'params' => $r->getNumberOfParameters(),
    ];
}

/**
 * Drop every cached answer, so the next capture is a MEASUREMENT and not a replay.
 *
 * One owner for the two statements, at the third copy of them — the timing loop's pre-run and
 * per-repetition purges are the other two, and the capture block was about to be a fourth.
 *
 * Both statements, not one: wp_cache_flush() clears the in-memory copy, and with no external
 * object cache the next read comes straight back out of wp_options, which is what made a
 * flush-only version measure 0.22-0.38 ms GROUP BYs. `_arm_caps.ext_object_cache` records which
 * of those two worlds the arm ran in, because the DELETE is load-bearing in exactly one of them.
 *
 * Called PER CAPTURE rather than once above the sequence, and that is not caution: get_results()
 * reads a transient keyed on md5 of the SQL for every select on slim_stats, and six of the timed
 * entries re-issue SQL an earlier capture already ran. A single hoisted purge would be correct
 * only while no two surfaces share a query — an invariant this file already breaks and is about
 * to break forty more times.
 */
function slimstat_purge_report_cache()
{
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    $GLOBALS['wpdb']->query(
        "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\\_transient\\_%slimstat%'"
            . " OR option_name LIKE '\\_transient\\_timeout\\_%slimstat%'"
    );
}

/**
 * Fingerprint a plugin directory with the algorithm frozen at the provenance block below.
 *
 * FROZEN in the strong sense: compare-answers.sh:223-226 FAILS the run when the two arms'
 * fingerprints match, so changing what this hashes changes what that control means. It is the
 * only thing in the artifact that establishes two different revisions ran at all — a harness that
 * failed to swap arms produces two identical files, which is simultaneously the strongest possible
 * "equivalent" and the most likely false positive.
 *
 * The asymmetry in the two entry shapes is part of the frozen output, not an oversight: directory
 * entries carry a leading slash because they come from substr($path, strlen($root)), and named
 * files carry none because they are recorded under the name the caller passed.
 *
 * @return array{fingerprint:string, files:int}
 */
function slimstat_fingerprint($root, array $dirs, array $files, array $skip = ['/Dependencies/'])
{
    $hash = [];

    foreach ($dirs as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/' . $dir,
            RecursiveDirectoryIterator::SKIP_DOTS
        ));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php') {
                continue;
            }
            foreach ($skip as $fragment) {
                if (false !== strpos($path, $fragment)) {
                    continue 2;
                }
            }
            $hash[] = substr($path, strlen($root)) . ':' . md5_file($path);
        }
    }

    foreach ($files as $file) {
        // 'MISSING' rather than a silent skip. Dropping the entry would change the FILE COUNT,
        // which is the one number an operator reads to sanity-check a hash they cannot read, and
        // a tool that quietly ignores what it was handed is how a whole run measured the wrong
        // corpus and said so confidently.
        $hash[] = $file . ':' . (is_file($root . '/' . $file) ? md5_file($root . '/' . $file) : 'MISSING');
    }

    sort($hash);

    return ['fingerprint' => md5(implode('|', $hash)), 'files' => count($hash)];
}

/**
 * Read a plugin's declared version out of its header comment.
 *
 * Pro defines no version constant of its own: the only two VERSION reads in wp-slimstat-pro.php
 * (:84-85) are of the FREE plugin's SLIMSTAT_ANALYTICS_VERSION, so the header is the only place
 * Pro's own version is written down.
 *
 * Core's get_file_data() does this, and an earlier draft hand-rolled the regex instead over a
 * docblock claiming get_plugin_data() sat "behind the admin bootstrap this instrument never
 * loads". That was FALSE twice: get_file_data() lives in wp-includes/functions.php and is always
 * loaded, and wp-slimstat's own src/Constants.php requires wp-admin/includes/plugin.php whenever
 * get_plugin_data() is missing — so the admin file is present here too. The hand-rolled version
 * also dropped three things core does: CR-only line endings, an optional `<?php` prefix, and
 * stripping a trailing `*​/` that a bare trim() leaves attached to the version.
 *
 * get_plugin_data() is still the wrong call, for the reason the old comment should have given:
 * its availability is a side effect of the ARM's Constants.php, so using it would make the
 * instrument's behaviour depend on the code under test. get_file_data() with an empty $context
 * fires no filters at all, which keeps this read arm-independent.
 */
function slimstat_plugin_header_version($file)
{
    if (!is_file($file)) {
        return null;
    }

    $data = get_file_data($file, ['Version' => 'Version']);

    return ('' === $data['Version']) ? null : $data['Version'];
}

/**
 * THE capture. One report call, classified four ways and never conflated.
 *
 * `empty` versus `error` is the distinction this exists for. get_results() answers `[]` for a
 * query that FAILED exactly as it does for one that found nothing, with show_errors off in this
 * container — which is how uniques_browser and uniques_country compared equal in every arm of
 * every run for the weeks they were live: both empty, neither having ever run. The comment above
 * them described a coverage the code did not have, and no gate here had an opinion about it.
 *
 * The detector is $GLOBALS['EZSQL_ERROR'], not wpdb::$last_error, for three reasons that are all
 * properties of WordPress CORE and therefore identical under both arms: wpdb::query() calls
 * print_error() unconditionally when last_error is set; print_error() appends to $EZSQL_ERROR
 * BEFORE its suppress_errors early return; and that entry survives a later successful query
 * resetting last_error, which is precisely the state this instrument inspects it in.
 *
 * The error test runs FIRST and does not consult the return value, because the entire point is
 * that a failed query's return value is indistinguishable from a successful empty one.
 */
function slimstat_capture(callable $fn, array $flags = [])
{
    // Purged BEFORE the error counter is read, never after — the DELETE is itself a query, and an
    // error raised by the purge attributed to the surface would be a lie about which code failed.
    slimstat_purge_report_cache();

    $e0 = (isset($GLOBALS['EZSQL_ERROR']) && is_array($GLOBALS['EZSQL_ERROR'])) ? count($GLOBALS['EZSQL_ERROR']) : 0;

    try {
        $value  = $fn();
        $thrown = null;
    } catch (\Throwable $t) {
        $value  = null;
        $thrown = get_class($t) . ': ' . $t->getMessage();
    }

    $e1 = (isset($GLOBALS['EZSQL_ERROR']) && is_array($GLOBALS['EZSQL_ERROR'])) ? count($GLOBALS['EZSQL_ERROR']) : 0;

    $env = [
        'class'         => 'ok',
        'value'         => $value,
        'rows'          => is_array($value) ? count($value) : null,
        'scalar'        => (is_int($value) || is_float($value) || is_string($value)) ? $value : null,
        'error'         => null,
        '__unsupported' => null,
        // array_merge, not `+`: it fixes key ORDER as well as presence, and a fixed order is what
        // makes _arm_status a table rather than a bag of differently-shaped rows.
        'flags'         => array_merge(
            ['clock_dependent' => false, 'calendar_day_dependent' => false, 'pinned' => false],
            $flags
        ),
    ];

    if ($thrown !== null || $e1 > $e0) {
        $env['class'] = 'error';

        $str   = $thrown;
        $query = null;
        if ($e1 > $e0 && isset($GLOBALS['EZSQL_ERROR'][$e1 - 1])) {
            $last  = (array) $GLOBALS['EZSQL_ERROR'][$e1 - 1];
            $ez    = isset($last['error_str']) ? (string) $last['error_str'] : '';
            $str   = (null === $thrown) ? $ez : $thrown . ' | ' . $ez;
            $query = isset($last['query']) ? substr((string) $last['query'], 0, 300) : null;
        }

        // Truncated because these land in _arm_status, which run-rollup-floor.sh:127 compares
        // byte-for-byte between two passes of the same arm; an unbounded error_str is a long way
        // to carry something that varies.
        $env['error'] = [
            'str'   => (null === $str) ? null : substr($str, 0, 300),
            'query' => $query,
            'count' => $e1 - $e0,
        ];
    } elseif ([] === $value || null === $value || '' === $value) {
        // `[] === $value` is strict, so it already implies the array test an earlier draft put in
        // front of it. Nothing here migrates into the zero branch either: is_numeric() is false for
        // all three, and '0' stays `zero` because this comparison is strict.
        $env['class'] = 'empty';
    } elseif (is_numeric($value) && 0.0 === (float) $value) {
        $env['class'] = 'zero';
    }

    return $env;
}

/** Normalise a report row set into a plain, ordered array of arrays. */
$rows = static function ($result) {
    $out = [];
    foreach ((array) $result as $row) {
        $row = (array) $row;
        ksort($row);
        $out[] = $row;
    }

    return $out;
};

$answers    = [];
$arm_status = [];

/**
 * Emit one capture at its LEGACY key and its envelope-minus-value at _arm_status[key].
 *
 * The legacy key keeps the bare list / bare int it has always held — compare-answers.sh diffs
 * those values directly, and every one of them is frozen. What the envelope adds is the thing the
 * document could not previously say: whether that bare `[]` means "found nothing" or "the query
 * failed and get_results() reported the failure as nothing".
 */
$capture = static function ($key, callable $fn, array $flags = []) use (&$answers, &$arm_status) {
    $env = slimstat_capture($fn, $flags);

    $answers[$key] = $env['value'];
    unset($env['value']);
    $arm_status[$key] = $env;
};

// ── provenance, so the artifact can prove two revisions actually ran ────────
// A blind auditor pointed out that nothing IN the files recorded which code produced them: a
// harness that silently failed to switch arms, or ran one revision twice, would emit two
// identical files indistinguishable from a genuine equivalence result. The strongest possible
// "identical" is then also the most likely false positive. This makes that detectable.
$answers['_arm_version'] = defined('SLIMSTAT_ANALYTICS_VERSION') ? SLIMSTAT_ANALYTICS_VERSION : 'unknown';

// Hashed over the SHIPPED PHP surface — src/, admin/ and the main file — not one file.
//
// A single-file hash said "these arms are the same code" for two genuinely different commits
// whose difference lay elsewhere, and aborted a valid run. It would also have missed the
// opposite: a change confined to src/ leaving the fingerprinted file untouched, so two different
// arms would have looked identical and the abort would never have fired at all.
//
// Vendor is excluded deliberately: it is normalised between arms on purpose (the autoloader is
// rebuilt per arm), so including it would report a difference the harness itself created.
$slimstat_free = slimstat_fingerprint(WP_PLUGIN_DIR . '/wp-slimstat', ['src', 'admin'], ['wp-slimstat.php']);

$answers['_arm_fingerprint'] = $slimstat_free['fingerprint'];
$answers['_arm_files']       = $slimstat_free['files'];

// ── what this arm CAN be asked ──────────────────────────────────────────────
//
// Feature detection, recorded rather than assumed, and every value below is a FIXED-SHAPE fact
// about the code in front of us — nothing here reads a version to decide anything.
//
// Its purpose is to make an absence auditable. A surface that this arm cannot drive is otherwise
// indistinguishable in the artifact from one nobody wrote a capture for, and the second is a gap
// in the instrument wearing the first's excuse. `count_exit_pages` present on one arm and gone on
// the other IS the finding for that surface; `get_goal_results_arity` 1 versus 2 is why the goal
// captures may only ever be called with one argument, since PHP would accept the second on both
// arms and silently ignore the scope on one.
//
// The three multiplier entries at the end record conditions the campaign must be able to prove
// were OFF rather than merely believed to be off: a network merge that never engaged, a Pro
// custom-DB handle that was never swapped in, and the geolocation setting that gates a free
// report elsewhere.
$slimstat_chart_class = 'SlimStat\\Modules\\Chart';
$slimstat_analytics_handle = (class_exists('wp_slimstat') && property_exists('wp_slimstat', 'wpdb') && is_object(wp_slimstat::$wpdb))
    ? wp_slimstat::$wpdb
    : null;

// One root, one predicate, read by both this block and the _arm_pro block below — so the two
// cannot disagree in the artifact about whether Pro was there. Network-activated Pro counts:
// run-topology.sh builds real multisite networks, and a site-only check would report
// "Pro is off" inside the very block whose job is proving what was off.
$slimstat_pro_root   = WP_PLUGIN_DIR . '/wp-slimstat-pro';
$slimstat_pro_file   = 'wp-slimstat-pro/wp-slimstat-pro.php';
$slimstat_pro_active = in_array($slimstat_pro_file, (array) get_option('active_plugins'), true)
    || array_key_exists($slimstat_pro_file, (array) get_site_option('active_sitewide_plugins', []));

$slimstat_caps = [
    'count_exit_pages'         => slimstat_probe('wp_slimstat_db', 'count_exit_pages')['exists'],
    'live_window_end'          => slimstat_probe('wp_slimstat_db', 'live_window_end')['exists'],
    'get_goal_results_arity'   => slimstat_probe('wp_slimstat_db', 'get_goal_results')['params'],
    'get_funnel_results_arity' => slimstat_probe('wp_slimstat_db', 'get_funnel_results')['params'],
    'chart_data_path'          => slimstat_probe($slimstat_chart_class, 'fetchChartData')['exists'],
    'pro_installed'            => is_dir($slimstat_pro_root),
    'pro_active'               => $slimstat_pro_active,
    // PHP 8.2+. Without it a per-report peak-memory figure is the PROCESS high-water mark wearing
    // a report's name, which is the memory equivalent of an empty report comparing equal.
    'memory_reset_peak_usage'  => function_exists('memory_reset_peak_usage'),
    // Which of the two cache worlds this arm ran in. The purge helper's DELETE is load-bearing in
    // exactly one of them, so the precondition every classification rests on is only auditable
    // with this recorded beside it.
    'ext_object_cache'         => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache(),
    'network_merge_active'     => (bool) apply_filters('slimstat_network_merge_active', false),
    'geolocation_country'      => (class_exists('wp_slimstat') && isset(wp_slimstat::$settings['geolocation_country']))
        ? wp_slimstat::$settings['geolocation_country']
        : null,
    // null, not `true`, when the analytics handle cannot be resolved: "the two handles are the
    // same object" and "there was no second handle to compare" are different statements, and only
    // the first licenses reading a query count as a single database's work.
    '_handles'                 => [
        'same_object' => (null === $slimstat_analytics_handle) ? null : ($slimstat_analytics_handle === $GLOBALS['wpdb']),
    ],
];

// ── the Pro arm, or the reason there isn't one ──────────────────────────────
//
// Never a missing key. An absent `_arm_pro` reads as "nobody looked"; the literal below says who
// decided not to and where. Fixed string, never sprintf'd with a path or a version, because
// run-rollup-floor.sh:127 compares this document byte-for-byte between two passes of the same arm
// and a formatted reason is a wall clock waiting to happen.
if (!is_dir($slimstat_pro_root)) {
    // OBSERVATIONAL, not an explanation. An earlier draft named the caller ("compare-answers.sh
    // never calls build_pro_arm()") — which is a fact about who configured the cell, not about
    // what this instrument saw, and would be a confident lie under run-topology.sh or any future
    // caller that does set ARM_PRO_ZIP. The instrument observed a directory; that is all it says.
    $slimstat_caps['_arm_pro'] = ['__unsupported' =>
        'no wp-slimstat-pro directory in this arm\'s plugin folder; nothing Pro-side was measured.'];
} else {
    // vendor/ and build/ excluded for the same reason the free hash excludes vendor/: the arm's
    // Pro is a php-scoper OUTPUT, rebuilt per arm by build-pro.sh, so hashing it would report a
    // difference the harness itself created.
    $slimstat_caps['_arm_pro'] = slimstat_fingerprint(
        $slimstat_pro_root,
        ['src'],
        ['wp-slimstat-pro.php'],
        ['/vendor/', '/build/', '/node_modules/', '/Dependencies/']
    ) + [
        'version'       => slimstat_plugin_header_version($slimstat_pro_root . '/wp-slimstat-pro.php'),
        'active'        => $slimstat_pro_active,
        '__unsupported' => null,
    ];
}

// ── scalar counts ───────────────────────────────────────────────────────────
// Date filters off throughout: the corpus is seeded relative to "now", and a report whose
// window moves between two arms run minutes apart would diff for a reason that is not the code.
$capture('count_records_id', static function () { return (int) wp_slimstat_db::count_records('id', '', false); });
$capture('count_records_ip', static function () { return (int) wp_slimstat_db::count_records('ip', '', false); });
$capture('count_records_visit_id', static function () { return (int) wp_slimstat_db::count_records('visit_id', '', false); });
$capture('count_records_resource', static function () { return (int) wp_slimstat_db::count_records('resource', '', false); });
$capture('count_human_hits', static function () { return (int) wp_slimstat_db::count_records('id', 'browser_type <> 1', false); });

// ── top reports — the GROUP BY paths, which is where cardinality bites ──────
foreach (['resource', 'browser', 'country', 'platform', 'referer'] as $column) {
    $capture('top_' . $column, static function () use ($rows, $column) {
        return $rows(wp_slimstat_db::get_top([
            'columns'          => $column,
            'use_date_filters' => false,
        ]));
    });
}

// ── the ALIASED class: an expression column wearing as_column ───────────────
// The plain-column loop above can never see this class, and it went live broken
// (D72, measured 2026-08-16 — the full story sits on get_top()'s tie-break
// comment). These mirror the three production reports debug.log recorded
// failing, as PINNED LITERALS rather than reads of wp_slimstat_reports::$reports
// on purpose: slim_p1_10's real WHERE derives from home_url(), and this probe's
// answers must not move with the environment or with a report edit. On a
// defective tree all three return [] and the hollow-report gate below fails the
// arm loudly instead of letting two empties compare equal.
$aliased_shapes = [
    'top_referer_domains' => [
        'columns'   => 'REPLACE( SUBSTRING_INDEX( ( SUBSTRING_INDEX( ( SUBSTRING_INDEX( referer, "://", -1 ) ), "/", 1 ) ), ".", -5 ), "www.", "" )',
        'as_column' => 'referer',
        'where'     => 'referer IS NOT NULL',
    ],
    'top_platform_prefixed' => [
        'columns'   => 'CONCAT("p-", SUBSTRING(platform, 1, 3))',
        'as_column' => 'platform',
    ],
    'top_resource_trimmed' => [
        'columns'   => 'TRIM(TRAILING "/" FROM resource)',
        'as_column' => 'resource',
    ],
];
foreach ($aliased_shapes as $key => $shape) {
    $capture($key, static function () use ($rows, $shape) {
        return $rows(wp_slimstat_db::get_top($shape + ['use_date_filters' => false]));
    });
}

// ── unique visitors per dimension — a metric that is NOT COUNT(*) ──────────
// Added because a blind audit found every measure in the set was `counthits`, so the
// pageviews-versus-uniques distinction — the classic confusion in this plugin, and the one F9's
// golden fixture is shaped around — could have been rewritten entirely without a single answer
// moving. get_top_aggr walks a different code path from get_top.
// THESE TWO RETURNED `[]` FROM THE DAY THEY WERE ADDED, in every arm of every run, and a blind
// adjudicator found it by asking why two reports were empty while their siblings had 59 and 105
// rows. The call was wrong, not the function: `$_outer_select_column` is a column to GROUP BY,
// and it was handed `COUNT(DISTINCT visit_id) AS counthits`. That renders
//
//     SELECT COUNT(DISTINCT visit_id) AS counthits, ts1.aggrid AS browser, COUNT(*) AS counthits
//       … GROUP BY COUNT(DISTINCT visit_id) AS counthits
//
// — a duplicate alias AND a GROUP BY over an aggregate. Invalid, and `get_results()` answers
// `[]` for a failed query exactly as it does for one that found nothing, with `show_errors` off
// in this container. So the reports added specifically to stop `get_top_aggr()` being rewritten
// without a single answer moving were themselves incapable of moving. The comment above them
// described a coverage the code did not have.
//
// The real shape, from `slim_p4_24` "Top Exit Pages": collapse to ONE ROW PER VISIT with
// MAX(id) — the visit's last pageview — then group and count. `COUNT(*)` over that is visits,
// not pageviews, which is the distinction this block exists for.
foreach (['browser', 'country'] as $column) {
    // ARRAY FORM, so `use_date_filters` can be turned off — every other answer in this file is
    // clock-independent by construction and these two must be too. The scalar form cannot
    // express it, and until this run get_top_aggr() PARSED the flag and never consulted it, so
    // an unfiltered aggregate was silently a 28-day one. Left as it was, a run crossing local
    // midnight would report DIFFERENCES on these two with no code difference at all — in a
    // harness that escalates any difference to "a defect or an EXPECTED-DIFFS entry".
    $capture('uniques_' . $column, static function () use ($rows, $column) {
        return $rows(wp_slimstat_db::get_top_aggr([
            'columns'             => 'visit_id',
            'where'               => 'visit_id > 0',
            'outer_select_column' => $column,
            'aggr_function'       => 'MAX',
            'use_date_filters'    => false,
        ]));
    });
}

// ── a FILTERED query — the WHERE-builder, otherwise untouched ──────────────
// Also from that audit: every report above is an unfiltered GROUP BY, so the filter/segment
// path — the largest and riskiest surface in a reports layer — was entirely unmeasured.
$capture('top_resource_human', static function () use ($rows) {
    return $rows(wp_slimstat_db::get_top([
        'columns'          => 'resource',
        'where'            => 'browser_type <> 1 AND resource IS NOT NULL',
        'use_date_filters' => false,
    ]));
});

// ── a HAVING path — the bounce numerator ────────────────────────────────────
$capture('bouncing_visits', static function () {
    return (int) wp_slimstat_db::count_records_having(
        'visit_id',
        'visit_id > 0 AND browser_type <> 1',
        'COUNT(id) = 1'
    );
});

// ── an explicit date window, so range filtering is compared too ─────────────
// Pinned to absolute bounds rather than "last 30 days": two arms run minutes apart would
// otherwise select different rows and diff for a reason that is not the change.
$end   = (int) getenv('SLIMSTAT_ANSWERS_END');
$start = (int) getenv('SLIMSTAT_ANSWERS_START');
if ($end > 0 && $start > 0) {
    // The two bounds are the caller's ARGUMENTS, not measurements — no query runs to produce
    // them, so they get no envelope and no _arm_status row. A capture record for a value this
    // file was handed would be a status about nothing.
    $answers['window_start'] = $start;
    $answers['window_end']   = $end;

    $capture('rows_in_window', static function () use ($start, $end) {
        return (int) wp_slimstat_db::count_records(
            'id',
            'dt BETWEEN ' . $start . ' AND ' . $end,
            false
        );
    }, ['pinned' => true]);

    $capture('top_resource_in_window', static function () use ($rows, $start, $end) {
        return $rows(wp_slimstat_db::get_top([
            'columns'          => 'resource',
            'where'            => 'dt BETWEEN ' . $start . ' AND ' . $end,
            'use_date_filters' => false,
        ]));
    }, ['pinned' => true]);
}

// ── the chart module, through its own data path ─────────────────────────────────────
//
// Charts were the one report family OUTSIDE this answer set. Run 25 licensed folding
// their totals query into the buckets query (WITH ROLLUP) — and a licence without a
// parity surface is how a silent answer change ships. Reflection, stated in the open:
// the module's public surfaces render HTML or read $_POST, and an instrument may open
// the private data path it measures. Two windows, historical on purpose (ends before
// today), so neither the live-window quantisation nor the clock moves the capture.
$chart_capture = static function (int $c_start, int $c_end) {
    $chart = new \SlimStat\Modules\Chart();
    $norm  = new ReflectionMethod($chart, 'normalizeArgs');
    $norm->setAccessible(true);
    $fetch = new ReflectionMethod($chart, 'fetchChartData');
    $fetch->setAccessible(true);

    return $fetch->invoke($chart, $norm->invoke($chart, ['start' => $c_start, 'end' => $c_end]));
};

$chart_today = strtotime(date('Y-m-d 00:00:00'));
$chart_end   = min($end > 0 ? $end : $chart_today - 1, $chart_today - 1);
// calendar_day_dependent, not clock_dependent: both windows END at $chart_end, which is derived
// from local midnight, so the capture is stable for a whole day and moves only when the two arms
// straddle one. That is a different hazard from a report anchored on now() and is recorded as a
// different flag — conflating them is how a run that crossed midnight would be read as a defect.
$capture('chart_daily', static function () use ($chart_capture, $chart_end) {
    return $chart_capture($chart_end - 5 * 86400 + 1, $chart_end);
}, ['calendar_day_dependent' => true, 'pinned' => true]);

$capture('chart_weekly', static function () use ($chart_capture, $chart_end) {
    return $chart_capture($chart_end - 60 * 86400 + 1, $chart_end);
}, ['calendar_day_dependent' => true, 'pinned' => true]);

// ── how each answer above got its value ─────────────────────────────────────
//
// Sorted for the same reason $answers is: a fixed key order means a byte diff between two arms
// is the STATUS changing and not a hash map iterating differently.
//
// This is the record the answers document could never carry. `top_referer_domains: []` is a
// legitimate answer, a corpus that cannot exercise the report, and a query that errored into a
// well-formed empty array — three different findings that compare equal to each other and to
// themselves. `_arm_status.top_referer_domains.class` separates them.
ksort($arm_status);
$slimstat_caps['_arm_status'] = $arm_status;

ksort($answers);

// ── every report must have said SOMETHING ──────────────────────────────────
//
// "18 reports, every answer byte-for-byte equal" is a strong claim and an empty report satisfies
// it perfectly. Two of these returned `[]` from the day they were added — a malformed call to
// get_top_aggr() that `get_results()` reports identically to "found nothing", with show_errors
// off in this container — so for every run since, two of the eighteen were identical because
// neither of them ran.
//
// Found by a blind adjudicator asking why two reports were empty while their siblings had 59 and
// 105 rows on the same 150,000 records. Not by this harness, which had no opinion about it.
//
// Scalars are exempt from the emptiness rule but not from the check: a count of 0 is a real
// answer on an empty corpus, and the row-count controls in seed-bench.sh already refuse one.
// Derived from the STATUS, not re-derived from the values. An earlier draft asked
// `is_array($value) && [] === $value` here, which reads the answer a second time and reaches a
// different verdict than slimstat_capture() did about the same call: a query that FAILED into a
// well-formed empty array was reported to the operator as "returned no rows — either the corpus
// cannot exercise them or the call is malformed", the exact conflation this seam exists to end.
// The classifier already knows which; this reads its answer.
//
// A THROWN surface is fatal here too, and that is not extra strictness — it is restoring what the
// try/catch took away. Before the envelope existed, an exception killed the script outright; now
// it lands as null, and a null is not an array, so the old shape would have skipped it silently.
$hollow  = [];
$errored = [];
foreach ($arm_status as $key => $env) {
    if ('empty' === $env['class'] && null === $env['scalar']) {
        $hollow[] = $key;
    } elseif ('error' === $env['class']) {
        $errored[] = $key;
    }
}

if ($errored !== []) {
    fwrite(STDERR, sprintf(
        "SLIMSTAT-SURFACE-ERROR FAIL: %d report(s) errored — %s.\nAn errored report is not an "
            . "empty one: get_results() answers [] for a query that FAILED exactly as it does for "
            . "one that found nothing, so this arm's answers cannot be compared to anything until "
            . "the SQL is fixed. _arm_status carries the error string and the statement.\n",
        count($errored),
        implode(', ', $errored)
    ));
    exit(1);
}

if ($hollow !== []) {
    // Marked distinctly, because the caller captures this arm's whole output into a .raw file and
    // surfaces the failure as "the arm produced no answers" — the same text an activation or
    // autoloader failure produces. Without the marker the operator has to open the raw file to
    // learn whether the arm failed to BOOT or merely failed to MEASURE, which are different
    // problems with different fixes.
    fwrite(STDERR, sprintf(
        "SLIMSTAT-HOLLOW-REPORT FAIL: %d report(s) returned no rows — %s.\nAn empty report compares equal to an empty "
            . "report, so it cannot detect a change in the code that produces it. Either the "
            . "corpus cannot exercise them or the call is malformed; both make this arm's "
            . "\"identical\" weaker than it reads.\n",
        count($hollow),
        implode(', ', $hollow)
    ));

    // STILL UNCONDITIONALLY FATAL, and an env-var escape hatch was written here and then removed.
    // The case for it was that a fatal gate names one hollow key per four-minute container boot,
    // which is expensive with forty surfaces arriving. Both halves of that turned out to be false.
    // Enumeration was never limited by fatality — the implode above already names every hollow key
    // in one run. And the escape hatch was not free: compare-answers.sh:180-194 only greps for this
    // marker INSIDE its `[ ! -s answers.json ]` branch, so an arm that emitted answers anyway would
    // write the marker for nobody, and the run would proceed to VERDICT: IDENTICAL over a set
    // containing hollow reports. A flag that converts a loud failure into a green verdict is worse
    // than the four minutes it saves.
    exit(1);
}

echo "SLIMSTAT-ANSWERS " . json_encode($answers) . "\n";

// ── the arm's capabilities, status and Pro provenance — a SEPARATE line ─────
//
// Deliberately NOT inside the answers document, and the reason is a live protocol rather than
// tidiness. verify-change.sh:66-73 copies the answers file verbatim into an adjudication packet,
// relabels the arms so "nothing an agent opens names a direction", and hands it to a blind
// reviewer. `count_exit_pages` true on one arm and false on the other, or get_goal_results arity
// 1 against 2, IS a changelog: it tells the reader which arm is newer, in the document written to
// stop exactly that. So the capabilities travel on their own line, which that packet does not copy.
//
// Two smaller consumers make the same placement right for a second reason. run-rollup-floor.sh
// compares every key including `_arm_*`, so a per-report status blob living in the answers
// document would surface a 5.6-only error as one unnamed `_arm_status VALUE diff`. And
// compare-answers.sh reads `len(answers) >= 10` as its vacuity floor — every metadata key added
// to that document weakens the count of real reports it is trying to make.
//
// The prefix cannot contain SLIMSTAT-ANSWERS: both consumers extract with an UNANCHORED grep, so a
// marker like SLIMSTAT-ANSWERS-CAPS would be concatenated into the answers JSON and corrupt it.
echo "SLIMSTAT-CAPS " . json_encode($slimstat_caps) . "\n";

// ── timing, emitted separately so the answers stay byte-comparable ──────────
//
// Only meaningful because I8 landed: on the old corpus a 30-day report and an all-time report
// scanned the same rows, so any latency difference between them measured nothing.
//
// REPEATED, and reported as a distribution. A blind adjudicator refused a previous single-shot
// ms figure on three grounds, all correct: n=1 has no spread, the wall clock was dominated by
// WordPress booting, and the direction happening to agree with the statement count is exactly
// what makes such a number tempting. So the clock starts AFTER boot, wraps only the report call,
// and each report runs REPS times with the minimum reported alongside the median.
//
// The minimum is the honest headline for a warm comparison: it is the run least perturbed by
// whatever else the machine was doing. The median is printed beside it so a wide spread is
// visible rather than hidden.
$reps = max(1, (int) (getenv('SLIMSTAT_TIMING_REPS') ?: 5));

$timed = [
    'count_records_id'   => static function () { return wp_slimstat_db::count_records('id', '', false); },
    'top_resource'       => static function () { return wp_slimstat_db::get_top(['columns' => 'resource', 'use_date_filters' => false]); },
    'top_referer'        => static function () { return wp_slimstat_db::get_top(['columns' => 'referer', 'use_date_filters' => false]); },
    'top_country'        => static function () { return wp_slimstat_db::get_top(['columns' => 'country', 'use_date_filters' => false]); },
    'bouncing_visits'    => static function () {
        return wp_slimstat_db::count_records_having('visit_id', 'visit_id > 0 AND browser_type <> 1', 'COUNT(id) = 1');
    },
    // The chart's whole data path — the Run 25 licence says its counters halve when the
    // totals query folds into the buckets query; this is where that claim is checked
    // end-to-end through the module rather than on hand-built SQL.
    'chart_weekly'       => static function () use ($chart_capture, $chart_end) {
        return $chart_capture($chart_end - 60 * 86400 + 1, $chart_end);
    },
];

if ($end > 0 && $start > 0) {
    $timed['top_resource_in_window'] = static function () use ($start, $end) {
        return wp_slimstat_db::get_top([
            'columns'          => 'resource',
            'where'            => 'dt BETWEEN ' . $start . ' AND ' . $end,
            'use_date_filters' => false,
        ]);
    };
}

$timings = ['_reps' => $reps];

/**
 * Session status counters, which are DETERMINISTIC where milliseconds are not.
 *
 * A null control — the same ref as both arms — showed top_resource moving +12.69 ms (+11.3%)
 * with no code difference at all, so the ms figures on this harness cannot carry a claim. These
 * can: Handler_read_rnd_next is rows read from a full scan, Created_tmp_disk_tables is the
 * MEMORY-to-disk spill A4 is about, Sort_rows is what a filesort moved. They do not vary with
 * how busy the machine is.
 *
 * If these are identical across two arms, any latency difference between those arms is
 * environmental BY DEFINITION — which is the check that makes a timing number interpretable
 * rather than merely printed.
 */
$counters = static function () {
    $wanted = [
        'Handler_read_rnd_next', 'Handler_read_next', 'Handler_read_key', 'Handler_read_first',
        'Created_tmp_tables', 'Created_tmp_disk_tables', 'Sort_rows', 'Sort_scan', 'Select_scan',
    ];

    $out = [];
    foreach ($GLOBALS['wpdb']->get_results("SHOW SESSION STATUS WHERE Variable_name IN ('" . implode("','", $wanted) . "')") as $row) {
        $out[$row->Variable_name] = (int) $row->Value;
    }

    return $out;
};

foreach ($timed as $name => $fn) {
    $samples = [];

    // Counters are read once, around a SINGLE clean execution, before the timing loop. Summing
    // them over repetitions would just multiply by $reps and hide the per-execution figure that
    // is the actual comparable.
    slimstat_purge_report_cache();

    $before_counters = $counters();
    $fn();
    $after_counters = $counters();

    $delta = [];
    foreach ($after_counters as $k => $v) {
        $delta[$k] = $v - ($before_counters[$k] ?? 0);
    }

    for ($i = 0; $i < $reps; $i++) {
        // The query cache must go between samples or every repetition after the first measures
        // the cache instead of the query. The helper's docblock carries the measurement that
        // forced both statements rather than just the flush (0.22-0.38 ms GROUP BYs).
        slimstat_purge_report_cache();

        $t0 = microtime(true);
        $fn();
        $samples[] = (microtime(true) - $t0) * 1000;
    }

    // RAW samples kept, not just min/median/max. A blind adjudicator noted that discarding
    // 4 of 7 leaves no variance to reason about, so a delta can be neither supported nor
    // refuted from the artifact — only eyeballed against a range.
    sort($samples);
    $timings[$name] = [
        'samples'  => array_map(static function ($s) { return round($s, 2); }, $samples),
        'min'      => round($samples[0], 2),
        'median'   => round($samples[intdiv(count($samples), 2)], 2),
        'max'      => round($samples[count($samples) - 1], 2),
        'counters' => $delta,
    ];
}

echo "SLIMSTAT-TIMING " . json_encode($timings) . "\n";
