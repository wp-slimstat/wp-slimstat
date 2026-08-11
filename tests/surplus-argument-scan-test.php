<?php
/**
 * Source-level: no call passes more arguments than the callee declares.
 *
 * PHP DISCARDS A SURPLUS ARGUMENT TO A USERLAND FUNCTION SILENTLY — no warning, no notice, no
 * deprecation, on any supported version. So the mistake is invisible at runtime and looks correct
 * on the page, which is the worst combination this programme keeps finding.
 *
 * IT SHIPPED. `wp_slimstat::date_i18n($_format)` declared ONE parameter, and
 * `get_overview_summary()` called it with two:
 *
 *   Today      dt >       date_i18n('U', mktime(0,0,0, m, d,   Y))
 *   Yesterday  dt BETWEEN date_i18n('U', mktime(0,0,0, m, d-1, Y))
 *                     AND date_i18n('U', mktime(23,59,59, m, d-1, Y))
 *
 * With the timestamp dropped every one of those became "now", so **Today asked for pageviews in
 * the future** and **Yesterday asked for a window zero seconds wide**. Measured before the fix:
 * Today rendered 0 against a true 5,051; Yesterday 0 against 1,418. Both had read 0 on every
 * install since the call sites were written — and the parity oracle had the report recorded as
 * *exempt, time-dependent*, so the one instrument that might have noticed was told not to look.
 *
 * SCOPE, STATED SO NOBODY READS THIS AS BROADER THAN IT IS. Static calls to `wp_slimstat::` and
 * `wp_slimstat_db::` only, resolved against the declarations in this plugin. Not instance calls,
 * not callables, not WordPress core, not variadics. That is where the defect happened and where
 * the arity is cheap to know for certain; guessing at the rest would trade a true finding for
 * false ones.
 *
 * 7.4-safe: `token_get_all()` only. No WordPress, no autoloader, no database.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$own_files = slimstat_own_php_files(
    [$plugin_root . '/admin', $plugin_root . '/src'],
    $plugin_root . '/src/Dependencies'
);
$own_files[] = $plugin_root . '/wp-slimstat.php';

/**
 * Declared parameter counts for the static classes under test.
 *
 * Read from the tokens rather than by reflection, because reflection needs the class loaded,
 * which needs WordPress — and this gate has to run in the PHP-only CI lanes.
 *
 * @return array<string,array{min:int,max:int}> method => arity, max = PHP_INT_MAX when variadic
 */
$declared = static function (string $file, string $wanted_class): array {
    $tokens = token_get_all((string) file_get_contents($file));
    $out    = [];
    $count  = count($tokens);
    $class  = '';

    for ($i = 0; $i < $count; $i++) {
        // SCOPED TO ONE CLASS. The first version keyed by bare method name across the whole
        // file, so the LAST declaration won regardless of which class it came from — and
        // wp-slimstat.php holds three scopes: class wp_slimstat, class slimstat_widget, and a
        // global function. A `slimstat_widget` method sharing a name with a `wp_slimstat` one
        // and declaring more parameters would silently raise the ceiling for the real method,
        // and surplus calls to it would stop being reported. Latent rather than live today —
        // no name collides — which is exactly when it is cheapest to fix.
        if (is_array($tokens[$i]) && T_CLASS === $tokens[$i][0]) {
            for ($k = $i + 1; $k < $count; $k++) {
                if (is_array($tokens[$k]) && T_STRING === $tokens[$k][0]) {
                    $class = $tokens[$k][1];
                    break;
                }
                if (!is_array($tokens[$k]) || T_WHITESPACE !== $tokens[$k][0]) {
                    break;
                }
            }
            continue;
        }

        if (!is_array($tokens[$i]) || T_FUNCTION !== $tokens[$i][0]) {
            continue;
        }

        // The name, then the parameter list between its own parentheses. `&` for a by-reference
        // RETURN sits between the two, and skipping the declaration on it — as the first version
        // did — drops the method from the map entirely, so surplus calls to it go unchecked.
        $j = $i + 1;
        while ($j < $count && (('&' === $tokens[$j])
            || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)))) {
            $j++;
        }
        if ($j >= $count || !is_array($tokens[$j]) || T_STRING !== $tokens[$j][0]) {
            continue; // a closure
        }

        if ($class !== $wanted_class) {
            continue;
        }

        $name = $tokens[$j][1];
        while ($j < $count && '(' !== $tokens[$j]) {
            $j++;
        }
        if ($j >= $count) {
            continue;
        }

        $depth    = 0;
        $required = 0;
        $total    = 0;
        $variadic = false;
        $seen     = false;
        $optional = false;

        for ($k = $j; $k < $count; $k++) {
            $t = $tokens[$k];

            if ('(' === $t) {
                $depth++;
                continue;
            }
            if (')' === $t) {
                $depth--;
                if (0 === $depth) {
                    break;
                }
                continue;
            }
            if (1 !== $depth) {
                continue; // a default value's own parentheses
            }
            if (is_array($t) && T_VARIABLE === $t[0]) {
                $seen = true;
                $total++;
                if (!$optional) {
                    $required++;
                }
                continue;
            }
            if (is_array($t) && T_ELLIPSIS === $t[0]) {
                $variadic = true;
                continue;
            }
            if ('=' === $t) {
                // Everything from the first defaulted parameter on is optional.
                $optional = true;
                if ($required > 0) {
                    $required--;
                }
                continue;
            }
            if (',' === $t) {
                $optional = false === $optional ? false : true;
            }
        }

        if ($seen || true) {
            $out[$name] = ['min' => $required, 'max' => $variadic ? PHP_INT_MAX : $total];
        }
    }

    return $out;
};

$targets = [
    'wp_slimstat'    => $declared($plugin_root . '/wp-slimstat.php', 'wp_slimstat'),
    'wp_slimstat_db' => $declared($plugin_root . '/admin/view/wp-slimstat-db.php', 'wp_slimstat_db'),
];

foreach ($targets as $class => $methods) {
    if (count($methods) < 5) {
        $failures[] = "only " . count($methods) . " method(s) parsed out of {$class} — the "
            . 'declaration scan is broken, so every call below would be compared against nothing';
    }
}

// ── walk every call site ────────────────────────────────────────────────────
$checked = 0;

foreach ($own_files as $file) {
    $rel    = ltrim(str_replace($plugin_root, '', $file), '/');
    $tokens = token_get_all((string) file_get_contents($file));
    $count  = count($tokens);

    for ($i = 0; $i < $count - 2; $i++) {
        // T_STRING **or** T_NAME_FULLY_QUALIFIED. On PHP 8.0+ `\wp_slimstat` lexes as a SINGLE
        // token, and the first version compared it to `wp_slimstat` and skipped it — so every
        // fully-qualified call was invisible: 242 sites across 38 files in src/, including the
        // two Tracker files this very seam edits. Proven, on the real tree:
        //
        //   \wp_slimstat::date_i18n('U', 1, 2, 3, 4)  ->  PASS, 110 call sites
        //     wp_slimstat::date_i18n('U', 1, 2, 3, 4)  ->  FAIL, "5 argument(s); it declares 2"
        //
        // Same call, same arity, one backslash apart. Worse, coverage depended on the INTERPRETER:
        // on 7.4 the same source lexes as T_NS_SEPARATOR + T_STRING and would have matched, so the
        // gate was strong on the floor and blind on the version CI actually runs.
        //
        // slimstat_last_name_segment() already exists in tests/lib/source-scan.php for exactly
        // this, documented with the same hazard. This was the one gate not using it.
        if (!is_array($tokens[$i])) {
            continue;
        }

        $is_name = T_STRING === $tokens[$i][0]
            || (defined('T_NAME_FULLY_QUALIFIED') && T_NAME_FULLY_QUALIFIED === $tokens[$i][0])
            || (defined('T_NAME_QUALIFIED') && T_NAME_QUALIFIED === $tokens[$i][0]);

        if (!$is_name || !isset($targets[slimstat_last_name_segment($tokens[$i][1])])) {
            continue;
        }
        if (!is_array($tokens[$i + 1]) || T_DOUBLE_COLON !== $tokens[$i + 1][0]) {
            continue;
        }
        if (!is_array($tokens[$i + 2]) || T_STRING !== $tokens[$i + 2][0]) {
            continue;
        }

        $class  = slimstat_last_name_segment($tokens[$i][1]);
        $method = $tokens[$i + 2][1];

        // The opening paren must follow immediately, or this is a constant, not a call.
        $j = $i + 3;
        while ($j < $count && is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) {
            $j++;
        }
        if ($j >= $count || '(' !== $tokens[$j]) {
            continue;
        }
        if (!isset($targets[$class][$method])) {
            continue; // inherited, dynamic, or declared elsewhere — not this gate's business
        }

        // Count top-level commas, bracket-aware, so a nested call's arguments are not counted.
        // BRACES COUNT TOO, and a trailing comma is not an argument. Without the brace depth a
        // closure passed as an argument leaks its statement-level commas into the count —
        // `m(function ($x, $y) { global $p, $q; return 1; }, 'z')` counted 3 where the truth is
        // 2 — and PHP 7.3+ permits `m('a', 'b',)`, which counted 3 for two arguments. Both are
        // FALSE FAILS: they block rather than admit, so they would have surfaced as a fabricated
        // "surplus argument" report naming a real bug, on the first call site that used either.
        $depth   = 0;
        $args    = 0;
        $any     = false;
        $pending = false;

        for ($k = $j; $k < $count; $k++) {
            $t = $tokens[$k];

            if ('(' === $t || '[' === $t || '{' === $t
                || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                continue;
            }
            if (')' === $t || ']' === $t || '}' === $t) {
                $depth--;
                if (0 === $depth) {
                    break;
                }
                continue;
            }
            if (1 === $depth) {
                if (',' === $t) {
                    $args++;
                    $pending = false;
                    continue;
                }
                if (!is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $any     = true;
                    $pending = true;
                }
            }
        }

        // A trailing comma leaves `$args` incremented with nothing after it.
        if ($any && $pending) {
            $args++;
        }

        $checked++;
        $max = $targets[$class][$method]['max'];

        if ($args > $max) {
            $failures[] = sprintf(
                '%s:%d calls %s::%s() with %d argument(s); it declares %d. PHP DISCARDS THE '
                    . 'SURPLUS SILENTLY — no warning on any supported version — so the call looks '
                    . 'correct and computes something else. This exact shape made the Overview\'s '
                    . '"Today" ask for pageviews in the future and render 0 against a true 5,051',
                $rel,
                $tokens[$i][2],
                $class,
                $method,
                $args,
                $max
            );
        }
    }
}

// Vacuity floor: a walk that resolves nothing passes perfectly.
// 223 resolvable sites today, up from 110 before the fully-qualified-name fix. The floor is set
// below that with margin: its job is to catch the walk going stale, not to pin an exact count
// that every new call site would have to chase.
if ($checked < 200) {
    $failures[] = sprintf(
        'only %d resolvable static call site(s) found (expected at least 200) — the token walk is '
            . 'stale, and every assertion above ran on almost nothing',
        $checked
    );
}

if ($failures) {
    fwrite(STDERR, 'FAIL: surplus arguments (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: {$checked} static call sites, none passing more arguments than the callee accepts\n";
