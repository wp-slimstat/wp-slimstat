<?php
/**
 * A migration that can touch an unbounded row or table set must carry a per-request deadline.
 *
 * ── The defect this exists to catch ──────────────────────────────────────────────────────────
 *
 * `RecoverCorruptedHeatmapPositions` is REQUIRED — it declares no `isOptional()`, so it inherits
 * false and sits in the admin notice and in "Apply All" — and its `do { … } while` walked
 * `slim_events ⋈ slim_stats` to exhaustion with no time check anywhere in the file. On an events
 * table too large to finish inside `max_execution_time` the request dies, and because the
 * watermark was written only AFTER the loop, the next attempt rescanned from the same cursor. It
 * never converged, and "all migrations complete" was unreachable.
 *
 * `ConvertTablesToUtf8mb4` had no deadline either, and is the more expensive of the two: a
 * `CONVERT TO CHARACTER SET` per table across six tables, which the server performs as
 * ALGORITHM=COPY and which blocks writes for its duration. It was missed by the first draft of
 * this gate, which scanned for `do`/`while` — a shape it does not use.
 *
 * Measured before the fix: **2 of 12** migrations referenced any deadline.
 *
 * ── Why a declared classification and not a heuristic ────────────────────────────────────────
 *
 * "Does this loop touch unbounded work" is not decidable from tokens: the eight index migrations
 * issue one `CREATE INDEX` and need no deadline, `AddVisitIdentity` issues two ALTERs and has
 * nothing to checkpoint, and a future migration could hide a row walk behind a helper. So each
 * migration is CLASSIFIED here with its reason, and the whole-set check fails on any file absent
 * from the table — the same shape `tests/migration-optionality-test.php` uses, and for the same
 * reason: a new migration cannot be added without someone deciding which kind it is.
 *
 * ── Why PASS_SECONDS and not `microtime` ─────────────────────────────────────────────────────
 *
 * `AddVisitIdentity` calls `microtime(true)` — to stamp a cache version, not to bound anything. A
 * gate keyed on `microtime` would report that migration as budgeted and be wrong, which is the
 * failure mode this whole file exists to prevent one level down. `PASS_SECONDS` is the name the
 * one correct implementation already uses and cannot be produced by a timestamp.
 *
 * The constant must also be USED, not merely declared: a class carrying
 * `private const PASS_SECONDS = 10;` and never reading it is exactly as unbounded as one that
 * does not declare it, while looking budgeted to anyone grepping.
 *
 * ── Scope: what this does NOT establish ──────────────────────────────────────────────────────
 *
 * That the deadline is CHECKED often enough, that the batch size is sane, or that progress
 * survives an interruption. Those are behavioural and belong to
 * `tests/Unit/Migration/MigrationDeadlineTest.php`, which drives the loops and asserts the
 * watermark advances. This pins the structural half: an unbounded migration that ships without a
 * budget at all.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

/**
 * Every migration, and whether its run() can touch an unbounded row or table set.
 *
 * `unbounded` means "the work grows with the data" and therefore owes a per-request deadline.
 * `bounded` means the statement count is fixed by the schema, not by the table's size — a single
 * DDL statement is bounded however long it takes, because there is nothing to resume BETWEEN.
 */
$classification = [
    // ── unbounded: the work grows with the data ──
    'AddUserAgentDimension.php'            => 'unbounded',
    'ConvertTablesToUtf8mb4.php'           => 'unbounded',
    'RecoverCorruptedHeatmapPositions.php' => 'unbounded',

    // ── bounded: a fixed number of statements ──
    'AddVisitIdentity.php'      => 'bounded',   // two ALTERs plus one index build
    'CreateCountryDtIndex.php'  => 'bounded',   // one CREATE INDEX
    'CreateDtBrowserIndex.php'  => 'bounded',
    'CreateDtOutIndex.php'      => 'bounded',
    'CreateDtPlatformIndex.php' => 'bounded',
    'CreateDtScreenIndex.php'   => 'bounded',
    'CreateEventsNotesDtIndex.php' => 'bounded',
    'CreateFunnelQueriesIndex.php' => 'bounded',
    'CreateGoalQueriesIndex.php'   => 'bounded',
];

$files = glob($plugin_root . '/src/Migration/Migrations/*.php') ?: [];

// VACUITY FLOOR. A broken glob makes every check below pass by iterating nothing — and a
// PARTIAL glob is worse than an empty one: three files, all three unbounded, leaves the unbounded
// floor satisfied, the whole-set check silent (it only fires on files it SEES that are
// unclassified) and nine migrations uninspected.
//
// Derived from the table rather than written as a literal. A hardcoded 12 decays into a stale
// lower bound the moment a thirteenth migration lands, and it would have to be hand-bumped in
// lockstep with the map directly above it — which is the class of number this repo's PITFALLS
// record is mostly about.
if (count($files) < count($classification)) {
    $failures[] = 'found only ' . count($files) . ' migration file(s) but the classification '
        . 'describes ' . count($classification) . ' — the glob is wrong and this gate is '
        . 'inspecting less than it appears to';
}

$unbounded_checked = 0;

foreach ($files as $file) {
    $name = basename($file);

    // WHOLE-SET. A migration absent from the table is not silently assumed bounded: someone has
    // to decide, and this is where the decision is written down.
    if (!isset($classification[$name])) {
        $failures[] = sprintf(
            '%s is not classified in this gate. Decide whether its run() can touch an unbounded '
                . 'row or table set and add it as `unbounded` or `bounded`. Defaulting either way '
                . 'is how RecoverCorruptedHeatmapPositions shipped REQUIRED with no time budget',
            $name
        );
        continue;
    }

    if ('unbounded' !== $classification[$name]) {
        continue;
    }

    $unbounded_checked++;

    // Comments and strings blanked first: a docblock describing the budget it used to have, or a
    // SQL literal mentioning it, must not satisfy a check for the budget itself.
    $code       = slimstat_strip_comments_and_strings((string) file_get_contents($file), true);
    $references = preg_match_all('/\bPASS_SECONDS\b/', $code);

    if ($references < 1) {
        $failures[] = sprintf(
            '%s is classified `unbounded` but declares no PASS_SECONDS deadline. Its work grows '
                . 'with the table, so on a large install the request dies partway and — unless '
                . 'progress is persisted as it goes — the next attempt starts over. That is how '
                . '"all migrations complete" becomes unreachable',
            $name
        );
        continue;
    }

    // Declared AND used. One occurrence is the declaration alone, which reads as budgeted while
    // bounding nothing.
    if ($references < 2) {
        $failures[] = sprintf(
            '%s declares PASS_SECONDS but never reads it — the constant appears exactly once. A '
                . 'declared-but-unused budget is as unbounded as no budget at all, and harder to '
                . 'notice because grepping for the name finds it',
            $name
        );

        continue;
    }

    // AND COMPARED. Two references is satisfied by a deadline that is computed and then never
    // tested — the bypass this file recorded against itself and left open. It was closed first
    // in tests/network-activation-bounded-test.php, for a walk outside this census, which left
    // the three migrations here on the weaker check the same author had already judged
    // insufficient. A guard's strong form belongs in the gate that needs it.
    // BOTH OPERAND ORDERS, because the first version accepted only `microtime(true) >= $deadline`
    // and rejected `$deadline <= microtime(true)` — a correct program — with a message saying
    // the comparison was absent. Right verdict for the wrong reason is how a gate gets relaxed;
    // wrong verdict with a confident message is how it gets deleted. Still a SPELLING check, and
    // the failure text says so, because a scanner cannot recognise every way to compare a clock.
    if (!preg_match('/microtime\\(\\s*true\\s*\\)\\s*>=?\\s*\\$deadline|\\$deadline\\s*<=?\\s*microtime\\(\\s*true\\s*\\)/', $code)) {
        $failures[] = sprintf(
            '%s computes a PASS_SECONDS deadline and never compares the clock against it — or '
                . 'compares it in a spelling this gate does not recognise. It reads '
                . '`microtime(true) >= $deadline` and `$deadline <= microtime(true)`, either '
                . 'operator strict or not; if yours is correct and different, widen this pattern '
                . 'rather than dropping the check. The constant is declared, the deadline is '
                . 'assigned, and the loop runs to completion regardless — which is exactly what '
                . 'the two-reference check above cannot tell apart from a working budget',
            $name
        );
    }
}

// VACUITY FLOOR. If nothing is classified unbounded the loop above asserted nothing, which is
// also what a mis-keyed table looks like.
if ($unbounded_checked < 3) {
    $failures[] = 'only ' . $unbounded_checked . ' migration(s) were checked as unbounded; '
        . 'expected at least 3 (AddUserAgentDimension, ConvertTablesToUtf8mb4, '
        . 'RecoverCorruptedHeatmapPositions). The classification table has drifted from the '
        . 'filenames on disk and this gate is inspecting less than it appears to';
}

// ── Progress must be persisted INSIDE the loop, not after it ─────────────────────────
//
// A deadline is only safe if stopping early keeps what was done. RecoverCorruptedHeatmapPositions
// wrote its watermark once, after the walk: the UPDATEs it had applied survived, but the SCAN did
// not, so an interrupted pass resumed from the same cursor and — most candidates being permanently
// unrecoverable — found the same nothing every time.
//
// Only migrations that resume from a STORED CURSOR are listed. AddUserAgentDimension resumes from
// `WHERE ua_id IS NULL`, which is state in the data rather than in an option, so it has no cursor
// to checkpoint and belongs nowhere in this map.
$cursor_migrations = [
    'RecoverCorruptedHeatmapPositions.php' => 'persistWatermark',
];

foreach ($cursor_migrations as $name => $method) {
    $path = $plugin_root . '/src/Migration/Migrations/' . $name;
    if (!is_file($path)) {
        $failures[] = "the cursor list names {$name}, which no longer exists";
        continue;
    }

    $body = slimstat_function_body((string) file_get_contents($path), 'run');
    if ('' === $body) {
        $failures[] = "{$name} has no run() body this gate can read — the tokeniser found nothing, "
            . 'so the check below would pass by inspecting an empty string';
        continue;
    }

    $tokens = slimstat_tokenize($body, false);
    $count  = count($tokens);

    // The loop that walks the cursor. `do` rather than a generic loop keyword: this is the one
    // construct whose body must hold the persistence call, and naming it keeps the failure honest
    // if the loop is ever rewritten as something else.
    $do = null;
    for ($i = 0; $i < $count; $i++) {
        if (is_array($tokens[$i]) && T_DO === $tokens[$i][0]) {
            $do = $i;
            break;
        }
    }

    if (null === $do) {
        $failures[] = "{$name} no longer contains a `do` loop, so this gate cannot locate the walk "
            . 'whose progress it exists to check. Re-point it at the new construct rather than '
            . 'deleting the check';
        continue;
    }

    $range = slimstat_token_block_range($tokens, $do, $count);
    if (null === $range) {
        $failures[] = "{$name}'s `do` block is unbalanced to the tokeniser — the check below would "
            . 'inspect nothing';
        continue;
    }

    // AT THE LOOP'S OWN STATEMENT LEVEL, not merely somewhere inside it. Asking only "does the
    // call appear between the braces" is VACUOUS here: the failure branch
    // (`if ($result === false) { persistWatermark(...); return false; }`) also sits inside the
    // loop, so removing the success-path call leaves that check green. Proven by perturbation.
    //
    // The walk itself is slimstat_statement_level_call() rather than an inline depth counter,
    // because the inline version compared token TEXT and went silently vacuous on `"${x}"` —
    // see that helper's docblock. Sharing slimstat_token_block_range()'s brace-token set is the
    // point: the two must agree about what opens a brace, and `run()` here is built entirely
    // from interpolated SQL.
    if (!slimstat_statement_level_call($tokens, $range[0], $range[1], $method)) {
        $failures[] = sprintf(
            '%s never calls %s() at the statement level of its `do` loop — only inside a branch, '
                . 'or after the walk. Progress recorded solely on the failure path or solely at '
                . 'the end means an interrupted pass records NOTHING, and the next attempt '
                . 'rescans from the same cursor. On a table too large to finish in one request '
                . 'that never converges, and the migration is REQUIRED, so "all migrations '
                . 'complete" becomes unreachable',
            $name,
            $method
        );
    }
}

// A stale classification is its own defect: it records a decision about a file that is gone.
foreach (array_keys($classification) as $name) {
    if (!is_file($plugin_root . '/src/Migration/Migrations/' . $name)) {
        $failures[] = "the classification names {$name}, which no longer exists in "
            . 'src/Migration/Migrations/ — remove the row rather than leaving a decision about '
            . 'a file nothing can check';
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: migration deadlines (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: all ' . $unbounded_checked . " unbounded migration(s) declare and read a PASS_SECONDS "
    . "deadline\n";
