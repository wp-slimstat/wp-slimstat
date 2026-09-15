<?php
// Run tests/docker/verify-export-fingerprint.php UNMODIFIED against a fake server, under one
// named scenario, and let its own exit code be this process's exit code.
//
//   SLIMSTAT_NEG_SCENARIO=<name> php tests/fp-negative/harness.php
//
// The subject is `require`d, not re-implemented and not copied: every control, probe, closure
// and the terminal `exit(1)` are the real ones. A scenario changes the WORLD the subject reads —
// the corpus, the catalogue, the interpreter on PATH, the encoder library it picks up — and never
// the subject. That is the whole discipline here: a negative test that edits the file it is
// testing proves something about the edit.
//
// Scenarios are defined in scenarios.php.

$root  = dirname(__DIR__, 2);                       // …/wp-slimstat
$here  = __DIR__;
$name  = getenv('SLIMSTAT_NEG_SCENARIO');
$name  = ('' === (string) $name) ? 'baseline' : (string) $name;

require $here . '/fake-server.php';
require $here . '/scenarios.php';

$scenarios = slimstat_neg_scenarios();
if (!isset($scenarios[$name])) {
    fwrite(STDERR, "unknown scenario '{$name}'; known: " . implode(', ', array_keys($scenarios)) . "\n");
    exit(2);
}
$scenario = $scenarios[$name];

/**
 * Write a mutant of $path built by exact string replacement, or abort the run.
 *
 * Both mutation vectors go through here, and both are held to the SAME rule: every anchor must
 * match EXACTLY ONCE. "At least once" is not good enough and was the weaker of the two guards
 * this replaced — an anchor that has drifted into matching twice replaces the wrong occurrence,
 * and one that matches zero times leaves the scenario running PRISTINE code while reporting
 * whatever that does. That is a mutation which silently stopped applying: the same defect as a
 * control that never runs, one layer down, in the harness built to rule it out.
 *
 * @param array[] $edits [[find, replace, what-it-anchors], ...]
 * @return string the mutant's path, cleaned up on shutdown
 */
function slimstat_neg_mutate($scenario_name, $path, array $edits, $mutant_path)
{
    $src = file_get_contents($path);
    foreach ($edits as $edit) {
        list($find, $replace, $why) = $edit;
        $hits = substr_count($src, $find);
        if (1 !== $hits) {
            fwrite(STDERR, sprintf(
                "scenario '%s': the anchor for %s matches %d times in %s, not exactly once; "
                . "regenerate it against the current file rather than fuzzing it in\n",
                $scenario_name, $why, $hits, basename($path)
            ));
            exit(2);
        }
        $src = str_replace($find, $replace, $src);
    }
    file_put_contents($mutant_path, $src);
    register_shutdown_function(function () use ($mutant_path) {
        @unlink($mutant_path);
    });
    return $mutant_path;
}

// ── The encoder library, possibly mutated ────────────────────────────────────────────────────
// tests/bench/lib/fingerprint-v2.php wraps its whole body in
// `if (!function_exists('slimstat_fp2_encode_field'))`, so loading a variant FIRST replaces it
// for the rest of the process without touching the file the subject requires.
$lib = $root . '/tests/bench/lib/fingerprint-v2.php';
if (!empty($scenario['mutate_lib'])) {
    require slimstat_neg_mutate(                     // wins the function_exists race, by design
        $name,
        $lib,
        $scenario['mutate_lib'],
        sys_get_temp_dir() . '/slimstat-neg-fp2-' . getmypid() . '.php'
    );
}
require $root . '/tests/bench/lib/pinned-columns.php';
require $lib;

require_once $here . '/fixture.php';

// ── The corpus ───────────────────────────────────────────────────────────────────────────────
$corpus_path = sys_get_temp_dir() . '/slimstat-neg-corpus-' . getmypid() . '.sqlite';
@unlink($corpus_path);
$corpus = new SQLite3($corpus_path);
register_shutdown_function(function () use ($corpus_path) {
    @unlink($corpus_path);
});

$rows_by_table = slimstat_neg_corpus_rows();
if (isset($scenario['rows'])) {
    $rows_by_table = call_user_func($scenario['rows'], $rows_by_table);
}
// Driven by the fixture's own keys rather than a second list of table suffixes: two places
// naming the same set is two places to disagree about it.
foreach ($rows_by_table as $suffix => $rows) {
    slimstat_neg_build_corpus($corpus, $suffix, slimstat_fp2_pinned_columns($suffix), $rows);
}

// ── The server ───────────────────────────────────────────────────────────────────────────────
$cfg = [
    'server_version'  => '8.0.36-fake',
    'charset'         => 'utf8mb4',
    'sql_mode'        => 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES',
    'collation'       => 'utf8mb3_general_ci',
    'collation_pad'   => 'pad',
    'hex_lowercase'   => false,
    'catalogue'       => slimstat_neg_catalogue(),
    'unique_indexes'  => slimstat_neg_unique_indexes(),
    'count_override'  => [],
];
if (isset($scenario['cfg'])) {
    $cfg = call_user_func($scenario['cfg'], $cfg);
}

$engine = new Slimstat_Fake_Server($corpus, $cfg);
$wpdb   = new wpdb($engine);
$GLOBALS['wpdb'] = $wpdb;

// A mid-run write to the corpus, applied BETWEEN the fingerprint pass and the export pass. This
// is the one thing a scenario has to reach into the server to do, because the two passes are
// consecutive statements inside the subject and nothing outside it runs in between. The engine
// fires it once, immediately before the first statement that selects bare columns — see
// Slimstat_Fake_Server::$between_passes for why that is the export pass and nothing else.
if (isset($scenario['between_passes'])) {
    $engine->between_passes = $scenario['between_passes'];
}

// ── The WordPress-shaped surface CONTROL 5 samples ───────────────────────────────────────────
if (!empty($scenario['cron_capable'])) {
    // No DISABLE_WP_CRON, a scheduled purge, and a non-zero retention: all three of CONTROL 5's
    // escape hatches shut.
    function wp_next_scheduled($hook)
    {
        return 2000000000;
    }
    class wp_slimstat
    {
        public static $wpdb     = null;
        public static $settings = ['auto_purge' => 120];
    }
} else {
    define('DISABLE_WP_CRON', true);
}

// ── The subject, possibly mutated ────────────────────────────────────────────────────────────
// A negative test proves a control's failure is DETECTED. It does not, on its own, prove the
// test could tell a working control from a severed or disconnected one — and that is the
// distinction the whole exercise turns on, so it is measured rather than argued. Two scenarios
// mutate the subject the way tests/docker/reachability/M1 and M2 describe and assert the negative
// test's verdict CHANGES.
//
// The mutant is written back into tests/docker/ because the subject resolves the Python reader
// from its own __DIR__; a copy anywhere else would fail for a reason that is not the mutation.
$subject = $root . '/tests/docker/verify-export-fingerprint.php';
if (!empty($scenario['mutate_subject'])) {
    $subject = slimstat_neg_mutate(
        $name,
        $subject,
        $scenario['mutate_subject'],
        $root . '/tests/docker/.fp-negative-mutant-' . getmypid() . '.php'
    );
}

require $subject;
