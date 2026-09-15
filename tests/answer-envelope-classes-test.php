<?php
/**
 * Source-level: the capture envelope tells `error`, `empty` and `zero` apart — for real.
 *
 * The WHY lives on slimstat_capture() itself (tests/docker/report-answers.php): a failed query
 * and an empty one are the same `[]`, and two reports compared equal for weeks on it. This file
 * is that function's GATE.
 *
 * Why it is a gate and not a unit test: the campaign's whole classification scheme — (a)/(b)/
 * (c)/(d), error ≠ empty ≠ zero — rests on this one function drawing three lines correctly, and
 * until now nothing executed it. The R4/R5 register amendment ("5.5.1's funnels ERROR on MySQL 8;
 * they do not report zero") is a claim about exactly that distinction, so it cites this test as
 * evidence the instrument that measured it cannot conflate the two.
 *
 * The subject is THE REAL slimstat_capture(), included under the SLIMSTAT_ANSWERS_LIB guard —
 * never a re-implementation, because two parsers of one contract drift (PITFALLS 5) and a test
 * proving a copy proves nothing about the instrument the campaign runs.
 *
 * ── A CONTRACT, not a preference ────────────────────────────────────────────────────────────
 * The failure line's wording is load-bearing. tests/verify/bin/run-mutations.php matches a
 * mutation's `expect:` as a SUBSTRING of this gate's stdout, and
 * tests/mutations/S1-error-reads-as-empty-01.mutation expects the exact string
 *
 *     failed-query class: expected 'error', got 'empty'
 *
 * which the sprintf below produces verbatim. Reword it, reorder its %s, or swap var_export() for
 * something prettier and the mutation silently downgrades KILLED → INVALID — a mutation that
 * proves nothing while still looking like coverage.
 *
 * 7.4-safe: bare PHP, no WordPress, no database, no vendor autoloader.
 */

declare(strict_types=1);

define('SLIMSTAT_ANSWERS_LIB', true);

/**
 * A wpdb-shaped double carrying only what the subject reaches: ->options, interpolated into the
 * purge, and ->query(). $raise_on_query makes the PURGE fail on demand, which is the only way to
 * prove the attribution case below is not vacuous — no existing stub in tests/ can do it.
 */
class Slimstat_Test_Wpdb
{
    public $options = 'wp_options';
    public $raise_on_query = false;

    public function query($sql)
    {
        if ($this->raise_on_query) {
            $GLOBALS['EZSQL_ERROR'][] = ['query' => (string) $sql, 'error_str' => 'purge failed (synthetic)'];
        }

        return 0;
    }
}

$GLOBALS['wpdb'] = new Slimstat_Test_Wpdb();
// The file's one piece of mutable global state, initialised explicitly rather than relied on to
// auto-vivify: an initial-state control is worth a line.
$GLOBALS['EZSQL_ERROR'] = [];

require __DIR__ . '/docker/report-answers.php';

$failures = [];
$checked  = 0;

$expect = static function (string $case, $actual, $expected) use (&$failures, &$checked): void {
    $checked++;
    if ($actual !== $expected) {
        // See the CONTRACT note above before touching this format string.
        $failures[] = sprintf(
            '%s: expected %s, got %s',
            $case,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
};

// ── error: a FAILED query returning [] must never read as empty ─────────────
// The whole test in one case. The closure does what wpdb does on a broken statement: appends to
// $EZSQL_ERROR and hands back a well-formed empty array.
$failing_sql = 'SELECT counthits FROM missing_table GROUP BY counthits';

$env = slimstat_capture(static function () use ($failing_sql) {
    $GLOBALS['EZSQL_ERROR'][] = [
        'query'     => $failing_sql,
        'error_str' => "Table 'wp.missing_table' doesn't exist",
    ];

    return [];
});
$expect('failed-query class', $env['class'], 'error');
$expect('failed-query keeps the statement', $env['error']['query'], $failing_sql);

// ── error: a throw is an error, not a silent null ───────────────────────────
// Before the envelope existed an exception killed the script; after it, an exception lands as
// null — and a null is not an array, so an emptiness test would have skipped it silently.
$env = slimstat_capture(static function () {
    throw new RuntimeException('boom');
});
$expect('thrown class', $env['class'], 'error');
$expect('thrown message survives', false !== strpos((string) $env['error']['str'], 'boom'), true);

// ── empty: an honest nothing is neither an error nor a zero ─────────────────
foreach ([[], null, ''] as $nothing) {
    $env = slimstat_capture(static function () use ($nothing) {
        return $nothing;
    });
    $expect('empty ' . var_export($nothing, true), $env['class'], 'empty');
}

// ── zero: a real measurement whose value is 0 — the third thing ─────────────
// '0' included because wpdb returns scalars as STRINGS, and a string zero collapsing into
// `empty` would erase the distinction R17's "Today reads 0" defect turns on.
foreach ([0, 0.0, '0'] as $zero) {
    $env = slimstat_capture(static function () use ($zero) {
        return $zero;
    });
    $expect('zero ' . var_export($zero, true), $env['class'], 'zero');
}

// ── ok: data is data ────────────────────────────────────────────────────────
$env = slimstat_capture(static function () {
    return [['resource' => '/a', 'counthits' => 3]];
});
$expect('rows class', $env['class'], 'ok');
$expect('rows counted', $env['rows'], 1);

$env = slimstat_capture(static function () {
    return 42;
});
$expect('scalar class', $env['class'], 'ok');
$expect('scalar carried', $env['scalar'], 42);

// ── attribution: the purge's own failure is never blamed on the surface ─────
// The purge runs BEFORE the error counter is read — the helper says so, and this is the case
// that goes red if someone reorders them. A purge error attributed to the report would be a lie
// about which code failed.
$GLOBALS['wpdb']->raise_on_query = true;
$env = slimstat_capture(static function () {
    return [['resource' => '/b', 'counthits' => 1]];
});
$GLOBALS['wpdb']->raise_on_query = false;
$expect('purge error not attributed', $env['class'], 'ok');

// ── truncation: error text is bounded, because CAPS is byte-compared ────────
// The measured length rides in the LABEL, so a failure says how long it actually was rather than
// only that a boolean came back false.
$env = slimstat_capture(static function () {
    $GLOBALS['EZSQL_ERROR'][] = ['query' => str_repeat('Q', 900), 'error_str' => str_repeat('E', 900)];

    return [];
});
$str_len   = strlen((string) $env['error']['str']);
$query_len = strlen((string) $env['error']['query']);
$expect(sprintf('error str bounded (%d chars)', $str_len), $str_len <= 300, true);
$expect(sprintf('error query bounded (%d chars)', $query_len), $query_len <= 300, true);

// ── Report ──────────────────────────────────────────────────────────────────
//
// A floor, because a counter nothing checks is decoration. Every assertion here is a literal call
// site, so the count is a constant — and that is exactly what makes a drop meaningful: it can
// only mean a case was deleted or an early exit swallowed the rest.
if ($checked < 17) {
    $failures[] = sprintf(
        'only %d assertions ran where 17 are expected — a case was removed or something exited early',
        $checked
    );
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: answer envelope classes (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: answer envelope classes ({$checked} assertions — error, empty and zero are three "
    . "different answers, a purge failure is never blamed on the surface, and error text is "
    . "bounded for the byte-compared record)\n";
exit(0);
