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
 * Invoke a static method era-blind, whatever its visibility.
 *
 * setAccessible() rather than a visibility test, because visibility is one of the things that
 * MOVED between the arms: NEW's '*' expansion lives in a private recent_columns()
 * (admin/view/wp-slimstat-db.php:1389) where OLD inlines a 29-name literal (:1119). An
 * instrument that could only reach public methods would report that difference as "absent".
 *
 * It THROWS rather than returning a sentinel. A missing method raises ReflectionException, which
 * slimstat_capture() would classify as `error` — the wrong answer for a method this arm simply
 * does not have, and the one conflation this whole envelope exists to end. So the two surfaces
 * that CAN be absent (count_exit_pages, live_window_end) gate on slimstat_probe() first; the
 * other nineteen name methods present in both eras, where the throw is the backstop it says it
 * is. An earlier draft of this sentence claimed every call site gates, which was true of two of
 * twenty-one — the "comment describing a coverage the code does not have" this file records
 * twice elsewhere, written a third time in the docblock warning about it.
 *
 * `null` as the object because every catalogued entry point is a static: the one non-static
 * data path in this file (Chart) needs an instance and builds its own ReflectionMethod below.
 */
function slimstat_invoke($class, $method, array $args = [])
{
    $r = new ReflectionMethod($class, $method);
    $r->setAccessible(true);

    return $r->invokeArgs(null, $args);
}

/**
 * Run $fn against an ABSOLUTE date window, then restore whatever was there.
 *
 * SCOPED, never global, and that is the whole design of the twins. init_filters() anchors the
 * default window on the clock — end is mktime(23:59:59, today) clamped to live_window_end()
 * (admin/view/wp-slimstat-db.php:899-903), i.e. now to the second, and start slides with it —
 * and three of the frozen answers are date-filtered through it: count_records_having takes no
 * use_date_filters argument (:1109 calls get_combined_where with two), and get_top_aggr's array
 * form only started honouring the flag in NEW. Pinning globally would move `bouncing_visits`,
 * `uniques_browser` and `uniques_country`, which compare-answers.sh:150 diffs byte-for-byte. So
 * the pin wraps the extended captures only and the three legacy keys get pinned TWINS.
 *
 * $filters_normalized is public in both eras (OLD:30, NEW:39), so no reflection is needed.
 *
 * `finally`, not a trailing restore: a surface that throws would otherwise leave the window
 * pinned for every capture after it, and those would be misread as the pinned ones.
 */
function slimstat_with_window($start, $end, $limit, callable $fn)
{
    $saved = wp_slimstat_db::$filters_normalized;

    wp_slimstat_db::$filters_normalized['utime']['start']        = (int) $start;
    wp_slimstat_db::$filters_normalized['utime']['end']          = (int) $end;
    wp_slimstat_db::$filters_normalized['utime']['range']        = (int) $end - (int) $start;
    wp_slimstat_db::$filters_normalized['misc']['limit_results'] = (int) $limit;
    wp_slimstat_db::$filters_normalized['misc']['start_from']    = 0;
    wp_slimstat_db::$filters_normalized['columns']               = [];

    try {
        return $fn();
    } finally {
        wp_slimstat_db::$filters_normalized = $saved;
    }
}

/**
 * Total, deterministic row order for an EXTENDED capture.
 *
 * OLD's get_top_aggr orders by `counthits DESC` alone (OLD:1327); NEW added the grouped column
 * as a tie-break (NEW:1735, `counthits DESC, %s ASC`) after a same-corpus null control swapped
 * rows 19 and 20 between two identical runs. Comparing the arms row-by-row without this would
 * report a difference the query PLAN produced, not one the data or the change did.
 *
 * The cost is stated rather than hidden: sorting by the encoded row DESTROYS the ranking, so a
 * change that reorders a top-N list without changing its membership is invisible here. That is
 * only acceptable because the legacy top_* keys keep their emitted order in the answers
 * document, which is where a reordering is meant to be caught.
 */
function slimstat_canon_rows($result)
{
    $out = slimstat_rows($result);

    // Sort keys computed ONCE per row, not inside the comparator. json_encode() in a usort()
    // callback re-encodes each row about log2(n) times — for get_recent's 29 columns at the
    // shipped 200-row limit that is ~3,000 encodes where 200 do, and it runs inside the window
    // the cost line is metering.
    $keys = [];
    foreach ($out as $i => $row) {
        $keys[$i] = json_encode($row);
    }
    array_multisort($keys, SORT_STRING, $out);

    return $out;
}

/**
 * Normalise a report row set into a plain, ordered array of arrays — WITHOUT reordering the rows.
 *
 * One owner, because the ksort loop had been written twice: here and inside the canonicalising
 * form above, character for character. Two independent readers of one surface is the drift
 * probe-ua-dimension.php:242 records having already been paid for once — and the divergence would
 * arrive the day this becomes a manifest order instead of a ksort, in whichever copy was edited.
 */
function slimstat_rows($result)
{
    $out = [];
    foreach ((array) $result as $row) {
        $row = (array) $row;
        ksort($row);
        $out[] = $row;
    }

    return $out;
}

/**
 * The two query counters, read as a pair and never merged.
 *
 * Pro's CustomDBAddon swaps wp_slimstat::$wpdb for a second handle
 * (src/Addon/Addons/CustomDBAddon.php:20), so on such an arm the analytics work and the core
 * work are two different databases' num_queries and adding them double-counts nothing while
 * hiding which one moved. `_handles.same_object` on the CAPS line records whether they were in
 * fact one object, which is what licenses reading these as a single database's work.
 *
 * num_queries increments in wpdb::_do_query() and needs no SAVEQUERIES.
 */
function slimstat_query_counts()
{
    $core   = (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) ? (int) $GLOBALS['wpdb']->num_queries : 0;
    $handle = slimstat_analytics_handle();

    return ['core' => $core, 'analytics' => (null === $handle) ? $core : (int) $handle->num_queries];
}

/**
 * The handle the ANALYTICS queries actually ran on, or null when it cannot be resolved.
 *
 * One owner, because the predicate was written three times and two of the copies fed the same
 * claim: the counts came from one spelling and `_handles.same_object` — the field that LICENSES
 * reading those counts as a single database's work — was derived from another. Two expressions
 * answering one question is how they end up disagreeing about a Pro custom-DB arm.
 *
 * null rather than falling back to $GLOBALS['wpdb'], because "the two handles are the same
 * object" and "there was no second handle to compare" are different statements and only the
 * first licenses anything. Callers decide what to do with the absence.
 */
function slimstat_analytics_handle()
{
    return (class_exists('wp_slimstat') && property_exists('wp_slimstat', 'wpdb') && is_object(wp_slimstat::$wpdb))
        ? wp_slimstat::$wpdb
        : null;
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

    // Prime alloptions HERE, so its SELECT lands outside the window being measured.
    //
    // wp_cache_flush() drops the alloptions blob with everything else, and core's get_option()
    // reloads it unconditionally on the first read after that (wp-includes/option.php). Every
    // catalogued surface reaches get_option() — get_results() takes a transient on every
    // slim_stats select — so without this the reload happens lazily INSIDE $fn(), adding one
    // instrument-caused query to every priced surface and one options SELECT to all 42 timed
    // samples. The instrument was taxing the numbers it exists to report, in the direction that
    // looks like the code being measured. Same total work, moved to where it belongs.
    //
    // It cannot resurrect what the DELETE just removed: every set_transient() in the plugin
    // passes an expiration, so core stores those rows with autoload 'no' and alloptions never
    // carries them. And it runs after the DELETE regardless.
    if (function_exists('wp_load_alloptions')) {
        wp_load_alloptions();
    }
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

/** The row normaliser, as a callable for the capture closures that `use` it. */
$rows = 'slimstat_rows';

$answers       = [];
$arm_status    = [];
$slimstat_cost = [];

/**
 * One capture, PRICED — the same call, wearing a query count and a memory figure.
 *
 * Measured at the FIRST, cold invocation rather than in a fourth pass after the timing loop,
 * and that is a deliberate departure from §A6. A repeat call inside one PHP request does not
 * re-run: get_goal_results memoises on a static keyed by criteria+filters (NEW:2258, OLD:1753),
 * get_funnel_results on its cache key (NEW:2546), get_overview_summary on self::$pageviews
 * (:1338), and slimstat_purge_report_cache() cannot clear a static — it clears transients. A
 * later pass would therefore price precisely the four surfaces the cost line exists for at ZERO
 * queries, and print it beside the surfaces that genuinely ran. The line is still EMITTED after
 * the timing loop, where §A6 puts it; only the reading is taken where the work happens.
 *
 * The counters are read INSIDE the closure slimstat_capture() wraps, so they are read after its
 * purge — the DELETE is itself a query and would otherwise be billed to the surface.
 *
 * Cost never reaches $answers or $slimstat_caps. num_queries and peak memory move with anything
 * else running in the process, and run-rollup-floor.sh:127 compares the answers document
 * byte-for-byte between two passes of the same arm.
 */
$slimstat_meter = static function ($id, callable $fn, array $flags = []) use (&$slimstat_cost) {
    $isolated = function_exists('memory_reset_peak_usage');
    $priced   = null;

    $env = slimstat_capture(static function () use ($fn, $isolated, &$priced) {
        $q0 = slimstat_query_counts();
        // PHP 8.2+. Without it the "peak" below is the process high-water mark wearing this
        // surface's name — the memory twin of an empty report comparing equal — which is why
        // _peak_isolated travels on the line rather than being assumed by the reader.
        if ($isolated) {
            memory_reset_peak_usage();
        }
        // peak_bytes reads real=true because §A6 asks for the allocator high-water mark; the
        // DELTA reads emalloc instead, and the difference is the difference between a number and
        // a constant. real=true reports the 2 MB chunk grain, and measured across the whole
        // catalogue that produced `mem_delta_bytes: 0` for 40 of 44 surfaces — a field that
        // cannot move is not a measurement, it is decoration that looks like one.
        $m0 = memory_get_usage();

        $value = $fn();

        $q1 = slimstat_query_counts();
        $priced = [
            'queries_analytics' => $q1['analytics'] - $q0['analytics'],
            'queries_core'      => $q1['core'] - $q0['core'],
            'peak_bytes'        => memory_get_peak_usage(true),
            'mem_delta_bytes'   => memory_get_usage() - $m0,
        ];

        return $value;
    }, $flags);

    // null on a throw — the closure never reached its second reading. Emitted as nulls rather
    // than zeros: "no queries ran" and "the measurement did not complete" are different facts,
    // and a zero here would read as the first.
    $slimstat_cost[$id] = ($priced === null
        ? ['queries_analytics' => null, 'queries_core' => null, 'peak_bytes' => null, 'mem_delta_bytes' => null]
        : $priced) + ['rows' => $env['rows'], 'class' => $env['class']];

    return $env;
};

/**
 * Emit one capture at its LEGACY key and its envelope-minus-value at _arm_status[key].
 *
 * The legacy key keeps the bare list / bare int it has always held — compare-answers.sh diffs
 * those values directly, and every one of them is frozen. What the envelope adds is the thing the
 * document could not previously say: whether that bare `[]` means "found nothing" or "the query
 * failed and get_results() reported the failure as nothing".
 */
$capture = static function ($key, callable $fn, array $flags = []) use (&$answers, &$arm_status, $slimstat_meter) {
    $env = $slimstat_meter($key, $fn, $flags);

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
$slimstat_analytics_handle = slimstat_analytics_handle();

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

// Named once and read at all three sites that ask the question. Both bounds must be present:
// half a window is not a window, and pinning only one end would move the other with the clock.
$slimstat_windowed = ($end > 0 && $start > 0);

if ($slimstat_windowed) {
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

// ── the EXTENDED surface catalogue — §A8 rows 8-26 ─────────────────────────
//
// Twenty-two surfaces, every one a `wp_slimstat_db` entry point the frozen 26 never touched.
// Ten of them are the no-argument summaries — the family that reaches get_combined_where() with
// no way for a caller to say otherwise — and a rewrite of the WHERE builder, the query cache or
// the date scope could have moved all ten without one byte of the answers document changing.
//
// They do NOT enter the answers document, and that is ruling 1 rather than tidiness.
// verify-change.sh:66-73 copies that document verbatim into a blind adjudication packet with
// the arms relabelled so "nothing an agent opens names a direction" — and `count_exit_pages`
// answered on one side and unsupported on the other names it, inside the file written to hide
// it. compare-answers.sh:203 also reads len(answers) >= 10 as its vacuity floor, which every
// non-report key weakens. So these ride on SLIMSTAT-CAPS, which that packet does not copy.
//
// They do not feed the HOLLOW gate either. An extended surface legitimately returns nothing —
// no events in the corpus, no funnels stored — and killing the arm over that would lose the
// other forty. They DO feed the ERROR gate: `empty` is an answer, `error` is not, and keeping
// those two apart is the only reason this envelope exists.
$arm_surfaces = [];

$capture_ext = static function ($id, callable $fn, array $flags = []) use (&$arm_surfaces, $slimstat_meter) {
    // The WHOLE envelope, value included (§A3) — unlike the core tier, which splits the bare
    // value into $answers and the classification into _arm_status. Nothing here is frozen, so
    // there is no legacy shape to preserve, and a reader of one key gets the answer and the
    // reason for it in the same object.
    $arm_surfaces[$id] = $slimstat_meter($id, $fn, $flags);
};

/**
 * A surface this arm cannot be asked, recorded as a REASON rather than a missing key.
 *
 * The flags map keeps its three fixed members even though nothing ran, for the reason the
 * capture envelope gives at its own array_merge: a fixed key order is what makes this a TABLE
 * rather than a bag of differently-shaped rows. §A4 draws it as `{}`; a row that changes shape
 * with its own verdict is harder to read than the two wasted booleans are to carry.
 */
$unsupported = static function ($id, $reason) use (&$arm_surfaces, &$slimstat_cost) {
    $arm_surfaces[$id] = [
        'class'         => 'unsupported',
        'value'         => null,
        'rows'          => null,
        'scalar'        => null,
        'error'         => null,
        '__unsupported' => $reason,
        'flags'         => ['clock_dependent' => false, 'calendar_day_dependent' => false, 'pinned' => false],
    ];

    // Listed on the cost line too, at null. Every surface on the CAPS line therefore also appears
    // on the cost line, so "absent from SLIMSTAT-COST" can only mean the instrument forgot it.
    // (The reverse does not hold and the earlier "SAME surface set" phrasing overstated it: COST
    // is a strict superset, carrying the core tier and get_data_size besides.)
    $slimstat_cost[$id] = [
        'queries_analytics' => null, 'queries_core' => null, 'peak_bytes' => null,
        'mem_delta_bytes' => null, 'rows' => null, 'class' => 'unsupported',
    ];
};

// The shipped default (wp-slimstat.php:1429), pinned as a literal so a pinned twin differs from
// its legacy sibling in the WINDOW and in nothing else.
$ext_limit    = 200;
$ext_pinnable = $slimstat_windowed;

// FIXED LITERAL, never sprintf'd with the bounds it is complaining about: run-rollup-floor.sh
// compares this arm's output between two passes, and a formatted reason is a wall clock waiting
// to happen (PITFALLS 50 — the answer was deterministic and the tape recorder was not).
$no_window_reason = 'this run supplied no absolute window bounds, and this surface takes no '
    . 'date-filter argument in either era, so the only window available would be the one '
    . 'init_filters() anchors on the clock at boot (admin/view/wp-slimstat-db.php:899-903). Two '
    . 'arms run minutes apart would then differ over the rows that slid out of the window, with '
    . 'no code difference at all.';

/**
 * Drive a surface inside the pinned window, or say why it could not be driven.
 *
 * §A8 assigns slimstat_with_window() to get_group_by alone, with an explicit criterion — "no
 * use_date_filters in either era" — and then lists every other no-argument surface plain. The
 * criterion is true of all of them: get_recent_events (:1478/:1181), get_top_events (:1742/:1333),
 * get_top_outbound (:1767/:1358), the six summaries, count_bouncing_pages (:1006/:869),
 * get_oldest_visit, count_exit_pages (OLD:887) and the goal/funnel four all reach
 * get_combined_where() with date filters on and no way for a caller to say otherwise. The rule is
 * applied where its criterion holds rather than where the table happened to spell it out.
 *
 * MEASURED, and the broadening earned itself: unpinned, all of these read the window
 * init_filters() ends at live_window_end() — now, to the second — so two arms run a minute apart
 * select different rows. get_recent is the ONE catalogued surface that takes the argument, and it
 * is the one capture below that runs unpinned.
 *
 * The pin does not make them clock-INDEPENDENT, only clock-EQUAL across the two arms, and one
 * surface proves the difference: with $end = the harness's "now", the window still reaches the
 * present, so Query::getAll() takes its historical/live split path — which is how get_group_by
 * surfaced an era divergence §A8 did not predict. That is the path being exercised, not a defect
 * in the pin.
 */
$capture_windowed = static function ($id, callable $fn, array $flags = [])
    use ($capture_ext, $unsupported, $ext_pinnable, $ext_limit, $no_window_reason, $start, $end) {
    if (!$ext_pinnable) {
        $unsupported($id, $no_window_reason);
        return;
    }

    $capture_ext($id, static function () use ($fn, $start, $end, $ext_limit) {
        return slimstat_with_window($start, $end, $ext_limit, $fn);
    }, array_merge(['pinned' => true], $flags));
};

// ── the PINNED TWINS (checklist step 5) ─────────────────────────────────────
//
// The three legacy keys they mirror are date-filtered through a window that ends at NOW, so
// they are the only frozen answers that can move without the code moving. They cannot be
// pinned in place — compare-answers.sh:150 diffs them byte-for-byte and A0 freezes their value
// — so the pinned reading arrives beside them under a new name, and the campaign compares the
// twin while the legacy key goes on proving nothing else shifted.
$capture_windowed('bouncing_visits_pinned', static function () {
    return (int) slimstat_invoke('wp_slimstat_db', 'count_records_having', [
        'visit_id', 'visit_id > 0 AND browser_type <> 1', 'COUNT(id) = 1',
    ]);
});

foreach (['browser', 'country'] as $ext_column) {
    // POSITIONAL, where the legacy pair is array-form. Positional has no use_date_filters in
    // either era, so the window comes only from the pin — and that sidesteps the era difference
    // the legacy pair is stuck with: OLD:1304 reads the flag with empty(), which turns a
    // declared `false` back into true, and OLD:1317 calls get_combined_where() with two
    // arguments anyway. canon_rows because OLD:1327 has no tie-break behind counthits.
    $capture_windowed('uniques_' . $ext_column . '_pinned', static function () use ($ext_column) {
        return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_top_aggr', [
            'visit_id', 'visit_id > 0', $ext_column, 'MAX',
        ]));
    });
}

// ── row 8: the Access Log path ──────────────────────────────────────────────
// Positional with an explicit $order_by, because the default 'dt DESC' has no tie-break in
// either era and this returns whole rows. use_date_filters FALSE — this is the one catalogued
// surface that has the argument, so it needs no pin.
$recent_shape = null;
$capture_ext('get_recent', static function () use (&$recent_shape) {
    $result = slimstat_invoke('wp_slimstat_db', 'get_recent', ['*', '', '', false, '', '', 'dt DESC, id DESC']);

    // Read BEFORE canonicalisation, because canon_rows ksorts every row and the SELECT order is
    // exactly what this observes: NEW builds the list from recent_columns() (:1389), OLD from a
    // 29-name literal (:1119), and an arm that reordered its manifest would be invisible in an
    // alphabetised key list.
    $first        = isset($result[0]) ? (array) $result[0] : null;
    $recent_shape = (null === $first) ? null : array_keys($first);

    return slimstat_canon_rows($result);
});

// ── rows 9-12: events, outbound, group-by ───────────────────────────────────
$capture_windowed('get_recent_events', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_recent_events'));
});

$capture_windowed('get_top_events', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_top_events'));
});

$capture_windowed('get_top_outbound', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_top_outbound', [[]]));
});

// Array-only in both eras, so the array parser is not a choice here. The two column names are
// pinned literals and deliberately low-cardinality: column_group is GROUP_CONCAT(DISTINCT …),
// and grouping a 4,000-resource corpus that way returns a payload bounded only by
// group_concat_max_len — a truncation point is not a comparable answer.
$capture_windowed('get_group_by', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_group_by', [
        ['group_by' => 'browser', 'column_group' => 'platform'],
    ]));
});

// ── rows 13-20: the no-argument summaries ───────────────────────────────────
// The family a WHERE-builder refactor could rewrite whole while the answers document sat still.
// Two carry flags rather than a pin, because the pin cannot reach what they read: rows 5-7 of
// get_overview_summary are computed from wp_slimstat::now() and mktime() on today directly
// (:1358-1367), outside $filters_normalized entirely.
$capture_windowed('get_overview_summary', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_overview_summary'));
}, ['clock_dependent' => true, 'calendar_day_dependent' => true]);

$capture_windowed('get_visitors_summary', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_visitors_summary'));
});

$capture_windowed('get_visits_duration', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_visits_duration'));
});

$capture_windowed('get_max_and_average_pages_per_visit', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_max_and_average_pages_per_visit'));
});

$capture_windowed('get_traffic_sources_summary', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_traffic_sources_summary'));
});

// Reads CORE tables (wp_posts, wp_comments) through Query::local() for seven of its eight rows,
// which is why it is worth a key of its own: under the Pro custom-DB handle those seven ran
// against a database with no wp_posts (F6/C44), and queries_core on the cost line is where that
// separation is observable at all.
$capture_windowed('get_your_blog', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_your_blog'));
});

$capture_windowed('count_bouncing_pages', static function () {
    return (int) slimstat_invoke('wp_slimstat_db', 'count_bouncing_pages');
});

$capture_windowed('get_oldest_visit', static function () {
    return (int) slimstat_invoke('wp_slimstat_db', 'get_oldest_visit');
});

// ── rows 21-24: goals and funnels ───────────────────────────────────────────
//
// FORCED on both counts, and unforced this whole tier measures nothing. get_funnels_raw reads
// apply_filters('slimstat_max_funnels', 0) and returns [] outright when it is not positive
// (NEW:2798-2801) while OLD returns everything; get_goals_raw bounds its loop by
// slimstat_max_goals, which is 1 on free (NEW:2362) and unread on OLD. Two arms differing
// because of a TIER is not a finding about the code.
//
// The definitions are INJECTED through pre_option_*, never written to wp_options: both arms share
// one database in compare-answers.sh, so a write would persist into the other arm and outlive the
// run. And they have to come from somewhere — on an empty option all four of these surfaces
// return [] on both arms and report parity over nothing, which is PITFALLS 44's shape exactly.
//
// FOUR fixtures, not two, because the first draft's two were one measurement wearing four names.
// get_goals_raw() and get_funnels_raw() call get_goal_results() / get_funnel_results() on whatever
// they are handed, and both of those memoise on a PHP static keyed by the RULE (NEW:2258 / :2546,
// OLD:1753 / :1968) — which slimstat_purge_report_cache() cannot clear, because it clears
// transients and a static is not one. Driving the loop and the single-result entry point with the
// SAME definition therefore ran the loop and then read its memo. Measured on the run that caught
// it: 19 queries for the loop and 2 for the "same" call, both of those two being get_option()
// machinery after a cache flush — and on OLD, where the funnel chain ERRORED inside
// get_funnels_raw, get_funnel_results was classified `ok` while handing back the poisoned partial
// result out of that memo. The conflation the envelope exists to prevent, arriving through the
// instrument rather than through the code. Distinct rules, distinct memo keys, four real calls.
// (PITFALLS 58.)
$goal_fixture = [
    'id'        => 'bench-goal',
    'name'      => 'Bench goal',
    'active'    => 1,
    'dimension' => 'resource',
    'operator'  => 'contains',
    'value'     => '/',
];
$goal_fixture_raw = [
    'id'        => 'bench-goal-raw',
    'name'      => 'Bench goal (raw)',
    'active'    => 1,
    'dimension' => 'browser',
    'operator'  => 'is_not_empty',
    'value'     => '',
];
// Two steps, and the second reads the SAME table as the first on purpose: that is the only shape
// in which NEW's argmin row-exclusion (:2648-2687) is observable at all, and it is pre-registered
// as an era diff rather than discovered as one.
$funnel_fixture = [
    'id'    => 'bench-funnel',
    'name'  => 'Bench funnel',
    'steps' => [
        ['name' => 'step-1', 'dimension' => 'resource', 'operator' => 'contains', 'value' => '/'],
        ['name' => 'step-2', 'dimension' => 'browser', 'operator' => 'is_not_empty', 'value' => ''],
    ],
];
$funnel_fixture_raw = [
    'id'    => 'bench-funnel-raw',
    'name'  => 'Bench funnel (raw)',
    'steps' => [
        ['name' => 'step-1', 'dimension' => 'resource', 'operator' => 'contains', 'value' => '/'],
        ['name' => 'step-2', 'dimension' => 'country', 'operator' => 'is_not_empty', 'value' => ''],
    ],
];

$forced_max_goals   = 50;
$forced_max_funnels = 50;

$force_goals   = static function () use ($forced_max_goals) { return $forced_max_goals; };
$force_funnels = static function () use ($forced_max_funnels) { return $forced_max_funnels; };
$inject_goals   = static function () use ($goal_fixture_raw) { return [$goal_fixture_raw]; };
$inject_funnels = static function () use ($funnel_fixture_raw) { return [$funnel_fixture_raw]; };

add_filter('slimstat_max_goals', $force_goals);
add_filter('slimstat_max_funnels', $force_funnels);
add_filter('pre_option_slimstat_goals', $inject_goals);
add_filter('pre_option_slimstat_funnels', $inject_funnels);

$capture_windowed('get_goals_raw', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_goals_raw', [[]]));
});

$capture_windowed('get_funnels_raw', static function () {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_funnels_raw', [[]]));
});

// ONE argument, on both arms, gated by arity and not by try/catch. OLD declares one parameter
// (:1729) and NEW two (:2227), and PHP accepts a surplus argument to a userland function
// without error — so a two-argument call succeeds on both arms and silently drops the scope on
// one. _arm_caps.get_goal_results_arity records the widening; nothing here compares it.
$capture_windowed('get_goal_results', static function () use ($goal_fixture) {
    return slimstat_invoke('wp_slimstat_db', 'get_goal_results', [$goal_fixture]);
});

$capture_windowed('get_funnel_results', static function () use ($funnel_fixture) {
    return slimstat_canon_rows(slimstat_invoke('wp_slimstat_db', 'get_funnel_results', [$funnel_fixture]));
});

remove_filter('pre_option_slimstat_funnels', $inject_funnels);
remove_filter('pre_option_slimstat_goals', $inject_goals);
remove_filter('slimstat_max_funnels', $force_funnels);
remove_filter('slimstat_max_goals', $force_goals);

// ── rows 25-26: the two mirror absences ─────────────────────────────────────
//
// The pair that makes the catalogue worth its length. One surface is gone on NEW and one is new
// on NEW, and in an artifact without this block both look identical to a surface nobody wrote a
// capture for.
if (slimstat_probe('wp_slimstat_db', 'count_exit_pages')['exists']) {
    $capture_windowed('count_exit_pages', static function () {
        return (int) slimstat_invoke('wp_slimstat_db', 'count_exit_pages');
    });
} else {
    $unsupported(
        'count_exit_pages',
        'wp_slimstat_db::count_exit_pages() is not defined on this arm. It exists at OLD '
        . 'admin/view/wp-slimstat-db.php:887 and was deleted in 6.0.0. git grep count_exit_pages '
        . 'origin/development returns only its own declaration, so no rendered number depends on '
        . 'it in either era.'
    );
}

if (slimstat_probe('wp_slimstat_db', 'live_window_end')['exists']) {
    // The captured observable is a PREDICATE, not the timestamp. live_window_end() returns
    // date_i18n('U') rounded down to the filtered bucket (:964-981) — a wall clock, and a wall
    // clock inside a byte-compared channel is PITFALLS 50 verbatim, in the one line whose job
    // is to describe the measurement. What is actually era-divergent is whether the arm honours
    // the bucket at all, so the capture asks that: with a one-hour bucket forced, does the
    // clamp land on an hour boundary. Deterministic, and it is the exact property §(c)'s reason
    // string says OLD cannot have.
    $bucket_hour = static function () { return 3600; };
    add_filter('slimstat_live_window_bucket_seconds', $bucket_hour);
    $capture_ext('live_window_end', static function () {
        return 0 === ((int) slimstat_invoke('wp_slimstat_db', 'live_window_end')) % 3600;
    });
    remove_filter('slimstat_live_window_bucket_seconds', $bucket_hour);
} else {
    $unsupported(
        'live_window_end',
        'wp_slimstat_db::live_window_end() is not defined on this arm. It is NEW-only (6.0.0, '
        . 'admin/view/wp-slimstat-db.php:962); OLD inlines intval(date_i18n(\'U\')) at :830 and '
        . ':855, so the slimstat_live_window_bucket_seconds quieting filter has no effect here.'
    );
}

ksort($arm_surfaces);

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

// The tier the goal/funnel captures were driven at, recorded rather than remembered: unforced,
// free's slimstat_max_goals is 1 and slimstat_max_funnels is 0, and a reader comparing two arms
// has no way to tell "the tiers were equalised" from "both arms happened to be free".
$slimstat_caps['forced_max_goals']   = $forced_max_goals;
$slimstat_caps['forced_max_funnels'] = $forced_max_funnels;

// The ONLY observable of NEW's recent_columns() (:1389): the manifest moved from a 29-name
// literal (OLD:1119) to an intersection with the columns actually present, and on a complete
// schema those two produce the same list — which is the claim D74's commit message makes and
// this is where it gets checked against a database. Column NAMES rather than a count, so an arm
// that dropped one says which. `null` when the surface returned no rows: a shape read off an
// absent row would be a fact about the corpus wearing the schema's name.
$slimstat_caps['recent_columns_shape'] = $recent_shape;

$slimstat_caps['_arm_surfaces'] = $arm_surfaces;

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
// ── the capability record is emitted BEFORE the gates, on purpose ───────────
//
// It used to sit below them, and that made the arm's own explanation of its failure conditional
// on the arm not failing. The gate below names `get_funnels_raw` and then exits, so the error
// string, the statement, and every other surface's classification — the entire reason the record
// exists — were destroyed by the event they describe, and the operator was left with a surface
// name and a guess.
//
// Moving it up does not soften anything. The gates still exit(1), SLIMSTAT-ANSWERS is still
// withheld, and both consumers key on that absence (compare-answers.sh:180-194,
// run-rollup-floor.sh:125) — never on this line, which neither of them greps. What survives is
// the evidence, which is the half a failing run actually needs.
//
// JSON_INVALID_UTF8_SUBSTITUTE, on this line only. The extended catalogue emits VALUES now —
// user_agent, notes, referer, resource straight out of the corpus — and json_encode() returns
// `false` for a single malformed byte anywhere in the structure, which would emit a bare
// "SLIMSTAT-CAPS" with nothing after it: the whole capability record destroyed by one row. The
// flag is a no-op on valid UTF-8, so it cannot move a byte of a well-formed payload, and it is
// deliberately NOT added to the frozen answers and timing lines.
$slimstat_caps_json = json_encode($slimstat_caps, JSON_INVALID_UTF8_SUBSTITUTE);

$hollow  = [];
$errored = [];
foreach ($arm_status as $key => $env) {
    if ('empty' === $env['class'] && null === $env['scalar']) {
        $hollow[] = $key;
    } elseif ('error' === $env['class']) {
        $errored[] = $key;
    }
}

// The extended catalogue feeds NEITHER list, and the two reasons are different.
//
// Not the hollow list: an extended surface returning nothing is a legitimate answer about this
// corpus — no events seeded, no outbound links, a funnel nobody completed — and failing the arm
// over it would cost the other forty surfaces for a fact that is not about the code.
//
// Not the fatal error list either, which is a correction to this instrument's first draft. An
// errored extended surface is exactly what the extended catalogue was built to FIND: 77c0e946's
// funnel step-2 join dies on this server with "Illegal mix of collations", reproducibly, which is
// the shipping-version defect R4/R5 record — and the first draft's fatal gate turned that finding
// into a dead arm, losing the twenty-odd surfaces that measured perfectly beside it and stopping
// compare-answers.sh before any verdict at all. The core tier stays fatal because it is what the
// comparison DIFFS: an error there makes every "identical" downstream meaningless. The extended
// tier is where discovery happens, so it is recorded loudly and the run continues. Loudly is the
// load-bearing half — the class, the error string and the statement all reach CAPS, and the WARN
// below names them on stderr, so this is not the silent-pass the fatal gate was defending against.
// The THREE PINNED TWINS are the exception, and they belong with the core tier rather than with
// discovery. The split above is drawn by where a key is emitted; what actually decides fatality is
// whether the CAMPAIGN COMPARES the value. The twins are the replacement measurement for the only
// three frozen answers that can move without the code moving — so if a twin errors, the run would
// otherwise exit 0, SLIMSTAT-ANSWERS would be byte-identical, a consumer would print IDENTICAL,
// and the surface carrying the real comparison would have carried nothing. Compared-and-new is
// fatal for the same reason compared-and-frozen is.
$slimstat_compared_ext = ['bouncing_visits_pinned', 'uniques_browser_pinned', 'uniques_country_pinned'];

$errored_ext = [];
foreach ($arm_surfaces as $key => $env) {
    if ('error' !== $env['class']) {
        continue;
    }
    if (in_array($key, $slimstat_compared_ext, true)) {
        $errored[] = $key;
    } else {
        $errored_ext[] = $key;
    }
}

// The error string and the statement INLINE, not "see the CAPS line". The one thing an operator
// needs from a failing surface is which SQL failed and why, and asking them to find a 200 KB JSON
// line in a raw log and dig out one key is how a diagnosis becomes a re-run.
$slimstat_error_detail = static function (array $keys) use ($arm_status, $arm_surfaces) {
    $detail = '';
    foreach ($keys as $key) {
        $env     = isset($arm_status[$key]) ? $arm_status[$key] : $arm_surfaces[$key];
        $detail .= sprintf(
            "  %s\n      error: %s\n      query: %s\n",
            $key,
            (isset($env['error']['str']) && null !== $env['error']['str']) ? $env['error']['str'] : '(no error string)',
            (isset($env['error']['query']) && null !== $env['error']['query']) ? $env['error']['query'] : '(no statement recorded)'
        );
    }

    return $detail;
};

if ($errored_ext !== []) {
    // WARN, not FAIL, and a distinct marker so a consumer can grep for either independently.
    fwrite(STDERR, sprintf(
        "SLIMSTAT-SURFACE-ERROR WARN: %d extended surface(s) errored — %s.\nThe arm continues: an "
            . "errored extended surface is a FINDING about this era, recorded with its class, its "
            . "error string and its statement on the CAPS line. It is not comparable data and no "
            . "consumer may treat it as an answer.\n%s",
        count($errored_ext),
        implode(', ', $errored_ext),
        $slimstat_error_detail($errored_ext)
    ));
}

if ($errored !== []) {
    // The CAPS line first, so the record outlives the failure it describes.
    echo "SLIMSTAT-CAPS " . $slimstat_caps_json . "\n";

    fwrite(STDERR, sprintf(
        "SLIMSTAT-SURFACE-ERROR FAIL: %d CORE report(s) errored — %s.\nAn errored report is not an "
            . "empty one: get_results() answers [] for a query that FAILED exactly as it does for "
            . "one that found nothing, so this arm's answers cannot be compared to anything until "
            . "the SQL is fixed.\n%s",
        count($errored),
        implode(', ', $errored),
        $slimstat_error_detail($errored)
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

    // Same reason as the error gate: the classification of every other surface is what tells the
    // operator whether one report is hollow or the corpus is.
    echo "SLIMSTAT-CAPS " . $slimstat_caps_json . "\n";

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
//
// Encoded further up, and emitted here on the success path only — the two gates above emit the
// same string themselves and exit, so this line appears EXACTLY ONCE however the run ends. That
// is a requirement rather than a tidiness: an arm printing two CAPS lines would concatenate into
// one invalid JSON document under the same unanchored grep any future consumer will use.
echo "SLIMSTAT-CAPS " . $slimstat_caps_json . "\n";

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

    // SESSION status belongs to the connection the reports actually ran on. Read from
    // $GLOBALS['wpdb'] this asks the CORE session on a Pro custom-DB arm — a different MySQL
    // session than the analytics queries used, so every Handler_read_* would be somebody else's.
    // The cost line already keeps the two handles apart; this is the same fact, one instrument
    // over. Falls back to the core handle only when there is no second one to prefer.
    $handle = slimstat_analytics_handle();
    $handle = (null === $handle) ? $GLOBALS['wpdb'] : $handle;

    $out = [];
    foreach ($handle->get_results("SHOW SESSION STATUS WHERE Variable_name IN ('" . implode("','", $wanted) . "')") as $row) {
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

// ── what each surface COST — a fourth line, and the only nondeterministic one ─
//
// Emitted last, after the timing loop, for the reason §A6 gives: these fields must never enter
// the answers document. num_queries and peak memory move with anything else in the process, and
// run-rollup-floor.sh:127 compares that document byte-for-byte between two passes of the same
// arm — a cost field inside it would fail the null control on a machine that did nothing wrong.
// The same argument bars them from SLIMSTAT-CAPS.
//
// The READINGS were taken at each surface's first, cold call rather than in a pass of their own
// here; $slimstat_meter's docblock carries why, and it is not tidiness — four of these surfaces
// answer a repeat call out of a PHP static that no cache purge can reach, so a pass here would
// price them at zero queries and print that beside the surfaces that ran.
//
// SLIMSTAT-COST contains neither SLIMSTAT-ANSWERS nor SLIMSTAT-TIMING as a substring, which is
// a requirement and not an aesthetic: both consumers extract with an UNANCHORED grep
// (compare-answers.sh:150-151, run-rollup-floor.sh:124), so a marker containing another would be
// concatenated into that one's JSON and corrupt every downstream reader.

// The one surface that is cost-line ONLY (§A8 row 27). get_data_size() reads Data_length and
// Index_length out of SHOW TABLE STATUS, which InnoDB reports from a sampled estimate that moves
// between two reads of an unchanged table — so its ANSWER cannot be compared, while its cost can.
// Captured here, at the end, and its value is dropped on the floor rather than emitted anywhere.
$slimstat_meter('get_data_size', static function () {
    return slimstat_invoke('wp_slimstat_db', 'get_data_size');
});

ksort($slimstat_cost);

$slimstat_cost_line = [
    // One reading per surface, not $reps of them: a cost is a count, and counting it five times
    // and dividing would report the same integer with a false claim to precision.
    '_reps'          => 1,
    // PHP 8.2+. FALSE means peak_bytes below is the PROCESS high-water mark wearing each
    // surface's name — monotonic, so every later surface inherits the largest earlier one. The
    // flag is the "never conflate" guard for memory, exactly as `class` is for answers, and
    // reading peak_bytes without it is how a per-report memory figure gets invented.
    // Read from the capability record rather than re-derived, so the cost line and the CAPS line
    // cannot disagree about whether the peak figures are per-report or a process high-water mark.
    '_peak_isolated' => $slimstat_caps['memory_reset_peak_usage'],
    // Whether queries_analytics and queries_core counted the SAME handle. null when the
    // analytics handle could not be resolved at all — "the two are one object" and "there was no
    // second handle to compare" license different readings of the numbers below.
    '_handles'       => $slimstat_caps['_handles'],
];

echo "SLIMSTAT-COST " . json_encode($slimstat_cost_line + $slimstat_cost) . "\n";
