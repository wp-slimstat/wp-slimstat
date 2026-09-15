<?php
// ONE NEGATIVE SCENARIO PER MAJOR CONTROL of tests/docker/verify-export-fingerprint.php.
//
// Each entry builds a world in which the named control's PROPERTY IS FALSE, and asserts what the
// subject then prints and exits with. The assertions are deliberately two-sided:
//
//   exit        the process status, which is the only thing a harness reads
//   contains    the [!!] line and the located failure text — a control that fails silently, or
//               fails with a message that names a different cause, is not a working control
//   absent      the [OK] line for the same control, so "it printed both" cannot pass
//
// `baseline` is not decoration. Without a green run over the same world, every red run below is
// consistent with the subject being broken for an unrelated reason, and the negative tests would
// be proving nothing — which is the exact defect the subject's own CONTROLS block exists to rule
// out, one layer up.

require_once __DIR__ . '/fixture.php';

/**
 * A varchar whose bytes are not empty and which a PAD SPACE collation calls empty. Kept out of
 * row 1 on purpose: the CONTROL 4 probe reads the FIRST row by ORDER BY, and a divergence there
 * would fail CONTROL 4 too and stop this scenario isolating CONTROL 8.
 */
function slimstat_neg_pad_only_row(array $rows)
{
    $rows['slim_stats'][2]['searchterms'] = '  ';
    return $rows;
}

function slimstat_neg_scenarios()
{
    $scenarios = [];

    // ── 0. The control run ───────────────────────────────────────────────────────────────────
    $scenarios['baseline'] = [
        'why'    => 'every control met over a corpus that exercises NULL, empty string, ASCII, '
            . 'multi-byte UTF-8, zero and a wide integer',
        'expect' => [
            'exit'     => 0,
            'contains' => [
                '[OK] 1 NON-VACUOUS', '[OK] 2 CAN-FAIL', '[OK] 3 INDEPENDENT',
                '[OK] 4 SERVER-SIDE', '[OK] 5 CORPUS-STABLE', '[OK] 6 SCHEMA-LIVE',
                '[OK] 7 ORDER-BOUND', '[OK] 8 EMPTY-EXACT', '[OK] 9 WHOLE-CORPUS',
                'forced=0', 'proven=2/2', 'PASS: the MySQL fingerprint equals',
            ],
            'absent'   => ['[!!]', 'FAIL:'],
        ],
    ];

    // ── CONTROL 1 — NON-VACUOUS. The corpus is empty, so every equality below it holds for a
    //    reason that is not evidence: a chain over zero rows is sha256(spec) on both sides.
    $scenarios['empty-corpus'] = [
        'why'    => 'CONTROL 1 false: both tables hold zero rows, so both sides agree by '
            . 'construction and nothing was compared',
        'rows'   => function (array $rows) {
            return ['slim_stats' => [], 'slim_events' => []];
        },
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 1 NON-VACUOUS',
                'CONTROL 1 (NON-VACUOUS) UNMET',
                'slim_stats=0(UNPROVEN)',
                'slim_events=0(UNPROVEN)',
                'a chain over zero rows is sha256(spec)',
                'proven=0/2',
            ],
            'absent'   => ['[OK] 1 NON-VACUOUS', 'PASS:'],
        ],
    ];

    // ── The same property from the other side: an empty table must be reported UNPROVEN, must
    //    not enter $proven, and must not be able to carry the run. One table still has rows, so
    //    the run legitimately passes — with proven=1/2, not 2/2.
    $scenarios['one-empty-table'] = [
        'why'    => 'an empty table is UNPROVEN, never PASS: it is excluded from $proven and from '
            . 'the rows the closing sentence claims',
        'rows'   => function (array $rows) {
            $rows['slim_events'] = [];
            return $rows;
        },
        'expect' => [
            'exit'     => 0,
            'contains' => [
                '[UNPROVEN] slim_events',
                'nothing about this table was compared',
                'slim_events=0(UNPROVEN)',
                'proven=1/2',
                '[OK] 1 NON-VACUOUS',
            ],
            'absent'   => ['[PASS    ] slim_events', '[!!]'],
        ],
    ];

    // ── The claim itself: chained_hash equality. A row is UPDATED in place between the
    //    fingerprint pass and the export pass — Storage::updateRow()'s shape, which leaves `rows`
    //    equal and moves only the MySQL side, i.e. byte-for-byte the topology of a defective SQL
    //    encoder. The localiser must not blame the encoder for it.
    $scenarios['hash-mismatch-midrun-update'] = [
        'why'    => 'hash equality false: the corpus is written to between the two passes, so the '
            . 'MySQL fingerprint describes an instant the export does not',
        'between_passes' => function ($engine) {
            $engine->corpus->exec("UPDATE `wp_slim_stats` SET `ip` = '203.0.113.99' WHERE `id` = 1");
        },
        'expect' => [
            'exit'     => 1,
            'contains' => [
                'CHAINED HASH differs — this is the claim',
                'mysql  (SQL encoder)',
                'python (re-encoded export)',
                'php    (wrote the export)',
                'so the drift is in THE CORPUS, not the code',
                'the MySQL side does not agree with itself',
                '[FAIL    ] slim_stats',
            ],
            'absent'   => ['PASS:', 'the drift is in the SQL encoder path'],
        ],
    ];

    // ── The other half of the same claim: `rows`. A DELETE between the passes moves the count,
    //    so $compare fires AND CONTROL 9 fires — COUNT(*) was taken in the first window.
    $scenarios['hash-mismatch-midrun-delete'] = [
        'why'    => 'rows equality false: a row is deleted between the two passes, so the export '
            . 'covers fewer rows than the fingerprint folded and than the server counted',
        'between_passes' => function ($engine) {
            $engine->corpus->exec('DELETE FROM `wp_slim_stats` WHERE `id` = 5');
        },
        'expect' => [
            'exit'     => 1,
            'contains' => [
                'slim_stats: rows differs — MySQL 5, export-via-Python 4',
                '[!!] 9 WHOLE-CORPUS',
                'CONTROL 9 (WHOLE-CORPUS) UNMET',
                'COUNT(*)=5, folded=5, exported=4',
            ],
            'absent'   => ['[OK] 9 WHOLE-CORPUS', 'PASS:'],
        ],
    ];

    // ── CONTROL 2 — CAN-FAIL. The comparison cannot detect a difference, because the reader
    //    MEMOISES: asked the same question twice it returns its first answer. A cached or
    //    otherwise inert second read is precisely how an equality stops being able to fail while
    //    every artifact of the run still shows two reads happening.
    $scenarios['reader-memoises'] = [
        'why'    => 'CONTROL 2 false: the Python reader is shimmed to cache by argv, so the '
            . 're-read of the corrupted export returns the CLEAN fingerprint',
        'env'    => ['PATH' => '{{SHIM_MEMO}}:' . getenv('PATH')],
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 2 CAN-FAIL',
                'CONTROL 2 (CAN-FAIL) UNMET',
                'IT DID NOT MOVE',
                // The victim is the SMALLEST proven table, not the first one.
                'flipped one byte of slim_events.event_description',
            ],
            'absent'   => ['[OK] 2 CAN-FAIL', 'PASS:'],
        ],
    ];

    // ── CONTROL 3 — INDEPENDENT. The second implementation never ran. This is the shape the
    //    control was written for: shell_exec returns a not-found message, json_decode returns
    //    null, and an ABSENCE would otherwise be reported as agreement.
    $scenarios['no-python3'] = [
        'why'    => 'CONTROL 3 false: python3 is not on PATH, so no second implementation '
            . 'produced anything to compare against',
        'env'    => ['PATH' => '{{EMPTY_DIR}}'],
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 3 INDEPENDENT',
                'CONTROL 3 (INDEPENDENT) UNMET',
                // The greppable header keeps key=value intact even though the shell's answer has
                // spaces in it; the control's own detail carries the raw answer.
                'python=NOT-FOUND',
                // NOT 'command not found': shell_exec() runs /bin/sh, which is bash on macOS
                // ("sh: python3: command not found") and dash on Linux CI ("sh: 1: python3: not
                // found"). Lowercase 'not found' is the only wording common to both, and strpos
                // is case-sensitive, so it cannot match the subject's own NOT-FOUND markers.
                // PITFALLS 93. Pinned by tests/docker/mutations/A1-shell-wording-bash-only-01.
                'not found',
                'tables-read=0',
                'the Python reader returned no fingerprint',
            ],
            'absent'   => ['[OK] 3 INDEPENDENT', 'PASS:'],
        ],
    ];

    // ── CONTROL 4 — SERVER-SIDE, part (a): the per-column NULL-guard census. row_sql() is loaded
    //    from a variant that drops the guard for ONE column out of thirty-three. `ip` is non-NULL
    //    in every row of this corpus, so the emitted SQL still evaluates to the same bytes and
    //    NOTHING ELSE IN THE RUN MOVES — which is the point. The census is the only thing that
    //    can see it, and CONCAT_WS-style silent field loss is what it is standing in front of.
    $scenarios['row-sql-guard-dropped'] = [
        'why'    => 'CONTROL 4(a) false: the row expression is no longer fully NULL-guarded',
        'mutate_lib' => [
            [
                <<<'FIND'
            $token    = "IF({$quoted} IS NULL, '\\\\NUL', {$token})";
FIND
                ,
                <<<'REPLACE'
            if ('ip' !== $name) { $token = "IF({$quoted} IS NULL, '\\\\NUL', {$token})"; }
REPLACE
                ,
                'the NULL-guard line in slimstat_fp2_row_sql()',
            ],
        ],
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 4 SERVER-SIDE',
                'CONTROL 4 (SERVER-SIDE) UNMET',
                'the emitted expression is not server-side and fully guarded',
                'unguarded: ip',
                // Each field contributes its token TWICE — once inside CHAR_LENGTH() and once as
                // the value — so the census counts 2 per column, which is why one dropped guard
                // shows as 66 against 64 rather than 33 against 32.
                '66 HEX(CAST( against 64 NULL guards over 33 columns',
            ],
            'absent'   => ['[OK] 4 SERVER-SIDE', 'PASS:'],
        ],
    ];

    // ── CONTROL 4 — SERVER-SIDE, part (b): the server's bytes are not the PHP encoder's. A
    //    server whose HEX() renders lower case is not a thought experiment — HEX/CAST semantics
    //    across 8.0/5.7/5.6 are the reason run-rollup-floor.sh runs this file on every cell.
    $scenarios['server-hex-lowercase'] = [
        'why'    => "CONTROL 4(b) false: the server's HEX() disagrees with the PHP encoder's, so "
            . 'the SQL encoder and the PHP encoder no longer emit the same token',
        'cfg'    => function (array $cfg) {
            $cfg['hex_lowercase'] = true;
            return $cfg;
        },
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 4 SERVER-SIDE',
                'CONTROL 4 (SERVER-SIDE) UNMET',
                'server and PHP encoders part at byte',
                'CHAINED HASH differs',
            ],
            'absent'   => ['[OK] 4 SERVER-SIDE', 'PASS:'],
        ],
    ];

    // ── CONTROL 5 — CORPUS-STABLE. Cron is live, wp_slimstat_purge is scheduled, and retention
    //    is finite, so the boot that ran this file could have DELETEd from the corpus it is
    //    certifying. NOTE: in the pristine subject this control's failure DOES reach $failures
    //    and DOES change the exit code — the M2 mutation is what makes it advisory.
    $scenarios['cron-can-purge'] = [
        'why'    => 'CONTROL 5 false: DISABLE_WP_CRON is not defined, wp_slimstat_purge is '
            . 'scheduled, and auto_purge is 120 days',
        'cron_capable' => true,
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 5 CORPUS-STABLE',
                'CONTROL 5 (CORPUS-STABLE) UNMET',
                'DISABLE_WP_CRON=NOT DEFINED',
                'auto_purge=120 days',
            ],
            'absent'   => ['[OK] 5 CORPUS-STABLE', 'PASS:'],
        ],
    ];

    // ── CONTROL 6 — SCHEMA-LIVE, drift: the catalogue holds a NARROWER varchar than the manifest
    //    declares. Narrowing is data loss and must move the hash; canonical_type() keeps varchar
    //    lengths for exactly this case.
    $scenarios['catalogue-type-drift'] = [
        'why'    => 'CONTROL 6 false: the server catalogue narrows ip to varchar(32) while the '
            . 'manifest hashes varchar(39)',
        'cfg'    => function (array $cfg) {
            $cfg['catalogue']['wp_slim_stats']['ip'] = ['varchar(32)', true];
            return $cfg;
        },
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 6 SCHEMA-LIVE',
                'CONTROL 6 (SCHEMA-LIVE) UNMET',
                'the manifest describes a schema this server does not have',
                'ip declared varchar(39), server has varchar(32)',
            ],
            'absent'   => ['[OK] 6 SCHEMA-LIVE', 'PASS:'],
        ],
    ];

    // ── CONTROL 6 — SCHEMA-LIVE, totality: the ORDER BY carries no single-column UNIQUE index,
    //    so the ordering admits ties and the chained hash is not a function of the corpus at all.
    //    Restore a dump without its PRIMARY KEY and this is the state; both sides would still
    //    agree, for no reason.
    $scenarios['no-unique-on-order-by'] = [
        'why'    => 'CONTROL 6 false: the ORDER BY column has no single-column UNIQUE index, so '
            . 'the ordering is not total',
        'cfg'    => function (array $cfg) {
            $cfg['unique_indexes']['wp_slim_stats'] = [];
            return $cfg;
        },
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 6 SCHEMA-LIVE',
                'ORDER BY id is not covered by a single-column UNIQUE index',
                'the ordering admits ties and the chained hash is not a function of the corpus',
            ],
            'absent'   => ['[OK] 6 SCHEMA-LIVE', 'PASS:'],
        ],
    ];

    // ── CONTROL 6 — SCHEMA-LIVE, census direction: a column the SERVER holds that the manifest
    //    declares nothing about. The census used to walk only the pinned set, where this is
    //    invisible; measure-arms.sh swaps code arms against one shared database, so it is
    //    reachable rather than hypothetical.
    $scenarios['undeclared-live-column'] = [
        'why'    => 'CONTROL 6 false: the server holds a column the manifest declares nothing '
            . 'about, and it is not one of the permitted v6-added exclusions',
        'cfg'    => function (array $cfg) {
            $cfg['catalogue']['wp_slim_stats']['engagement_score'] = ['varchar(8)', true];
            return $cfg;
        },
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 6 SCHEMA-LIVE',
                'engagement_score is on the server and the manifest declares no such column',
                'the only permitted extras are the v6-added vid_hash and ua_id',
            ],
            'absent'   => ['[OK] 6 SCHEMA-LIVE', 'PASS:'],
        ],
    ];

    // ── CONTROL 7 — ORDER-BOUND. The reader's ORDER BY does nothing. The export is written in
    //    MySQL's order, so an inert sort returns the same sequence and the same hash: every other
    //    control in the run still passes, the tables still compare EQUAL, and only the deliberate
    //    re-read under a different ordering can see it.
    $scenarios['reader-ignores-order-by'] = [
        'why'    => "CONTROL 7 false: the reader is shimmed to drop the ORDER BY from its scan "
            . 'while still hashing it into the manifest, so its sort governs nothing',
        'env'    => ['PATH' => '{{SHIM_INERT}}:' . getenv('PATH')],
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 7 ORDER-BOUND',
                'CONTROL 7 (ORDER-BOUND) UNMET',
                'IT DID NOT MOVE, so the ORDER BY the reader was handed did nothing',
            ],
            'absent'   => ['[OK] 7 ORDER-BOUND', 'PASS:'],
        ],
    ];

    // ── CONTROL 8 — EMPTY-EXACT. One pad-only varchar. The server calls it empty (PAD SPACE) and
    //    encodes \EMPTY; the PHP and Python encoders test BYTE LENGTH and encode 2020. This is
    //    the one adversarial class verify-sql-encoder.php cannot reach, because it binds every
    //    string as _binary, which is NO PAD.
    $scenarios['pad-only-varchar'] = [
        'why'    => 'CONTROL 8 false: a pinned varchar holds two spaces, which this collation '
            . 'calls empty and whose bytes are not',
        'rows'   => 'slimstat_neg_pad_only_row',
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 8 EMPTY-EXACT',
                'CONTROL 8 (EMPTY-EXACT) UNMET',
                'the server calls a value empty whose bytes are not, in searchterms (1 rows)',
                'CHAINED HASH differs',
            ],
            'absent'   => ['[OK] 8 EMPTY-EXACT', 'PASS:'],
        ],
    ];

    // ── CONTROL 9 — WHOLE-CORPUS. The server counts more rows than either streaming pass folded:
    //    a truncated read. Both passes use `while ($row = $result->fetch_row())` and mysqli is in
    //    MYSQLI_REPORT_OFF, so a mid-stream failure is indistinguishable from end-of-rows and the
    //    prefix would otherwise supply its own coverage claim.
    $scenarios['count-disagrees'] = [
        'why'    => 'CONTROL 9 false: COUNT(*) is 6 where both streaming passes folded 5 — the '
            . 'shape a position-deterministic read error takes',
        'cfg'    => function (array $cfg) {
            $cfg['count_override']['wp_slim_stats'] = '6';
            return $cfg;
        },
        'expect' => [
            'exit'     => 1,
            'contains' => [
                '[!!] 9 WHOLE-CORPUS',
                'CONTROL 9 (WHOLE-CORPUS) UNMET',
                'FAIL slim_stats: COUNT(*)=6, folded=5, exported=5',
            ],
            'absent'   => ['[OK] 9 WHOLE-CORPUS', 'PASS:'],
        ],
    ];

    // ── The subject's OWN fault-injection hook, run against the pristine file. This is the
    //    reachability half: force each control in turn and the run must print exactly one [!!] n
    //    AND exit 1. A control whose call site is dead prints no such line; a control whose
    //    failure records into nothing prints it and exits 0.
    foreach (range(1, 9) as $n) {
        $scenarios['forced-' . $n] = [
            'why'    => sprintf('the subject\'s own hook: control %d forced to fail on an '
                . 'otherwise-passing run', $n),
            'env'    => ['SLIMSTAT_FP_FORCE_CONTROL_FAIL' => (string) $n],
            'expect' => [
                'exit'     => 1,
                'contains' => [
                    '[!!] ' . $n . ' ',
                    'FORCED FAIL (SLIMSTAT_FP_FORCE_CONTROL_FAIL=' . $n . ')',
                    'UNMET',
                    'forced=' . $n,
                ],
                'absent'   => ['[OK] ' . $n . ' ', 'PASS:'],
            ],
        ];
    }
    $scenarios['forced-99'] = [
        'why'    => 'the hook cannot invent a control: an out-of-range n changes nothing, which '
            . 'is what says the nine [!!] lines above came from nine real call sites',
        'env'    => ['SLIMSTAT_FP_FORCE_CONTROL_FAIL' => '99'],
        'expect' => [
            'exit'     => 0,
            'contains' => ['[OK] 1 NON-VACUOUS', '[OK] 9 WHOLE-CORPUS', 'forced=99',
                'PASS (SELF-TEST forced=99, certifies nothing)'],
            'absent'   => ['[!!]'],
        ],
    ];

    // ── Do these negative tests DISCRIMINATE? ────────────────────────────────────────────────
    // Everything above shows a control going red when its property is false. That is necessary
    // and it is not sufficient: it does not, by itself, distinguish a working control from one
    // whose call site is dead or whose failure records into nothing. Both of those print exactly
    // as well as prose does. So the two mutations tests/docker/reachability/ describes are
    // applied to the subject and the SAME negative worlds are re-run — the verdict must change.
    //
    // The mutations are generated here by exact string replacement against the current subject
    // and refuse to apply if the anchor is not present exactly once, so a stale anchor fails the
    // scenario loudly instead of mutating something adjacent.

    // M1 — CONTROL 4's call site moves inside a closure nothing invokes. Run in the world where
    // CONTROL 4 genuinely fails (the dropped NULL guard). Nothing else in that world moves, so
    // the whole run comes back GREEN: no [!!] 4, no [OK] 4, exit 0. That is what says the [!!] 4
    // in row-sql-guard-dropped came from a live call site and not from the file's text.
    $scenarios['m1-control-4-severed'] = [
        'why'    => 'discrimination: with CONTROL 4 severed, the same defect that failed the run '
            . 'above passes it — the control prints nothing at all',
        'mutate_subject' => [
            [
                "    \$c4_ok = \$c4_probed && !in_array(false, \$c4_probed, true);\n    \$control(\n        \$c4_ok, 4, 'SERVER-SIDE',",
                "    \$c4_ok = \$c4_probed && !in_array(false, \$c4_probed, true);\n    \$severed_control_4 = function () use (\$control, \$c4_ok, \$c4_notes, \$indent) { \$control(\n        \$c4_ok, 4, 'SERVER-SIDE',",
                "CONTROL 4's call site",
            ],
            [
                "would agree with the Python side on every field and move nothing this file prints.'\n    );",
                "would agree with the Python side on every field and move nothing this file prints.'\n    ); };",
                "the close of CONTROL 4's argument list",
            ],
        ],
        'mutate_lib' => $scenarios['row-sql-guard-dropped']['mutate_lib'],
        'expect' => [
            'exit'     => 0,
            'contains' => ['PASS: the MySQL fingerprint equals', '[OK] 3 INDEPENDENT', '[OK] 5 CORPUS-STABLE'],
            'absent'   => ['[!!] 4 SERVER-SIDE', '[OK] 4 SERVER-SIDE', 'CONTROL 4 (SERVER-SIDE) UNMET'],
        ],
    ];

    // M2 — CONTROL 5 renders through a second renderer that records into nothing $failures reads.
    // Run in the world where CONTROL 5 genuinely fails. The `[!!] 5` line still appears, in the
    // block a reader is told to read first, ABOVE a PASS line, and the process exits 0. Worse
    // than a missing control, because it is evidence-shaped — and the reason the exit code is
    // asserted here and not only the printed line.
    $scenarios['m2-control-5-advisory'] = [
        'why'    => 'discrimination: with CONTROL 5 disconnected from $failures, the same '
            . 'schedulable purge prints [!!] 5 above a PASS line and exits 0',
        'mutate_subject' => [
            [
                "    \$control(\n        \$c5_ok, 5, 'CORPUS-STABLE',",
                "    \$control_advisory = function (\$ok, \$n, \$name, \$detail) use (&\$controls, \$forced) {\n"
                    . "        if (\$forced === \$n) { \$ok = false; }\n"
                    . "        \$controls[] = sprintf('[%s] %d %-13s %s', \$ok ? 'OK' : '!!', \$n, \$name, \$detail);\n"
                    . "    };\n    \$control_advisory(\n        \$c5_ok, 5, 'CORPUS-STABLE',",
                "CONTROL 5's call site",
            ],
        ],
        'cron_capable' => true,
        'expect' => [
            'exit'     => 0,
            'contains' => [
                '[!!] 5 CORPUS-STABLE',
                'DISABLE_WP_CRON=NOT DEFINED',
                'PASS: the MySQL fingerprint equals',
            ],
            'absent'   => ['CONTROL 5 (CORPUS-STABLE) UNMET', 'FAIL:'],
        ],
    ];

    // ── And do the harness's OWN guards fail? ────────────────────────────────────────────────
    // Both mutation vectors are string replacements against files that are under active edit. A
    // replacement that quietly stopped matching would leave the scenario running the PRISTINE
    // code and reporting whatever that does — a negative test that passes because its mutation
    // never applied is the same defect as a control that never runs, one layer down. So the
    // refusal is exercised: a deliberately stale anchor must abort with exit 2 and say so.
    $scenarios['stale-lib-anchor'] = [
        'why'    => "the harness's own guard: a mutation of fingerprint-v2.php whose anchor no "
            . 'longer matches must abort, not run the pristine library',
        'mutate_lib' => [
            [
                '$token = "IF({$quoted} WAS NULL, …)";   // never existed',
                '$token = "";',
                'a NULL-guard line that never existed',
            ],
        ],
        'expect' => [
            'exit'     => 2,
            'contains' => [
                'the anchor for a NULL-guard line that never existed matches 0 times in '
                    . 'fingerprint-v2.php, not exactly once',
                'regenerate it against the current file rather than fuzzing it in',
            ],
            'absent'   => ['CONTROLS (before any result', 'PASS:'],
        ],
    ];
    $scenarios['stale-subject-anchor'] = [
        'why'    => "the harness's own guard: a mutation of the subject whose anchor no longer "
            . 'matches exactly once must abort, not run the pristine subject',
        'mutate_subject' => [
            [
                "    \$control(\n        \$c5_ok, 5, 'NOT-THE-NAME',",
                'x',
                'a CONTROL 5 call site under a name it does not carry',
            ],
        ],
        'expect' => [
            'exit'     => 2,
            'contains' => [
                'the anchor for a CONTROL 5 call site under a name it does not carry matches 0 '
                    . 'times in verify-export-fingerprint.php, not exactly once',
                'regenerate it against the current file rather than fuzzing it in',
            ],
            'absent'   => ['CONTROLS (before any result', 'PASS:'],
        ],
    ];

    return $scenarios;
}
