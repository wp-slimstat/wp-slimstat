<?php
/**
 * Every skip site in the E2E suite is counted, classified and pinned. The count only descends.
 *
 * ── WHY A CENSUS, AND WHY NOW ───────────────────────────────────────────────────────────────
 *
 * The Tier 2 lane is about to stop being advisory. A blocking lane and an unbounded skip budget
 * are the same lane: `test.skip()` turns a red test green, and nothing in the repository ever
 * counted how many times that had been done. The uncapped census (Run 65) put a number on it for
 * the first time — 79 skipped of 748 — and the number is only useful if something now holds it.
 *
 * `tests/E2E-QUARANTINE-CEILING` holds the three class counts. This file must reproduce them
 * EXACTLY, and they may only DESCEND. Exactly, not "at most": removing a skip without lowering
 * the ceiling leaves headroom for the next one, and headroom is how a ratchet becomes a dial —
 * the lesson `phpstan-baseline-ratchet-test.php` learned one altitude down.
 *
 * ── THE THREE CLASSES, AND WHY THE SPLIT IS NOT COSMETIC ─────────────────────────────────────
 *
 *   pro           the deliberate carve-out. CI installs no Pro (`ci.yml` overwrites `.wp-env.json`
 *                 to `{"plugins":["."]}`), so a guard that fires on Pro's ABSENCE is configuration
 *                 showing through, not test health. Named as its own number so it can never be
 *                 mistaken for the rest, and so shrinking it is visible if Pro ever joins the lane.
 *                 Since `helpers/pro-state.ts` landed there is exactly ONE such site in the whole
 *                 suite: `requireProBooted()` skips only when Pro is not on disk at all, and every
 *                 other Pro state — installed-but-off, active-but-never-booted — is an `expect`
 *                 that FAILS. That is the point. A0 (Pro deactivating itself on the first admin
 *                 request) hid for a full qualification round behind 34 guards that read
 *                 "Pro is not installed/active" and skipped on the defect they existed to catch.
 *   conditional   every other guarded skip: consent plugins, a missing .po, a project filter, a
 *                 precondition the test could not establish. Some of these are honest fixtures and
 *                 some are defects wearing a fixture's coat; the census does not adjudicate, it
 *                 counts.
 *   unconditional bare `test.skip()`, and declaration-form `test.skip('title', fn)` /
 *                 `test.fixme('title', fn)`. Coverage switched off with no condition and no ticket.
 *                 A permanently disabled test is worse than a deleted one — it carries the
 *                 reassurance of coverage with none of the cost.
 *
 * Per-class counts, not one total, because one total launders. Rewriting a bare `test.skip()` as
 * `test.skip(!proActive, …)` in a spec that has nothing to do with Pro would keep a single total
 * unchanged while moving a disabled test into the carve-out. Under three numbers that same edit
 * raises `pro`, and raising is what this file refuses.
 *
 * ── WHAT THE CENSUS DELIBERATELY CANNOT SEE ─────────────────────────────────────────────────
 *
 * 36 of Run 65's 79 skips carried NO annotation. They are not skip sites at all: 21 spec files
 * declare `mode: 'serial'`, and the first failure in such a group aborts the rest, which Playwright
 * reports as skipped. Those 36 are downstream of the 132 failures, and they return to the
 * denominator when the failures are fixed. A census that counted them would ratchet the cascade
 * into the contract and could then never descend past it. This file reads SOURCE, so it cannot
 * see them by construction — that is the design, not a limitation.
 *
 * The runtime counterpart, for the record: of the 43 annotated skips in Run 65, 33 were Pro and 10
 * were conditional. Those 33 are gone at source — `requireProBooted()` replaced them — so on a
 * lane WITH Pro installed the next uncapped run should annotate 10, and on the Pro-less CI lanes
 * the same 33 tests still skip, now through one site instead of 34.
 *
 * ── THE VACUITY FLOOR ───────────────────────────────────────────────────────────────────────
 *
 * A census that parses zero skips reports a clean suite in the same words it uses for a broken
 * scan, and it is the same defect one level up. So: the walk must find the suite it claims to
 * read, the classifier must place sites in every class, the one Pro carve-out site must actually
 * contribute to `pro`, and the Pro specs must hold no skip site of their own. Four recorded
 * instances in this programme of a scan that went green by reading nothing.
 *
 * Run: php tests/e2e-quarantine-census-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$e2e_dir     = $plugin_root . '/tests/e2e';
$ceiling_rel = 'tests/E2E-QUARANTINE-CEILING';
$failures    = [];

/**
 * The identifiers a spec uses to ask "is Pro on this install?".
 *
 * Membership alone does not make a site a Pro carve-out: the predicate must also be NEGATED.
 * `adminbar-chart-consistency.spec.ts` holds both polarities — `if (!isPro)` at :36 and :147 skip
 * because Pro is absent, which is the carve-out, while `if (isPro)` at :178 skips because Pro is
 * PRESENT, which on a Pro-less lane never fires at all. Run 65 confirms it: the first two are in
 * the artifact's skip list and the third is not. Collapsing the polarity would put a test that
 * always runs into the carve-out's budget.
 *
 * `pro_installed` is the live one: `helpers/pro-state.ts` skips on `!state.pro_installed` and on
 * nothing else. The older names are kept because a spec may reintroduce one, and a predicate this
 * file does not know lands in `conditional`, where it is invisible.
 */
$pro_identifiers = '(?:isProActive|proActive|pro_active|pro_installed|isPro)';

// ── Walk the suite ──────────────────────────────────────────────────────────────────────
$files = [];
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($e2e_dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ('ts' === strtolower($file->getExtension())) {
        $files[] = $file->getPathname();
    }
}
sort($files);

/**
 * Match the `)` closing the `(` at $open, stepping over quoted strings.
 *
 * Template literals matter here: `textdomain-timing.spec.ts:182` passes a backtick string holding
 * `${response?.status() ?? 'null'}`, whose parens would otherwise unbalance the scan and swallow
 * the rest of the file into one argument list.
 */
$match_paren = static function (string $s, int $open): int {
    $depth = 0;
    $len   = strlen($s);
    for ($i = $open; $i < $len; $i++) {
        $c = $s[$i];
        if ('\'' === $c || '"' === $c || '`' === $c) {
            $q = $c;
            for ($i++; $i < $len && $s[$i] !== $q; $i++) {
                if ('\\' === $s[$i]) {
                    $i++;
                }
            }
            continue;
        }
        if ('(' === $c) {
            $depth++;
        } elseif (')' === $c) {
            if (0 === --$depth) {
                return $i;
            }
        }
    }

    return -1;
};

/** The first top-level argument: split on a comma at nesting depth zero, outside strings. */
$first_arg = static function (string $args): string {
    $depth = 0;
    $len   = strlen($args);
    for ($i = 0; $i < $len; $i++) {
        $c = $args[$i];
        if ('\'' === $c || '"' === $c || '`' === $c) {
            $q = $c;
            for ($i++; $i < $len && $args[$i] !== $q; $i++) {
                if ('\\' === $args[$i]) {
                    $i++;
                }
            }
            continue;
        }
        if (false !== strpos('([{', $c)) {
            $depth++;
        } elseif (false !== strpos(')]}', $c)) {
            $depth--;
        } elseif (',' === $c && 0 === $depth) {
            return substr($args, 0, $i);
        }
    }

    return $args;
};

$counts = ['pro' => 0, 'conditional' => 0, 'unconditional' => 0];
$sites  = [];

foreach ($files as $path) {
    $rel   = slimstat_rel_path($plugin_root, $path);
    $lines = explode("\n", (string) file_get_contents($path));

    // Blank whole comment lines rather than stripping `//` anywhere, which would cut URLs and
    // regexes in half. Both false positives this suite actually has are prose: a docblock in
    // `consent-banner-e2e.spec.ts` and an example in `fixtures/index.ts`, and both are of this
    // shape. Hiding a real site inside a comment buys nothing — the ceiling is an equality, so a
    // site that vanishes fails exactly as loudly as one that appears.
    $scan = implode("\n", array_map(
        static fn(string $l): string => preg_match('#^\s*(//|\*|/\*)#', $l) ? '' : $l,
        $lines
    ));

    if (!preg_match_all('/\btest\.(?:skip|fixme)\s*\(/', $scan, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    foreach ($m[0] as $hit) {
        $open  = (int) strpos($scan, '(', $hit[1]);
        $close = $match_paren($scan, $open);
        if ($close < 0) {
            $failures[] = sprintf('%s: unbalanced argument list at a test.skip/fixme site; the '
                . 'classifier cannot read it, and an unreadable site is an uncounted one', $rel);
            continue;
        }

        $line = substr_count($scan, "\n", 0, $hit[1]) + 1;
        $args = trim(substr($scan, $open + 1, $close - $open - 1));
        $head = trim($first_arg($args));
        $tail = ltrim(substr($args, strlen($head)), ", \n\t");

        if ('' === $args) {
            $class = 'unconditional';                       // bare test.skip()
            $pred  = '(no condition)';
        } elseif (preg_match('/^[\'"`]/', $head) && preg_match('/^(?:async\s*)?(?:\(|function\b)/', $tail)) {
            $class = 'unconditional';                       // declaration form: the test never runs
            $pred  = '(declaration form) ' . $head;
        } else {
            $pred = $head;

            // `test.skip(true, …)` is the shape this suite uses INSIDE an `if`, so the literal is
            // not the condition — the enclosing `if` is. Reading only the argument would file
            // `if (!isPro) { test.skip(true, …) }` as unconditional and hand the Pro carve-out a
            // free pass out of its own budget. Walk back to the nearest line at a smaller indent,
            // and take it only if it opens an `if`, so a sibling's guard is never borrowed.
            if ('true' === $pred) {
                $pred   = '(no condition)';
                $indent = strlen($lines[$line - 1]) - strlen(ltrim($lines[$line - 1]));
                for ($j = $line - 2; $j >= 0; $j--) {
                    if ('' === trim($lines[$j])) {
                        continue;
                    }
                    $ind = strlen($lines[$j]) - strlen(ltrim($lines[$j]));
                    if ($ind >= $indent) {
                        continue;
                    }
                    if (preg_match('/^\s*(?:\}\s*else\s*)?if\s*\(/', $lines[$j])) {
                        $pred = trim($lines[$j]);
                    }
                    break;
                }
            }

            if ('(no condition)' === $pred) {
                $class = 'unconditional';
            } else {
                $stripped = (string) preg_replace('/^\s*(?:\}\s*else\s*)?if\s*\(/', '', $pred);
                $class    = preg_match('/^\s*!\s*\(?\s*(?:await\s+)?\(?\s*[\w.]*' . $pro_identifiers . '/', $stripped)
                    ? 'pro'
                    : 'conditional';
            }
        }

        $counts[$class]++;
        $sites[] = [$class, $rel, $line, $pred];
    }
}

// ── Vacuity floor ───────────────────────────────────────────────────────────────────────
if (count($files) < 100) {
    $failures[] = sprintf('walked only %d .ts file(s) under tests/e2e/; the suite has well over a '
        . 'hundred, so this census is counting a directory it is not actually reading', count($files));
}

$total = array_sum($counts);
// 51 sites stand after the 34 Pro guards collapsed into one helper. The floor sits under that and
// far above zero: it catches a matcher that stopped matching, it does not pin the total — the
// ceiling file does that, exactly and per class. Lower it only alongside a real removal.
if ($total < 45) {
    $failures[] = sprintf('classified only %d skip site(s); the suite has not held fewer than 50 '
        . 'since this census was written, so a count this small means the matcher stopped matching. '
        . 'Zero skips and a broken parse report themselves in the same words', $total);
}

foreach ($counts as $class => $n) {
    if (0 === $n) {
        $failures[] = sprintf('the `%s` class is empty. Every class in this census had members '
            . 'when it was written; an empty one means the classifier lost a branch, and a lost '
            . 'branch silently lowers the number this file exists to hold', $class);
    }
}

// Positive control on the carve-out specifically: `pro` is the class a loose predicate list
// over-counts and a stale one silently empties, and it is the one nobody re-reads.
$pro_files    = [];
$sites_by_rel = [];
foreach ($sites as [$class, $rel, $line]) {
    $sites_by_rel[$rel][] = $line;
    if ('pro' === $class) {
        $pro_files[$rel] = true;
    }
}
if (!isset($pro_files['tests/e2e/helpers/pro-state.ts'])) {
    $failures[] = 'tests/e2e/helpers/pro-state.ts contributes nothing to the `pro` class. It holds '
        . 'the suite\'s only Pro carve-out — `requireProBooted()` skipping on `!state.pro_installed` '
        . '— so either that guard is gone or the Pro predicate list has gone stale against it';
}

// The other half of the same control, and the reason A0 survived a qualification round: a Pro spec
// must FAIL on a Pro that is installed and broken, never skip. Any skip site of its own is a way
// back to that, so these files must contribute nothing at all, and must route through the helper.
foreach (['tests/e2e/pro-dbip-whois-data.spec.ts', 'tests/e2e/pro-version-floor-check.spec.ts'] as $spec) {
    if (isset($sites_by_rel[$spec])) {
        $failures[] = sprintf('%s carries its own skip site(s) at line(s) %s. A Pro spec skips only '
            . 'through requireProBooted(), which skips only when Pro is absent from the disk; every '
            . 'other Pro state is the defect the spec exists to catch', $spec,
            implode(', ', $sites_by_rel[$spec]));
    }
    if (false === strpos((string) file_get_contents($plugin_root . '/' . $spec), 'requireProBooted')) {
        $failures[] = sprintf('%s never calls requireProBooted(), so nothing in it asserts that Pro '
            . 'actually booted before its Pro assertions run', $spec);
    }
}

// ── The committed ceiling: exact, and descending only ───────────────────────────────────
$parse = static function (?string $raw): ?array {
    if (null === $raw) {
        return null;
    }
    $out = [];
    foreach (explode("\n", $raw) as $l) {
        $l = trim($l);
        if ('' === $l || '#' === $l[0]) {
            continue;
        }
        if (!preg_match('/^(pro|conditional|unconditional)\s+(\d+)$/', $l, $m)) {
            return null;
        }
        $out[$m[1]] = (int) $m[2];
    }

    return 3 === count($out) ? $out : null;
};

$ceiling = $parse(@file_get_contents($plugin_root . '/' . $ceiling_rel) ?: null);

// The last COMMITTED value from where this change stands: on a committed tree (CI) the file on
// disk IS HEAD's copy, so HEAD^ holds the previous value; on a dirty working tree HEAD does.
$on_disk_is_committed = ($parse(slimstat_git_show($plugin_root, 'HEAD', $ceiling_rel)) === $ceiling);
$previous             = $parse(slimstat_git_show($plugin_root, $on_disk_is_committed ? 'HEAD^' : 'HEAD', $ceiling_rel));

if (null === $ceiling) {
    $failures[] = $ceiling_rel . ' is missing or malformed; it must hold one `<class> <count>` line '
        . 'for each of pro, conditional and unconditional';
} else {
    foreach ($counts as $class => $n) {
        if ($ceiling[$class] !== $n) {
            $failures[] = sprintf(
                'the suite has %d `%s` skip site(s); %s says %d. If you REMOVED a skip, lower the '
                    . 'ceiling in this commit — leaving the old number is headroom for the next '
                    . 'one. If you ADDED one, that is the thing this gate exists to refuse',
                $n,
                $class,
                $ceiling_rel,
                $ceiling[$class]
            );
        }
    }
}

if (null === $previous) {
    echo "NOTE: no committed ceiling to compare against (new file, or no parent commit) — the "
        . "descent check is skipped this run.\n";
} elseif (null !== $ceiling) {
    foreach ($counts as $class => $n) {
        if ($ceiling[$class] > $previous[$class]) {
            $failures[] = sprintf(
                '%s raised `%s` from %d to %d. The ceiling only descends. A blocking lane with a '
                    . 'growable skip budget is an advisory lane wearing a red tick',
                $ceiling_rel,
                $class,
                $previous[$class],
                $ceiling[$class]
            );
        }
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: e2e quarantine census (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    if (getenv('SLIMSTAT_CENSUS_VERBOSE')) {
        foreach ($sites as [$class, $rel, $line, $pred]) {
            fwrite(STDERR, sprintf("    %-13s %s:%d  %s\n", $class, $rel, $line, $pred));
        }
    }
    exit(1);
}

printf(
    "PASS: %d skip site(s) across %d .ts file(s) — %d pro carve-out, %d conditional, %d "
        . "unconditional — matching %s exactly, and no class was raised\n",
    $total,
    count($files),
    $counts['pro'],
    $counts['conditional'],
    $counts['unconditional'],
    $ceiling_rel
);
