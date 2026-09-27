<?php
/**
 * Every duration a migration shows an admin is rendered from a MEASURED figure, and that figure
 * is quoted from the record that measured it.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────────────────────
 *
 * `AddUserAgentDimension` carried "~14 s at 443k rows, ~5 min at 10M" in FOUR places — its class
 * docblock, its isOptional() docblock, AbstractMigration::isOptional()'s docblock, and a unit
 * test's header — while the sentence the admin actually reads said "on a large table it can take
 * several minutes". Two different claims about the same operation, differing by a factor of
 * twenty, inside one class.
 *
 * Neither was a measurement of this migration. The 14 s was Run 7's EXTRAPOLATION of step 1 (the
 * ALTER) from a 152k-row table, quoted throughout as the price of BOTH steps. When the whole
 * migration was finally run — Run 58, on 443,543 real rows — it went past eight minutes and was
 * interrupted, and it has never been observed finishing. So an admin deciding whether to accept a
 * rebuild that can pause tracking writes was reading a number roughly 34x under the only figure
 * anyone had, and the warning against that extrapolation sat in the SIBLING file
 * (AddVisitIdentity's header records that this operation class came in 1.7x under a Run 7
 * extrapolation when it was measured).
 *
 * ── WHY A CONSTANT, AND NOT A GATE OVER THE PROSE ───────────────────────────────────────────
 *
 * A gate over prose is a spell-check. `tests/record-citation-test.php` says this in its own
 * header and PITFALLS 104 says it outright: no scanner can tell a correct figure from a plausible
 * one. What a scanner CAN do is refuse to let the number exist twice. So:
 *
 *   1. the figure lives in ONE place, the `MEASURED_COST` constant;
 *   2. the user-facing sentence is RENDERED from it — asserted by CALLING getDescription() and
 *      requiring the rendered phrase to appear in it, not by grepping for a method name;
 *   3. the constant names a record and a heading, and this file resolves them — the anchor must
 *      match exactly one heading, and every quote must appear verbatim INSIDE that section;
 *   4. and the quotes must between them state the figure and the row count, and carry a
 *      floor marker if and only if the declaration claims one. Without 4 the rest is
 *      bookkeeping: a citation resolving to a section that says nothing about the declared
 *      number is decoration, and `seconds => 14` beside a quotation about eight minutes is
 *      precisely the defect being repaired.
 *
 * ── WHAT THIS DOES NOT ESTABLISH ────────────────────────────────────────────────────────────
 *
 * That the measurement is CORRECT, or that it still holds. Nothing here re-runs a migration.
 *
 * `engine` is author-asserted: Run 58's section does not name its server (the harness default,
 * `mysql:8.0`, lives in rehearse-upgrade.sh) and a check that reaches into a shell script to
 * confirm a string is a check that will rot.
 *
 * AND THE SUBJECT SET IS OPEN, WHICH IS THE REAL LIMIT. The obligation is triggered by a
 * migration's own description mentioning a duration, so a migration that says nothing owes
 * nothing — `RecoverCorruptedHeatmapPositions` is required, is classified unbounded by
 * tests/migration-deadline-test.php, and is exempt here by silence. Compare that gate, whose set
 * is CLOSED: it carries a whole-set classification map and an unknown migration defaults to FAIL.
 * Here an unknown migration defaults to PASS. Closing that means a cost column on the deadline
 * gate's table and a decision per migration; it is filed, not done, and this paragraph is the
 * record that it is a choice rather than an oversight.
 *
 * The literal patterns below were widened once already, after review measured 24 of 30 realistic
 * phrasings slipping through the first draft. They now cover unit abbreviations and spelled-out
 * numbers. They still will not catch "takes a while on large tables".
 *
 * ── MODES ───────────────────────────────────────────────────────────────────────────────────
 *
 * `jaan-to/` is a SIBLING of this plugin, so every CI lane — which checks out the plugin alone —
 * cannot see the records at all. Section 4 is skipped there, LOUDLY: the mode is printed, and
 * sections 1-3 and the fixtures run in both. The fixtures matter most in the standalone mode,
 * because they are the only thing exercising the resolver and the figure matcher there.
 *
 * 7.4-safe: bare PHP, no PHPUnit, no WordPress, no vendor autoloader.
 *
 * Run: php tests/migration-duration-honesty-test.php
 */

declare(strict_types=1);

// Never executable over HTTP: these scripts run to completion, write to STDOUT/STDERR
// (undefined under a web SAPI) and can disclose absolute paths.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];
$controls    = [];

// Where the records are, which files are citable, and whether this checkout can see them —
// from the shared helper, so "the same set record-citation uses" is true by construction
// rather than by a comment claiming it.
$standing   = slimstat_standing_records($plugin_root);
$records    = $standing['records'];
$standalone = $standing['standalone'];
$failures   = array_merge($failures, $standing['problems']);

// The i18n stubs the renderer needs, defined BEFORE anything calls it: section 3 renders real
// descriptions, not only the fixtures at the end.
if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default')
    {
        return 1 === $number ? $single : $plural;
    }
}
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0)
    {
        return number_format((float) $number, (int) $decimals);
    }
}

/** English words for the small integers a duration realistically renders as. */
function mdh_number_words(): array
{
    return [
        1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
        7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve',
        13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen',
        17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty',
    ];
}

/**
 * The ways a record might write $n: as a decimal, as an integer, or spelled out.
 *
 * One helper for both units. It was two near-identical blocks, and they had already drifted —
 * the seconds list carried a one-decimal form the minutes list did not, for no stated reason.
 * That form is gone: for every real declaration it duplicated the trimmed decimal, and where it
 * differed it LOOSENED the match (a declaration of 8.25 would have been satisfied by a record
 * saying "8.3 s").
 *
 * @return string[]
 */
function mdh_number_forms(float $n): array
{
    $words = mdh_number_words();
    $whole = (int) round($n);

    $forms = [
        rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'),
        number_format($n, 2, '.', ''),
        (string) $whole,
    ];

    if (isset($words[$whole])) {
        $forms[] = $words[$whole];
    }

    return $forms;
}

/**
 * One alternation of number spellings followed by a unit, longest spelling first.
 *
 * Longest-first so "8.30" is tried before "8" and the trailing zero in a record that writes
 * "8.30 s" cannot leave "8" matching and then failing on the unit. The lookbehind stops "8"
 * inside "8.0.35", or "443" inside "443,543", from counting — and stops "4435.43 s" satisfying
 * a declaration of 43.
 */
function mdh_forms_pattern(array $forms, string $unit): string
{
    $forms = array_unique(array_filter($forms, static function ($form) {
        return '' !== $form;
    }));
    usort($forms, static function ($a, $b) {
        return strlen($b) <=> strlen($a);
    });

    $alternation = implode('|', array_map(static function ($form) {
        return preg_quote($form, '/');
    }, $forms));

    return '/(?<![\d.,\w])(?:' . $alternation . ')\s*' . $unit . '/i';
}

/** Does $text state $seconds as a duration, in either unit? */
function mdh_states_duration(string $text, float $seconds): bool
{
    $patterns = [mdh_forms_pattern(mdh_number_forms($seconds), '(?:s\b|secs?\b|seconds?\b)')];

    // Anything a renderer would show in minutes must be findable in minutes too: Run 58 wrote
    // "eight minutes", not "480 seconds", and a record is written for readers.
    if ($seconds >= 60.0) {
        $patterns[] = mdh_forms_pattern(mdh_number_forms($seconds / 60.0), '(?:m\b|mins?\b|minutes?\b)');
    }

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }

    return false;
}

/** Does $text state $rows, with or without thousands separators? */
function mdh_states_rows(string $text, int $rows): bool
{
    foreach ([number_format($rows), (string) $rows] as $form) {
        if (preg_match('/(?<![\d,])' . preg_quote($form, '/') . '(?![\d])/', $text)) {
            return true;
        }
    }

    return false;
}

/**
 * Does $text say the run did not finish?
 *
 * Scoped to the AUTHOR'S OWN QUOTED SPANS, never to the whole record — that distinction is what
 * makes this decidable rather than a spell-check. The spans are short, author-chosen, and already
 * required to appear verbatim in the resolved section, so requiring one of them to carry the
 * evidence for `bound` costs nothing and raises the bar on a floor->about relabel from editing
 * one word to re-quoting a different verbatim span.
 *
 * Phrases, not bare words: "over" and "past" alone would fire on ordinary prose.
 */
function mdh_states_floor(string $text): bool
{
    return (bool) preg_match(
        '/\b(?:ran past|past \*|more than|at least|exceeded|interrupted|stopped before|'
            . 'did not finish|never finished|without finishing)\b/i',
        $text
    );
}

/**
 * The Markdown section a heading anchor names, plus how many headings the anchor matched.
 *
 * The anchor must match at the START of a heading's text and end on a boundary. Both halves are
 * load-bearing against this repo's own records: `## Run 59 — the null control that certified Run
 * 58's timing …` CONTAINS "Run 58", so a substring rule makes that anchor ambiguous and a
 * plausible citation resolve to the wrong run; and "ADR-1" is a prefix of "ADR-19", so a bare
 * prefix rule silently redirects a citation to a longer-numbered decision.
 *
 * A count other than 1 is returned rather than resolved. An ambiguous citation is not a citation.
 *
 * @return array{0:string,1:int} the section text, and the number of headings matched
 */
function mdh_section(string $markdown, string $anchor): array
{
    $lines   = preg_split('/\R/', $markdown) ?: [];
    $total   = count($lines);
    $matched = [];

    foreach ($lines as $i => $line) {
        if (!preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            continue;
        }
        if (preg_match('/^' . preg_quote($anchor, '/') . '(?![\w-])/', trim($m[2]))) {
            $matched[] = [$i, strlen($m[1])];
        }
    }

    if (1 !== count($matched)) {
        return ['', count($matched)];
    }

    list($start, $level) = $matched[0];
    $end = $total;

    for ($j = $start + 1; $j < $total; $j++) {
        if (preg_match('/^(#{1,6})\s+/', $lines[$j], $m2) && strlen($m2[1]) <= $level) {
            $end = $j;
            break;
        }
    }

    return [implode("\n", array_slice($lines, $start, $end - $start)), 1];
}

// ── 1. The subject set comes from the tree ───────────────────────────────────────────

$migration_dir = $plugin_root . '/src/Migration/Migrations';
$files         = glob($migration_dir . '/*.php') ?: [];
sort($files);

// AND THE SHARED BASE, which the glob cannot see. Nine of the twelve migrations inherit
// getDescription() from AbstractIndexMigration, one directory up: a duration added to that one
// method would be shown for nine migrations and scanned for none of them. Found by review, on a
// gate whose control line said "12 migration classes scanned" while examining three descriptions.
$scanned = array_merge($files, [$plugin_root . '/src/Migration/AbstractIndexMigration.php']);

// VACUITY FLOOR. A glob that stopped matching makes every check below pass by having nothing to
// check — the shape PITFALLS 86 records, where a cell reported every migration green having run
// one. This is a floor on the SCAN, not a census: migrations are only ever added, so it never
// needs revising upward, and the closed whole-set map lives in tests/migration-deadline-test.php
// rather than being copied here.
if (count($files) < 10) {
    $failures[] = sprintf(
        'found only %d migration classes under src/Migration/Migrations — twelve exist. The scan '
            . 'has stopped seeing the tree and sections 2-4 would pass on an empty set',
        count($files)
    );
}

$declared = [];   // class short name => absolute path, for every file declaring MEASURED_COST
$mentions = 0;    // files whose user-facing copy states a duration at all

// A duration stated as a literal, and the vague quantifiers that are how "~14 s" survived being
// contradicted: "several minutes" is not a measurement and reads as one.
//
// Widened after review measured the first draft: of 30 realistic phrasings it caught SIX.
// Abbreviations ("90s", "20 mins", "2h") and spelled numbers ("ten minutes") were the two classes
// that slipped, and the spelled-number words were already in this file, used for quotes and not
// for the trigger.
$units            = '(?:s|secs?|seconds?|m|mins?|minutes?|h|hrs?|hours?)';
$duration_literal = '/(?<![\w.])\d+(?:[.,]\d+)?\s*' . $units . '\b/i';
$duration_vague   = '/\b(?:' . implode('|', mdh_number_words()) . '|a few|several|many|'
    . 'a couple of|some)\s+(?:seconds?|minutes?|hours?)\b|\b(?:half|a quarter) an hour\b/i';

foreach ($scanned as $file) {
    if (!is_file($file)) {
        $failures[] = sprintf('%s is in the scanned set and does not exist — re-point the scan',
            slimstat_rel_path($plugin_root, $file));
        continue;
    }

    $rel    = slimstat_rel_path($plugin_root, $file);
    $source = (string) file_get_contents($file);

    // Comments blanked, STRINGS KEPT: the subject here is the literal an admin reads, so
    // slimstat_strip_comments_and_strings() would erase exactly what must be inspected — and
    // the docblocks are full of the very figures being banned from the strings.
    $code = slimstat_blank_comments($source);

    $user_facing = '';
    foreach (['getName', 'getDescription'] as $method) {
        $body = slimstat_find_function_body($code, $method);
        if (null !== $body) {
            $user_facing .= "\n" . $body;
        }
    }

    $has_literal = (bool) preg_match($duration_literal, $user_facing, $hit);
    $has_vague   = (bool) preg_match($duration_vague, $user_facing, $vague);
    $has_const   = (bool) preg_match('/\bconst\s+MEASURED_COST\s*=/', $code);
    $renders     = false !== strpos($user_facing, 'measuredCostPhrase');

    if ($has_literal) {
        $failures[] = sprintf(
            '%s states "%s" to the admin as a literal. A duration is a MEASUREMENT: declare it in '
                . 'MEASURED_COST and render the sentence with $this->measuredCostPhrase(), so the '
                . 'figure exists once and this gate can tie it to the run that took it',
            $rel,
            trim($hit[0])
        );
    }

    if ($has_vague) {
        $failures[] = sprintf(
            '%s tells the admin "%s". That is not a measurement and it reads as one — it is the '
                . 'sentence AddUserAgentDimension showed while its own docblocks claimed a figure '
                . 'twenty times smaller. Render measuredCostPhrase() instead',
            $rel,
            trim($vague[0])
        );
    }

    if ($has_literal || $has_vague || $renders) {
        $mentions++;
    }

    // The only case the two checks above do not already report: a description that renders a
    // phrase for a constant that is not there. (A literal or a vague quantifier has already been
    // reported on this same file, for this same root cause; saying it twice is noise.)
    if ($renders && !$has_const) {
        $failures[] = sprintf(
            '%s renders measuredCostPhrase() and declares no MEASURED_COST, so the sentence the '
                . 'admin reads has an empty hole where the figure should be',
            $rel
        );
    }

    if ($has_const) {
        $declared[basename($file, '.php')] = $file;
    }
}

// VACUITY FLOOR, the other direction, DERIVED: three descriptions state a duration today, and
// every one of them is checked individually above. What this catches is the patterns silently
// ceasing to match — at which point the loop above finds nothing to demand and passes.
if ($mentions < 3) {
    $failures[] = sprintf(
        'only %d migration description(s) were found to state a duration; three do '
            . '(add-visit-identity, convert-tables-to-utf8mb4, add-user-agent-dimension). The '
            . 'trigger patterns have stopped matching, and with them every demand in this file',
        $mentions
    );
}

// ── 2 & 3. Every declared cost is well formed, renders, and says what its quotes say ─

// The real classes, not a parse of them: the value this asserts about must be the value the
// renderer receives. Loading is bare — these files declare a class and nothing else.
require_once $plugin_root . '/src/Migration/MigrationInterface.php';
require_once $plugin_root . '/src/Migration/AbstractMigration.php';

$costs    = [];
$resolved = [];   // class short name => [record, anchor], for the citations section 4 can chase

foreach ($declared as $short => $file) {
    require_once $file;
    $class = 'SlimStat\\Migration\\Migrations\\' . $short;

    if (!class_exists($class, false)) {
        $failures[] = sprintf('%s declares MEASURED_COST but %s did not load — the file name and '
            . 'the class name have diverged', slimstat_rel_path($plugin_root, $file), $class);
        continue;
    }

    $costs[$short] = constant($class . '::MEASURED_COST');
}

foreach ($costs as $short => $cost) {
    $where = 'src/Migration/Migrations/' . $short . '.php';
    $class = 'SlimStat\\Migration\\Migrations\\' . $short;

    if (!is_array($cost)) {
        $failures[] = sprintf('%s: MEASURED_COST must be an array; the base declares null and a '
            . 'migration that has no measurement simply leaves it alone', $where);
        continue;
    }

    $keys    = ['seconds', 'rows', 'engine', 'bound', 'record', 'anchor', 'quotes'];
    $missing = array_diff($keys, array_keys($cost));
    if ($missing) {
        $failures[] = sprintf('%s: MEASURED_COST is missing %s', $where, implode(', ', $missing));
        continue;
    }

    // Below one second the renderer has no honest shape — round(0.12) prints "0 seconds", and a
    // max(1, …) guard would print "1 second" for a figure eight times smaller. Refusing is the
    // correct answer until something sub-second needs describing; then the renderer grows a branch.
    if (!is_numeric($cost['seconds']) || (float) $cost['seconds'] < 1.0) {
        $failures[] = sprintf('%s: MEASURED_COST[seconds] must be a number of at least 1 — the '
            . 'renderer rounds to whole seconds and has no sub-second wording', $where);
    }

    if (!is_int($cost['rows']) || $cost['rows'] < 1) {
        $failures[] = sprintf('%s: MEASURED_COST[rows] must be the row count it was measured on', $where);
    }

    if (!in_array($cost['bound'], ['about', 'floor'], true)) {
        $failures[] = sprintf('%s: MEASURED_COST[bound] must be "about" (observed to completion) '
            . 'or "floor" (interrupted, so the figure is a lower bound), not %s',
            $where, var_export($cost['bound'], true));
    }

    if (!is_array($cost['quotes']) || [] === $cost['quotes']) {
        $failures[] = sprintf('%s: MEASURED_COST[quotes] is empty, so the citation resolves to a '
            . 'section and proves nothing about the figure', $where);
        continue;
    }

    // THE SENTENCE ACTUALLY CARRIES THE FIGURE. Rendered and compared, not grepped for a method
    // name: a template whose %s was dropped, or a constant the renderer cannot reach, both leave
    // the admin reading a sentence with a hole in it while every string check stays green.
    // Instantiated without the constructor because these descriptions touch no connection.
    $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $phrase   = $instance->measuredCostPhrase();
    $sentence = $instance->getDescription();

    if ('' === $phrase) {
        $failures[] = sprintf('%s: measuredCostPhrase() renders empty despite a declared '
            . 'MEASURED_COST — the constant is not reaching the renderer', $where);
    } elseif (false === strpos($sentence, $phrase)) {
        $failures[] = sprintf(
            '%s: getDescription() does not contain "%s". The constant is declared and cited and '
                . 'the admin never sees it — check the sprintf placeholder',
            $where,
            $phrase
        );
    }

    $all_quotes = slimstat_collapse_ws(implode(' ', $cost['quotes']));

    // THE CHECKS THAT MAKE THE CITATION LOAD-BEARING. seconds = 14 beside a quotation about eight
    // minutes is the defect this file was written for, and every other check here passes on it.
    if (is_numeric($cost['seconds']) && !mdh_states_duration($all_quotes, (float) $cost['seconds'])) {
        $failures[] = sprintf(
            '%s: MEASURED_COST[seconds] is %s, and none of the quoted spans state it. Either the '
                . 'figure is not the one the record took, or the quotation is decoration',
            $where,
            var_export($cost['seconds'], true)
        );
    }

    if (is_int($cost['rows']) && !mdh_states_rows($all_quotes, $cost['rows'])) {
        $failures[] = sprintf(
            '%s: MEASURED_COST[rows] is %d, and none of the quoted spans state it. A duration '
                . 'without the table it was measured on is not a measurement',
            $where,
            $cost['rows']
        );
    }

    // `bound` decides whether the admin reads "more than 8 minutes" or "about 8 minutes", so a
    // silent relabel is understatement in exactly the direction this file exists to stop.
    $quotes_floor = mdh_states_floor($all_quotes);
    if ('floor' === $cost['bound'] && !$quotes_floor) {
        $failures[] = sprintf(
            '%s: MEASURED_COST[bound] is "floor" — the admin is told "more than" — and no quoted '
                . 'span says the run did not finish. Quote the sentence that establishes it',
            $where
        );
    } elseif ('about' === $cost['bound'] && $quotes_floor) {
        $failures[] = sprintf(
            '%s: MEASURED_COST[bound] is "about" — the admin is told a duration — while a quoted '
                . 'span says the run did not finish. That figure is a floor',
            $where
        );
    }

    if (!isset($records[$cost['record']])) {
        $failures[] = sprintf('%s: MEASURED_COST[record] must name one of %s; got %s',
            $where, implode(', ', array_keys($records)), var_export($cost['record'], true));
        continue;
    }

    $resolved[$short] = [$cost['record'], (string) $cost['anchor'], $cost['quotes']];
}

// ── 4. The citation resolves, in the record it names ─────────────────────────────────

if ($standalone) {
    echo "MODE: standalone checkout — no jaan-to/ sibling, so section 4 (citation resolution) "
        . "is not run. Sections 1-3 and the fixtures below did run.\n";
} else {
    // Read once per RECORD, not once per citation: two of the three costs cite the same file.
    $bodies  = [];
    $chased  = 0;

    foreach ($resolved as $short => $citation) {
        list($record, $anchor, $quotes) = $citation;

        $where = 'src/Migration/Migrations/' . $short . '.php';
        $path  = $records[$record];

        if (!is_file($path)) {
            $failures[] = sprintf('%s cites %s, which does not exist at %s', $where, $record, $path);
            continue;
        }

        if (!isset($bodies[$path])) {
            $bodies[$path] = (string) file_get_contents($path);
        }

        list($section, $hits) = mdh_section($bodies[$path], $anchor);

        if (1 !== $hits) {
            $failures[] = sprintf(
                '%s cites "%s / %s" and that anchor matches %d headings in the record. A citation '
                    . 'nobody can resolve to one section is a citation nobody should trust',
                $where, $record, $anchor, $hits
            );
            continue;
        }

        $normalised = slimstat_collapse_ws($section);
        $chased++;

        foreach ($quotes as $quote) {
            if (false === strpos($normalised, slimstat_collapse_ws($quote))) {
                $failures[] = sprintf(
                    '%s quotes "%s", which does not appear under "%s" in %s. Either the entry was '
                        . 'rewritten or the span was spliced',
                    $where, $quote, $anchor, $record
                );
            }
        }
    }

    // What was actually chased, not what was declared: a cost dropped above for an unresolvable
    // record must not be counted as a citation this run verified.
    $controls[] = sprintf('%d of %d citation(s) resolved to a section and had every span checked, '
        . 'across %d record file(s)', $chased, count($costs), count($bodies));
}

// ── 5. Fixtures — the checker is exercised on every run, in every mode ───────────────
//
// Section 4 is skipped in a standalone checkout, so without these the resolver and the figure
// matcher would run NOWHERE on the six CI lanes. A gate whose own logic is exercised only on one
// developer's machine is a shape PITFALLS keeps recording.

$fixtures = [
    // the figure matcher — the check that makes a citation load-bearing
    ['accepts a decimal written with a trailing zero', true,
        mdh_states_duration('Measured 8.30 s for the pair', 8.30)],
    ['accepts a spelled minute count', true,
        mdh_states_duration('it ran past **eight minutes** on this dataset', 480)],
    ['REJECTS the restored figure against that same span', false,
        mdh_states_duration('it ran past **eight minutes** on this dataset', 14)],
    ['does not read a version number as a duration', false, mdh_states_duration('on MySQL 8.0.35', 8)],
    // The lookbehind, pinned by a case that fails without it. The obvious negatives — "443,543
    // rows", "MySQL 8.0.35" — fail on unit adjacency instead, and would pass with the lookbehind
    // deleted, so they prove nothing about it.
    ['does not read the tail of a longer number as a duration', false,
        mdh_states_duration('took 4435.43 s', 43)],
    ['matches a row count written with separators', true,
        mdh_states_rows('the 443,543-row reference', 443543)],
    ['does not match a row count inside a longer number', false,
        mdh_states_rows('4435430 rows', 443543)],

    // the bound marker, scoped to the author's own spans
    ['reads an interrupted run out of its own quotation', true,
        mdh_states_floor('it ran past **eight minutes** on this dataset')],
    ['does not read a completed measurement as interrupted', false,
        mdh_states_floor('Measured 8.30 s for the pair on the 443,543-row reference')],

    // the resolver — both halves of the anchor rule, against the shapes the real records have.
    // Trailing blank lines belong to the section: it runs to the line before the next heading,
    // and every comparison against it is whitespace-normalised anyway.
    ['resolves a heading anchor to its own section', "## Run 58 — a\ntext here\n",
        mdh_section("# Top\n\n## Run 58 — a\ntext here\n\n## Run 59 — about Run 58 timing\nother", 'Run 58')[0]],
    ['a mid-heading mention does not make the anchor ambiguous', 1,
        mdh_section("## Run 58 — a\n\n## Run 59 — about Run 58 timing\n", 'Run 58')[1]],
    ['a shorter number is not a prefix of a longer one', 0,
        mdh_section("## ADR-19 — something\ntext\n", 'ADR-1')[1]],
    ['an anchor that matches nothing resolves to nothing', 0, mdh_section("## Run 58 — a\n", 'Run 99')[1]],

    // the trigger patterns, which decide who owes a declaration at all
    ['catches an abbreviated unit', 1, preg_match($duration_literal, 'about 90s on our table')],
    ['catches a spelled-out duration', 1, preg_match($duration_vague, 'around ten minutes')],
    ['catches half an hour', 1, preg_match($duration_vague, 'up to half an hour')],
    ['does not fire on a charset name', 0, preg_match($duration_literal, 'utf8 (3-byte) to utf8mb4')],
];

// The renderer is the only place the admin's number is derived, so its rounding is checked here
// rather than trusted. Guarded rather than called blind: while it does not exist this gate must
// SAY that — a fatal "call to undefined method" is a red run that names PHP's problem, not ours.
$describe = ['SlimStat\Migration\AbstractMigration', 'describeMeasuredCost'];

if (!is_callable($describe)) {
    $failures[] = 'AbstractMigration::describeMeasuredCost() does not exist. It is the single '
        . 'place a measured cost becomes a sentence an admin reads; without it every duration is '
        . 'a literal somebody typed twice';
} else {
    $fixtures[] = ['renders an observed cost', 'about 8 seconds on a 440,000-row table (MySQL 8)',
        $describe(['seconds' => 8.30, 'rows' => 443543, 'engine' => 'MySQL 8', 'bound' => 'about'])];
    $fixtures[] = ['renders an interrupted run as a floor, in minutes',
        'more than 8 minutes on a 440,000-row table (MySQL 8)',
        $describe(['seconds' => 480, 'rows' => 443543, 'engine' => 'MySQL 8', 'bound' => 'floor'])];

    // The seconds/minutes boundary, pinned from both sides. Two minutes rather than one because
    // "about 90 seconds" tells a reader more than "about 2 minutes" does.
    $fixtures[] = ['stays in seconds below the boundary', 'about 119 seconds on a 12,000-row table (MariaDB 10.11)',
        $describe(['seconds' => 119, 'rows' => 12000, 'engine' => 'MariaDB 10.11', 'bound' => 'about'])];
    $fixtures[] = ['switches to minutes at the boundary', 'about 2 minutes on a 12,000-row table (MariaDB 10.11)',
        $describe(['seconds' => 120, 'rows' => 12000, 'engine' => 'MariaDB 10.11', 'bound' => 'about'])];
    $fixtures[] = ['singularises at the contract floor', 'about 1 second on a 12,000-row table (MariaDB 10.11)',
        $describe(['seconds' => 1, 'rows' => 12000, 'engine' => 'MariaDB 10.11', 'bound' => 'about'])];

    // ROW ROUNDING, from below. The first version of this renderer rounded to a fixed ten
    // thousand, so every table under 5,000 rows rendered as "a 0-row table" and 12,000 rendered
    // as 10,000 — the identical defect the one-second contract above exists to prevent, on the
    // field beside it, invisible because no fixture went below 5,000. Two significant figures now.
    $fixtures[] = ['a small table is not rounded to nothing', 'about 4 seconds on a 1-row table (MySQL 8)',
        $describe(['seconds' => 4, 'rows' => 1, 'engine' => 'MySQL 8', 'bound' => 'about'])];
    $fixtures[] = ['a few thousand rows keep two significant figures',
        'about 4 seconds on a 4,000-row table (MySQL 8)',
        $describe(['seconds' => 4, 'rows' => 4020, 'engine' => 'MySQL 8', 'bound' => 'about'])];
}

foreach ($fixtures as $fixture) {
    list($name, $expected, $actual) = $fixture;
    if ($expected !== $actual) {
        $failures[] = sprintf('FIXTURE "%s": expected %s, got %s', $name,
            var_export($expected, true), var_export($actual, true));
    }
}

$controls[] = sprintf('%d fixtures exercised the renderer, the figure matcher, the bound marker, '
    . 'the trigger patterns and the resolver', count($fixtures));
$controls[] = sprintf('%d file(s) scanned for user-facing durations, %d stating one, %d declaring '
    . 'a measured cost', count($scanned), $mentions, count($costs));

foreach ($controls as $control) {
    echo "CONTROL: {$control}\n";
}

if ($failures) {
    fwrite(STDERR, 'FAIL: migration duration honesty (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: every migration duration shown to an admin renders from a measured constant, and "
    . "every constant is quoted from the record section that measured it\n";
