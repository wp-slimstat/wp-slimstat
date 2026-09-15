<?php
/**
 * Source-level: every translated string in the free plugin names the free text domain.
 *
 * TWO STRINGS WERE BOUND TO PRO'S DOMAIN, and neither had ever been translatable.
 *
 * `admin/index.php`'s export button carried `__('Upgrade to Pro', 'wp-slimstat-pro')` and
 * `__('Export', 'wp-slimstat-pro')`. Free ships no `wp-slimstat-pro` .mo file, and on a site
 * without Pro installed nothing loads that domain at all — so both strings fell back to
 * English in every locale, permanently and silently. There is no error for this: `__()` with
 * an unloaded domain returns its input.
 *
 * It is also what WordPress.org's Plugin Check reports as `WordPress.WP.I18n.TextDomainMismatch`,
 * which has been a release blocker for this plugin before.
 *
 * SCOPED TO LITERALS, AND THE COMMENT BLANKING MATTERS. The subject of this gate is the second
 * argument of a gettext call — a string literal — so `slimstat_strip_comments_and_strings()`
 * would erase exactly what it exists to find. Comments are blanked instead, so this file's own
 * explanation cannot satisfy it.
 *
 * Calls whose domain is a variable or a constant are counted and reported rather than assumed
 * correct: "measured none" must never read as "all fine".
 *
 * THIS STANDS IN FOR A SNIFF THIS REPO DOES NOT RUN. `WordPress.WP.I18n.TextDomainMismatch`
 * would catch it, but there is no PHPCS here — no phpcs.xml, no WPCS in composer.json, nothing
 * in CI — and adding it is a heavier lift than it looks on a repo that commits a `--no-dev -o`
 * autoloader. If WPCS is ever adopted, retire this gate rather than running both.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

const SLIMSTAT_FREE_TEXT_DOMAIN = 'wp-slimstat';

// The domain the plugin header declares is the authority; hardcoding it here would make this
// gate disagree with the plugin silently if either changed.
$headerRaw = (string) file_get_contents($plugin_root . '/wp-slimstat.php');
if (preg_match('/^\s*\*\s*Text Domain:\s*(\S+)\s*$/mi', $headerRaw, $m)) {
    $declared = trim($m[1]);
} else {
    $declared = '';
    $failures[] = 'wp-slimstat.php declares no Text Domain header, so there is nothing to '
        . 'check every call against';
}

if ('' !== $declared && SLIMSTAT_FREE_TEXT_DOMAIN !== $declared) {
    $failures[] = sprintf(
        "the plugin header declares text domain '%s' but this gate was written against '%s' — "
            . 're-anchor it deliberately rather than letting the two drift',
        $declared,
        SLIMSTAT_FREE_TEXT_DOMAIN
    );
}

$sources = array_merge(
    [$plugin_root . '/wp-slimstat.php'],
    // ABSOLUTE prefix. slimstat_own_php_files() compares against getPathname(), which is
    // absolute, so a relative 'src/Dependencies' never matches and 493 vendored files were
    // scanned. Harmless today — no dependency calls gettext — but the day one does, this gate
    // would fail on code this plugin cannot fix.
    slimstat_own_php_files(
        [$plugin_root . '/src', $plugin_root . '/admin', $plugin_root . '/views'],
        $plugin_root . '/src/Dependencies'
    )
);

// Every gettext-family function that takes a text domain, with the argument index the domain
// sits at (0-based, counting from the first argument).
$gettext = [
    '__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1,
    'esc_attr__' => 1, 'esc_attr_e' => 1,
    '_x' => 2, '_ex' => 2, 'esc_html_x' => 2, 'esc_attr_x' => 2,
    '_n' => 3, '_nx' => 4,
    // Zero uses in this tree today, listed so the gap is closed before one appears rather
    // than after. Indices checked against wp-includes/l10n.php.
    '_n_noop' => 2, '_nx_noop' => 3,
    'translate' => 1, 'translate_with_gettext_context' => 2, 'translate_nooped_plural' => 2,
];

$checked    = 0;
$dynamic    = 0;
$mismatched = [];

foreach ($sources as $file) {
    $lit    = slimstat_blank_comments((string) file_get_contents($file));
    $tokens = slimstat_tokenize($lit, false);
    $names  = slimstat_name_token_types();

    foreach ($tokens as $i => $token) {
        // isset(), not in_array(): slimstat_name_token_types() returns a MAP KEYED BY TOKEN
        // TYPE, so in_array() against its values (all `true`) never matches an int and this
        // scan found zero call sites. The vacuity floor below is what caught it.
        if (!is_array($token) || !isset($names[$token[0]])) {
            continue;
        }

        $fn = slimstat_last_name_segment($token[1]);
        if (!isset($gettext[$fn])) {
            continue;
        }

        // A method or property of the same name is not this function.
        $before = $i - 1;
        while ($before > 0 && is_array($tokens[$before]) && T_WHITESPACE === $tokens[$before][0]) {
            $before--;
        }
        if (is_array($tokens[$before])
            && in_array($tokens[$before][0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_FUNCTION], true)) {
            continue;
        }

        $open = slimstat_next_significant($tokens, $i);
        if (!isset($tokens[$open]) || '(' !== slimstat_token_text($tokens[$open])) {
            continue;
        }

        $close = slimstat_token_paren_end($tokens, $open, count($tokens));
        if (null === $close) {
            continue;
        }

        // Split on top-level commas.
        $depth = 0;
        $args  = [[]];
        for ($k = $open + 1; $k < $close; $k++) {
            $text = slimstat_token_text($tokens[$k]);
            if ('(' === $text || '[' === $text) {
                $depth++;
            } elseif (')' === $text || ']' === $text) {
                $depth--;
            } elseif (0 === $depth && ',' === $text) {
                $args[] = [];
                continue;
            }
            $args[count($args) - 1][] = $tokens[$k];
        }

        $idx = $gettext[$fn];
        if (!isset($args[$idx])) {
            // No domain argument at all — WordPress then defaults to 'default', i.e. core's
            // own strings. Reported, because it is the same class of silence.
            $failures[] = sprintf(
                '%s: %s() is called with no text domain, so WordPress looks the string up in '
                    . "core's 'default' domain",
                slimstat_rel_path($plugin_root, $file),
                $fn
            );
            continue;
        }

        // CONCATENATED LITERALS RESOLVE. `'wp-slimstat' . '-pro'` is a compile-time constant
        // wearing a dynamic shape — an earlier draft counted it as "built at run time" and
        // skipped it, which is a name-only bypass of the whole gate: the correct domain appears
        // at the call site and the wrong one is what gets used.
        $literal = null;
        $sawText = false;
        foreach ($args[$idx] as $piece) {
            if (is_array($piece) && in_array($piece[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($piece) && T_CONSTANT_ENCAPSED_STRING === $piece[0]) {
                $literal = (string) $literal . substr($piece[1], 1, -1);
                $sawText = true;
                continue;
            }
            if (!is_array($piece) && '.' === trim(slimstat_token_text($piece))) {
                continue;
            }
            $literal = null;
            $sawText = false;
            break;
        }

        if (!$sawText) {
            $literal = null;
        }

        if (null === $literal) {
            $dynamic++;
            continue;
        }

        $checked++;

        if (SLIMSTAT_FREE_TEXT_DOMAIN !== $literal) {
            $mismatched[] = sprintf(
                "%s: %s(... , '%s')",
                slimstat_rel_path($plugin_root, $file),
                $fn,
                $literal
            );
        }
    }
}

foreach ($mismatched as $hit) {
    $failures[] = $hit . " — that domain is not loaded by this plugin, so the string falls back "
        . 'to English in every locale, silently. wp.org Plugin Check reports it as '
        . 'TextDomainMismatch';
}

// VACUITY FLOOR. A tokenizer change, a rename, or a different call shape would otherwise let
// this pass by finding nothing — which is exactly what the first draft of this scan did, by
// using in_array() against a map keyed by token type. 1,325 literal-domain calls were counted
// on 2026-09-01.
if ($checked < 1300) {
    $failures[] = sprintf(
        'only %d gettext calls with a literal domain were found (expected at least 1300) — the '
            . 'scan has stopped seeing its own subject, so a clean result means nothing',
        $checked
    );
}

if ($failures) {
    fwrite(STDERR, 'FAIL: text domain single source (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: all ' . $checked . " gettext calls with a literal domain name '"
    . SLIMSTAT_FREE_TEXT_DOMAIN . "' (" . $dynamic . " build the domain at run time)\n";
