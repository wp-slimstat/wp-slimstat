<?php
/**
 * The migration page must not hide its own status note.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────────────────────────
 *
 * `admin/assets/css/migration.css` opens by hiding every notice on this screen, so another
 * plugin's "rate us" banner cannot sit on top of a database migration:
 *
 *     body.slimstat_page_migration .notice:not(.slimstat-migration-notice) { display: none; }
 *
 * The page's own status note was `class="status-note notice inline notice-info"` — a `.notice`,
 * without the exemption — so the plugin hid it. Measured in a browser on 2026-09-04:
 * `getComputedStyle(#slimstat-status-note).display === "none"`, on a page whose visible copy
 * then read "We are migrating your database…" beside an "Idle" badge with no explanation of
 * why there was nothing to press.
 *
 * The sharper half is the failure path. `migration.js` writes the error text of a failed step
 * into THIS element (`$("#slimstat-status-note").addClass("notice-error").text(message)`), so a
 * migration that failed reported into something with `display: none`. The one message an admin
 * most needs was the one guaranteed to be invisible.
 *
 * ── WHAT THIS ASSERTS ───────────────────────────────────────────────────────────────────────
 *
 * The exemption class is read OUT of the stylesheet rather than hard-coded here: rename it in
 * the CSS and this gate follows, because a gate that pins a spelling the CSS no longer uses is
 * the vacuity one level up. Then: every element migration.js writes status text into must carry
 * that class in the template.
 *
 * Run: php tests/migration-notice-visibility-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$css_path  = $plugin_root . '/admin/assets/css/migration.css';
$js_path   = $plugin_root . '/admin/assets/js/migration.js';
$view_path = $plugin_root . '/src/view/migration-page.php';

foreach (['migration.css' => $css_path, 'migration.js' => $js_path, 'migration-page.php' => $view_path] as $label => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: {$label} is missing at {$path} — this gate would pass by reading nothing\n");
        exit(1);
    }
}

$css  = (string) file_get_contents($css_path);
$js   = (string) file_get_contents($js_path);
// COMMENTS BLANKED. The element check below is a regex over the template, and the comment
// block directly above that element names the exemption class and points at this gate — so
// pasting the fixed markup into that comment as an example satisfied the check while the real
// element stayed unexempted. Verified by a reviewer: G1 survived one comment edit. That is
// PITFALLS 112, one file away from where the sibling gate had just fixed it and in the same
// change — the lesson was not carried across. Inline HTML survives blanking, so the real
// element is untouched.
$view = slimstat_blank_comments((string) file_get_contents($view_path));

// ── 1. The hiding rule, and the class it exempts, read from the CSS itself ──────────────
if (!preg_match('/\.notice:not\(\.([a-z0-9-]+)\)/i', $css, $m)) {
    $failures[] = 'migration.css has no `.notice:not(.<class>)` rule. Either the blanket notice '
        . 'hide is gone — in which case delete this gate — or it was rewritten in a form that no '
        . 'longer states its exemption, and nothing now says which notices survive';
    $exempt = '';
} else {
    $exempt = $m[1];
}

// VACUITY. If the rule above matched but hides nothing, every check below is about a rule with
// no effect. Look for the declaration in the same block.
if ('' !== $exempt && !preg_match('/\.notice:not\(\.' . preg_quote($exempt, '/') . '\)[^{]*\{[^}]*display\s*:\s*none/i', $css)) {
    $failures[] = sprintf('the `.notice:not(.%s)` rule in migration.css does not declare '
        . '`display: none`, so it hides nothing and this gate is asserting about a rule with no '
        . 'effect', $exempt);
}

// ── 2. Every element migration.js writes status text into must carry that class ─────────
//
// The ids are taken from the JS, not from a list here: an element this gate does not know about
// is exactly the one that will be hidden next.
preg_match_all('/\$\(\s*["\']#([a-z0-9-]+)["\']\s*\)/i', $js, $touched);
$status_ids = array_values(array_unique(array_filter(
    $touched[1],
    static function (string $id): bool {
        return false !== strpos($id, 'status') || false !== strpos($id, 'note');
    }
)));

if (!$status_ids) {
    $failures[] = 'migration.js addresses no #...status/#...note element; the scan below has '
        . 'nothing to check, which is how this gate would pass while the note is hidden again';
}

foreach ($status_ids as $id) {
    if (!preg_match('/<[^>]*\bid=["\']' . preg_quote($id, '/') . '["\'][^>]*>/i', $view, $tag)) {
        $failures[] = sprintf('migration.js writes into #%s, which migration-page.php does not '
            . 'declare. A status element the template does not own cannot be exempted from the '
            . 'hide rule, so it will be invisible', $id);
        continue;
    }

    $markup = $tag[0];

    if (false === strpos($markup, 'notice')) {
        continue; // Not a .notice, so the blanket rule never touches it.
    }

    if ('' !== $exempt && false === strpos($markup, $exempt)) {
        $failures[] = sprintf('#%s is a `.notice` and does not carry `%s`, so '
            . 'migration.css hides it — including the failure text migration.js writes into it. '
            . 'This is the defect measured in a browser on 2026-09-04: the page hid its own '
            . 'status note, and a failed migration reported into display:none', $id, $exempt);
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: migration notice visibility (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo sprintf("PASS: migration.css exempts `.%s`, and all %d status element(s) migration.js "
    . "writes into carry it\n", $exempt, count($status_ids));
