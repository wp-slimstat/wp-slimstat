<?php
/**
 * Source-level: own-code JS uses no jQuery event-shorthand that jQuery 4.0
 * removes — `.submit()` / `.click()` triggers and `.click(handler)` binders.
 *
 * PINS FIX (Phase 8). These are no-ops on the jQuery 3.x WordPress ships today
 * (so behavior-preserving), but emit JQMIGRATE warnings and break under jQuery
 * 4.0. Migrated own-code to `.trigger('submit'|'click')` / `.on('click', …)`.
 * This scanner is RED before the migration and green after.
 *
 * IMPORTANT: admin.js bundles vendored qTip2 + bootstrap-switch AFTER the
 * "END: SLIMSTATADMIN HELPER FUNCTIONS" banner — those minified third-party
 * regions are out of scope (shimmed by jQuery Migrate, tracked by the
 * wp56-jquery-migrate-console E2E watchdog), so this scanner reads only the
 * own-code prefix of admin.js.
 *
 * Allow-marker: /​* jquery4: ok *​/ on the line above a site.
 */

declare(strict_types=1);

$plugin_root  = dirname(__DIR__);
$allow_marker = 'jquery4: ok';

// file => marker at which own code ends (null = whole file is own code).
$targets = [
    'admin/assets/js/admin.js'                                 => '/* qTip2',
    'admin/assets/js/daterangepicker/slimstat-daterangepicker.js' => null,
];

// Trigger patterns are scoped to a jQuery receiver — $(...) or jQuery(...) —
// so a native DOM element's .click() (e.g. el.click() where el = getElementById)
// is NOT flagged (it is valid in jQuery 4.0). The bind-shorthand .click(fn) is
// jQuery-specific (a DOM .click() takes no handler), so it needs no receiver scope.
$patterns = [
    '$(...).submit() event-shorthand trigger (removed in jQuery 4.0)' => '/(?:\$|jQuery)\([^)]*\)\.submit\(\s*\)/',
    '$(...).click() event-shorthand trigger (removed in jQuery 4.0)'  => '/(?:\$|jQuery)\([^)]*\)\.click\(\s*\)/',
    // Bind-shorthand: any non-empty arg — inline function, arrow fn, or a handler
    // reference (the empty-call trigger form is covered by the patterns above).
    '.click(handler) bind-shorthand (removed in jQuery 4.0)'          => '/\.click\(\s*(?!\))/',
    '.submit(handler) bind-shorthand (removed in jQuery 4.0)'         => '/\.submit\(\s*(?!\))/',
];

$violations = [];
foreach ($targets as $rel => $ownCodeEndMarker) {
    $path = $plugin_root . '/' . $rel;
    $contents = @file_get_contents($path);
    if (false === $contents) { $violations[] = "{$rel}: cannot read"; continue; }
    if (null !== $ownCodeEndMarker) {
        $cut = strpos($contents, $ownCodeEndMarker);
        if (false !== $cut) $contents = substr($contents, 0, $cut);
    }
    foreach ($patterns as $label => $pattern) {
        if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches[0] as [$match, $offset]) {
            $line_no = substr_count($contents, "\n", 0, $offset) + 1;
            $prev    = $line_no > 1 ? explode("\n", substr($contents, 0, $offset))[$line_no - 2] : '';
            if (false !== strpos($prev, $allow_marker)) continue;
            $violations[] = sprintf('%s:%d  [%s]  → %s', $rel, $line_no, $label, trim($match));
        }
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: jQuery-4.0-removed event shorthand in own-code JS:\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    fwrite(STDERR, "\nFix: .submit()/.click() → .trigger('submit'|'click'); .click(fn) → .on('click', fn).\n");
    exit(1);
}
echo "OK: own-code JS has no jQuery-4.0-removed event shorthands\n";
