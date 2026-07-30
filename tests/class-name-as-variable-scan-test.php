<?php
/**
 * Source-level: own code never performs a static access through a variable.
 *
 * PINS FIX (S1). `unset($wp_slimstat::$settings['date_format'])` sat in
 * `update_tables_and_options()`'s `< 4.8.4` branch directly beneath six correct
 * `wp_slimstat::$settings[...]` lines — a copy-paste slip that added a `$`.
 *
 * `$wp_slimstat` is never assigned, so PHP evaluates it as null and raises
 * "Error: Class name must be a valid object or a string" (reproduced on PHP 8.5.5;
 * an Error on every version this plugin supports). It is reached from
 * `add_action('admin_init', ...)` with no try/catch on the path, and the schema
 * version is written at the END of the same function — so it never advances, the
 * branch is re-entered next request, and wp-admin stays white for any site whose
 * stored version is below 4.8.4, with no route out from inside WordPress.
 *
 * Why this is a legitimate source scan rather than a vocabulary match: the defect IS
 * the existence of the call site. No runtime state makes `$SomeClass::` correct, so
 * finding the token pair is finding the bug.
 *
 * It tokenises rather than regexing, for two reasons learned the hard way here:
 * brace-counting over raw text over-runs on a brace inside a string, and five
 * assertions in this suite have passed by matching a name that appeared in a comment
 * or docblock. `token_get_all()` sees neither.
 *
 * Scope: wp-slimstat.php, uninstall.php, admin/, src/ — excluding src/Dependencies/.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);

/**
 * Opt-out marker, on the same line or the line above a legitimate site.
 *
 * The rule is the general one — no `$variable::` anywhere in our own code — rather
 * than a list of class names. Measured before adopting it: across all 115 own files
 * there are **zero** `$variable::` static accesses of any kind, so the general form
 * passes today, needs no maintenance, and catches every case a name list would miss
 * (`$WpSlimstatDb::`, `$Consent::`, `$slimstat_widget::`, and any class added later).
 *
 * An earlier draft enumerated four class names and had already missed
 * `slimstat_widget` at birth — the list was drifting before it shipped.
 *
 * `$class = Foo::class; $class::bar();` is legitimate PHP and would trip this. If that
 * pattern is ever wanted, mark the site rather than weakening the rule — the same
 * convention `php82-84-forward-scan-test.php` uses.
 */
$allow_marker = 'php-variable-static: ok';

$files = slimstat_own_php_files(
    [
        $plugin_root . '/wp-slimstat.php',
        $plugin_root . '/uninstall.php',
        $plugin_root . '/admin',
        $plugin_root . '/src',
    ],
    $plugin_root . '/src/Dependencies'
);

if ([] === $files) {
    fwrite(STDERR, "FAIL: scanned no files — the scanner is broken, not the code\n");
    exit(1);
}

$findings  = [];
$tokenised = 0;

foreach ($files as $file) {
    $source = (string) @file_get_contents($file);
    if ('' === $source) {
        fwrite(STDERR, "FAIL: cannot read {$file}\n");
        exit(1);
    }

    // Byte pre-filter: a finding needs both a `$` and a `::`, so a file without `::`
    // cannot produce one and need not be lexed. Cheap, and it keeps this scanner from
    // adding a second full lex of src/ to a suite that already has one.
    if (false === strpos($source, '::')) {
        continue;
    }

    $tokenised++;
    $lines  = explode("\n", $source);
    $tokens = token_get_all($source);
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || T_VARIABLE !== $tokens[$i][0]) {
            continue;
        }

        $j = slimstat_next_significant($tokens, $i);
        if ($j >= $count || !is_array($tokens[$j]) || T_DOUBLE_COLON !== $tokens[$j][0]) {
            continue;
        }

        $line = $tokens[$i][2];

        // Allow-marked sites: same line, or the line above.
        $here  = $lines[$line - 1] ?? '';
        $above = $lines[$line - 2] ?? '';
        if (false !== strpos($here, $allow_marker) || false !== strpos($above, $allow_marker)) {
            continue;
        }

        $findings[] = sprintf(
            '%s:%d  %s::',
            str_replace($plugin_root . '/', '', $file),
            $line,
            $tokens[$i][1]
        );
    }
}

if ($findings !== []) {
    fwrite(STDERR, 'FAIL: static access through a variable (' . count($findings) . " site(s))\n");
    foreach ($findings as $finding) {
        fwrite(STDERR, "  - {$finding}\n");
    }
    fwrite(STDERR, "\n  Rule: own code does not use \$variable:: — a class name must never carry a `\$`.\n"
        . "  An unassigned one evaluates as null and raises\n"
        . "  \"Class name must be a valid object or a string\".\n"
        . "  If a site is genuinely dynamic, mark it: // {$allow_marker}\n");
    exit(1);
}

printf(
    "PASS: no static access through a variable in own code (%d files, %d tokenised)\n",
    count($files),
    $tokenised
);
exit(0);
