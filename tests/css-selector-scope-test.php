<?php

/**
 * Fix 5 — guard that SlimStat's admin CSS does not leak WP-standard / bundled
 * third-party selectors into the rest of wp-admin.
 *
 * admin.css loads not only on SlimStat's own pages but on the WP Dashboard and
 * the post-list (edit.php) screen (the posts-column feature), neither of which
 * carries body.slimstat-admin-page. So any rule that styles a generic class
 * (.form-table, .nav-tab, .CodeMirror) or a bundled-library selector
 * (.ui-datepicker, .qtip, .ui-widget-overlay) at the top level would restyle
 * OTHER plugins' UI on those shared screens.
 *
 * Fix: those rules are confined under body.slimstat-admin-page (SlimStat only
 * renders these widgets on its own pages, which carry that body class), and the
 * modal-content rule is scoped to the .slimstat dialog. This scans the COMPILED
 * admin.css (the shipped artifact) so a future SCSS edit that forgets to scope —
 * or forgets to recompile — fails here.
 *
 * Run: php tests/css-selector-scope-test.php
 */

declare(strict_types=1);

$failures = [];
function css_assert(bool $cond, string $label, array &$failures): void
{
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

$css = (string) file_get_contents(dirname(__DIR__) . '/admin/assets/css/admin.css');
if ($css === '') {
    fwrite(STDERR, "FAIL: cannot read compiled admin.css (run `npm run sass-compile`)\n");
    exit(1);
}

// Each leaky base selector must NOT appear as an unscoped rule-start. In the
// minified CSS a rule-start is preceded by `}` (previous rule) or `,` (selector
// list) or `{` (inside a media block). The scoped form is always preceded by
// `.slimstat-admin-page `, so none of these bare prefixes may occur.
$leaky = ['.form-table', '.nav-tab', '.nav-tabs', '.CodeMirror', '.ui-datepicker', '.qtip', '.ui-widget-overlay'];
foreach ($leaky as $sel) {
    foreach (['}' . $sel, ',' . $sel, '{' . $sel] as $unscoped) {
        css_assert(strpos($css, $unscoped) === false, "no unscoped rule-start \"{$unscoped}\"", $failures);
    }
}

// Robust check: a leaky base can also leak when prefixed by another token
// (e.g. `.rtl .form-table`, `body.rtl .ui-datepicker`) — those slip past the
// bare-rule-start scan above. Parse every selector group and assert that any
// individual selector targeting a leaky class also carries a SlimStat scope
// (a .slimstat* / #slim* / .wrap-slimstat / .ui-dialog.slimstat ancestor).
if (preg_match_all('/([^{}]+)\{/', $css, $groups)) {
    foreach ($groups[1] as $selectorList) {
        foreach (explode(',', $selectorList) as $oneSelector) {
            $oneSelector = trim($oneSelector);
            if ($oneSelector === '' || $oneSelector[0] === '@') {
                continue; // skip @media / @font-face / etc.
            }
            foreach ($leaky as $needle) {
                if (strpos($oneSelector, $needle) === false) {
                    continue;
                }
                $scopedHere = (bool) preg_match('/\.slimstat|#slim|wrap-slimstat/i', $oneSelector);
                css_assert($scopedHere, "leaky selector carries a SlimStat scope: \"{$oneSelector}\"", $failures);
            }
        }
    }
}

// And the scoped forms must actually be present (proves the scoping shipped, not
// that the selectors were simply deleted).
$scoped = [
    '.slimstat-admin-page .form-table',
    '.slimstat-admin-page .nav-tab',
    '.slimstat-admin-page .CodeMirror',
    '.slimstat-admin-page .ui-datepicker',
    '.slimstat-admin-page .qtip',
    '.slimstat-admin-page .ui-widget-overlay',
];
foreach ($scoped as $sel) {
    css_assert(strpos($css, $sel) !== false, "scoped selector present: \"{$sel}\"", $failures);
}

// The jQuery UI dialog content rule is scoped to SlimStat's dialog (dialogClass:
// "slimstat"), not every .ui-dialog on the page.
css_assert(strpos($css, '.ui-dialog.slimstat .ui-dialog-content') !== false, 'dialog content scoped to .ui-dialog.slimstat', $failures);
css_assert(strpos($css, '}.ui-dialog .ui-dialog-content') === false, 'no unscoped .ui-dialog .ui-dialog-content rule', $failures);

echo "\n";
if ($failures) {
    echo count($failures) . " FAILURE(S)\n";
    exit(1);
}
echo "ALL PASS\n";
exit(0);
