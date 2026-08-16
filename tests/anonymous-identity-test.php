<?php
/**
 * Source-level: the cookieless visitor's identity is 128 bits, stable across a session,
 * and erasable — D68, realising decision P2 in the tracker.
 *
 * What the old code did, measured (Run 16) and traced (flow analysis 2026-08-11):
 *
 *   - `generateAnonymousVisitId()` truncated an HMAC to 32 bits —
 *     `abs((int) hexdec(substr($hash, 0, 8)))` — which is ~50% collision odds at ~77k
 *     cookieless visitors, and deterministic collisions by construction (X3). After G4 a
 *     subject-access export would return ANOTHER PERSON's browsing history.
 *   - Without a fingerprint the identity folded in `floor(now/300)*300`, so the same
 *     person minted a NEW visit_id at every 5-minute boundary (D68's headline symptom).
 *   - `findExistingAnonymousVisitId()` filtered on `resource = <this URL>`, so ordinary
 *     navigation could never match, and its most selective predicate (`ip`) has no index —
 *     an uncached 30-minute range scan per anonymous pageview.
 *   - The Ajax update path re-derived the id BEFORE the fingerprint existed and
 *     retroactively rewrote the row's visit_id with the weaker identity.
 *   - `DataEraser::anonymizeByIp()` cleared the fingerprint but left the
 *     fingerprint-derived visit identity on the "anonymized" row.
 *
 * The fix this pins: identity lives in `vid_hash BINARY(16)` (full-width HMAC, no bucket
 * entropy, daily salt retained on purpose — no cross-day identity is a privacy feature);
 * `visit_id` is minted from the sequential VisitIdGenerator and keeps ONLY session
 * semantics; the reuse probe matches `vid_hash` within the session window and is served by
 * a declared index; the eraser clears `vid_hash`.
 *
 * Constructs via the token helpers — comments are blanked before every check, because the
 * mutation registered against this gate leaves the old formula behind in a comment.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

// PurgeArchive.php opens with a direct-access guard — a bare `exit` when ABSPATH is
// undefined, which is EXIT CODE 0. Without this define, autoloading it ends this gate
// silently, mid-run, reporting success: the PITFALLS 46 shape at process scope, found
// because the gate printed nothing where a dozen failures were due.
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$session_src = (string) @file_get_contents($plugin_root . '/src/Tracker/Session.php');
$ajax_src    = (string) @file_get_contents($plugin_root . '/src/Tracker/Ajax.php');
$eraser_src  = (string) @file_get_contents($plugin_root . '/src/Services/Privacy/DataEraser.php');
$db_src      = (string) @file_get_contents($plugin_root . '/admin/view/wp-slimstat-db.php');

foreach (['Session' => $session_src, 'Ajax' => $ajax_src, 'DataEraser' => $eraser_src, 'wp-slimstat-db' => $db_src] as $name => $src) {
    if ('' === $src) {
        fwrite(STDERR, "FAIL: cannot read {$name}\n");
        exit(1);
    }
}

// ── 1. The 32-bit truncation is GONE from Session.php ───────────────────────────────────
//
// `hexdec` appears in this file only inside the truncation `abs((int) hexdec(substr(...)))`,
// so its presence IS the defect. Comments blanked: the registered mutation restores the
// formula while a comment still quotes it.
$session_code = slimstat_blank_comments($session_src);

if (false !== strpos($session_code, 'hexdec')) {
    $failures[] = 'Session.php still truncates a hash with hexdec() — a 32-bit visitor identity '
        . 'is ~50% collision odds at 77k cookieless visitors, and after G4 that is one person '
        . "receiving another's browsing history in a subject-access export (P2)";
}

if (null !== slimstat_find_function_body($session_src, 'generateAnonymousVisitId')) {
    $failures[] = 'Session.php still declares generateAnonymousVisitId() — the 32-bit minting '
        . 'path must be gone, not merely bypassed, or a fallback keeps it reachable';
}

// ── 2. The 128-bit identity exists and is full-width ────────────────────────────────────
$vid_hash_body = slimstat_find_function_body($session_src, 'generateAnonymousVidHash');

if (null === $vid_hash_body) {
    $failures[] = 'Session.php does not declare generateAnonymousVidHash() — nothing mints the '
        . 'BINARY(16) identity P2 ratified';
} else {
    $body = slimstat_blank_comments($vid_hash_body);
    if (!preg_match('/hash_hmac\s*\(\s*[\'"]sha256[\'"].*?,\s*true\s*\)/s', $body)) {
        $failures[] = 'generateAnonymousVidHash() does not take raw (binary) HMAC output — hex '
            . 'output silently halves the identity width when 16 bytes of it are stored';
    }
    // `.*?` not `[^,]+`: the first substr argument is the hash_hmac(...) call itself,
    // which contains commas — the stricter class matched a shape the real code never has.
    if (!preg_match('/substr\s*\(.*?,\s*0\s*,\s*16\s*\)/s', $body)) {
        $failures[] = 'generateAnonymousVidHash() does not cut the HMAC to exactly 16 raw bytes — '
            . 'BINARY(16) truncates silently, and every key sharing a prefix would collapse';
    }
    if (false !== strpos($body, 'floor(')) {
        $failures[] = 'generateAnonymousVidHash() still folds a time bucket into the identity — '
            . 'floor(now/300) is what minted a new id at every 5-minute boundary (D68)';
    }
}

// ── 3. The reuse probe matches identity, not the page being viewed ──────────────────────
$probe_body = slimstat_find_function_body($session_src, 'findExistingAnonymousVisitId');

if (null === $probe_body) {
    $failures[] = 'Session.php no longer declares findExistingAnonymousVisitId() — the reuse '
        . 'probe is what keeps one session ONE session';
} else {
    $body = slimstat_blank_comments($probe_body);
    if (false === strpos($body, 'vid_hash')) {
        $failures[] = 'findExistingAnonymousVisitId() does not match on vid_hash — matching on '
            . 'request attributes is what made navigation unmatchable';
    }
    if (false !== strpos($body, "'resource'")) {
        $failures[] = 'findExistingAnonymousVisitId() still filters on resource — a visitor who '
            . 'NAVIGATES can never match their own session (D68 mechanism a)';
    }
    if (!preg_match('/[\'"]visit_id[\'"]\s*,\s*[\'"]>[\'"]\s*,\s*0/', $body)) {
        $failures[] = 'findExistingAnonymousVisitId() does not exclude visit_id = 0 rows — the '
            . 'newest matching row with the column default hides older good rows (mechanism e)';
    }
}

// ── 4. The anonymous branch stamps vid_hash and mints visit_id sequentially ─────────────
$ensure_body = slimstat_find_function_body($session_src, 'ensureVisitId');

if (null === $ensure_body) {
    $failures[] = 'Session.php no longer declares ensureVisitId()';
} else {
    $body = slimstat_blank_comments($ensure_body);
    if (false === strpos($body, 'vid_hash')) {
        $failures[] = 'ensureVisitId() never touches vid_hash — the anonymous branch must stamp '
            . 'the identity on every hit, found or minted';
    }
}

// ── 5. The Ajax update path derives the fingerprint BEFORE the identity ─────────────────
//
// Positional, and honestly so: both calls live in process(), and the defect was purely
// their order — ensureVisitId(true) ran first, fell to the weaker IP+UA formula, and
// updateRow() rewrote the row's visit_id with it.
$ajax_code   = slimstat_blank_comments($ajax_src);
$client_pos  = strpos($ajax_code, 'getClientInfo');
$ensure_pos  = strpos($ajax_code, 'ensureVisitId');

if (false === $client_pos || false === $ensure_pos) {
    $failures[] = 'Ajax.php no longer calls both getClientInfo() and ensureVisitId() — the '
        . 'ordering this gate pins has been restructured; re-pin it';
} elseif ($ensure_pos < $client_pos) {
    $failures[] = 'Ajax.php calls ensureVisitId() before getClientInfo() — the identity is '
        . 'derived without the fingerprint and the row is retroactively rewritten with the '
        . 'weaker id (D68 mechanism c)';
}

// ── 6. Erasure erases the identity ──────────────────────────────────────────────────────
$erase_body = slimstat_find_function_body($eraser_src, 'anonymizeByIp');

if (null === $erase_body) {
    $failures[] = 'DataEraser.php no longer declares anonymizeByIp()';
} elseif (false === strpos(slimstat_blank_comments($erase_body), 'vid_hash = NULL')) {
    // The SET fragment itself, not the bare name: the body legitimately contains
    // `$has_vid_hash` (the schema probe) and a comment-blanked scan would accept that
    // variable as evidence of clearing while the actual SET clause is gone.
    $failures[] = 'anonymizeByIp() does not clear vid_hash — an "anonymized" row would keep a '
        . 'value derived from the fingerprint the same UPDATE just erased';
}

// ── 7. The schema half: declared column, declared index, archived column ────────────────
$columns = \SlimStat\Schema\Schema::columns('slim_stats');
if (!isset($columns['vid_hash']) || 0 !== stripos(trim($columns['vid_hash']), 'BINARY(16)')) {
    $failures[] = "the manifest does not declare slim_stats.vid_hash as BINARY(16) — " .
        (isset($columns['vid_hash']) ? "it declares '{$columns['vid_hash']}'" : 'it is absent') .
        '. Fresh installs must be born with the full-width identity (C39)';
}

$indexes = \SlimStat\Schema\Schema::indexes('slim_stats');
if (!isset($indexes['idx_vid_hash_dt'])) {
    $failures[] = 'the manifest declares no idx_vid_hash_dt — without it the reuse probe is the '
        . 'same 30-minute range scan it replaced, once per anonymous pageview';
}

if (!in_array('vid_hash', \SlimStat\Utils\PurgeArchive::STATS_COLUMNS, true)) {
    $failures[] = 'PurgeArchive::STATS_COLUMNS omits vid_hash — the purge would archive rows '
        . 'while silently dropping the identity column (the C25/C36 shape)';
}

// ── 8. The report-side identity ladder knows the new tier ───────────────────────────────
$expr_body = slimstat_find_function_body($db_src, 'visitor_id_expr');

if (null === $expr_body) {
    $failures[] = 'wp-slimstat-db.php no longer declares visitor_id_expr()';
} elseif (false === strpos(slimstat_blank_comments($expr_body), 'vid_hash')) {
    $failures[] = 'visitor_id_expr() has no vid_hash tier — goals, funnels and unique-visitor '
        . 'counts would keep resolving cookieless identity through the 32-bit visit_id';
}

// ── 9. The migration adds columns and writes NO rows — the no-backfill policy ───────────
//
// Historical rows keep vid_hash NULL forever: their identity inputs (the per-day salts)
// are gone, and recomputing with today's salt would fabricate an identity the visitor
// never had — the P2 harm, delivered by a migration. Comments AND strings are blanked,
// so neither the docblock stating this policy nor a quoted SQL literal can satisfy or
// trip the scan; a write statement has to appear as CODE.
$migration_src = (string) @file_get_contents($plugin_root . '/src/Migration/Migrations/AddVisitIdentity.php');
if ('' === $migration_src) {
    $failures[] = 'cannot read src/Migration/Migrations/AddVisitIdentity.php — re-anchor this '
        . 'section rather than deleting it; the no-backfill policy loses its only gate with it';
} else {
    $migration_code = slimstat_strip_comments_and_strings($migration_src);

    // Blanking control: the raw file names addManifestColumn once in a comment and twice
    // as calls. Exactly 2 surviving the blanking proves both that the file was read and
    // that the blanking ran — 3 here means the scan is reading prose.
    $call_count = substr_count($migration_code, 'addManifestColumn');
    if (2 !== $call_count) {
        $failures[] = "AddVisitIdentity holds {$call_count} addManifestColumn call(s) where 2 were "
            . 'expected (slim_stats + slim_stats_archive) — the migration changed shape or the '
            . 'blanking control failed; re-anchor this section rather than deleting it';
    }

    // Residual channel, named rather than closed: a backfill hidden behind a NEW
    // parent-class helper would be invisible to any single-file scan. The word list pins
    // wpdb's method surface plus hash_hmac; \b cannot fire inside identifiers, so a
    // future deleteMarker() or $updated does not trip it.
    if (1 === preg_match('/\b(update|insert|replace|delete|query|get_results|hash_hmac)\b/i', $migration_code, $m)) {
        $failures[] = "AddVisitIdentity contains '{$m[1]}' as code — the migration writes rows into "
            . 'the fact table. The no-backfill policy is load-bearing: the historical identity '
            . 'inputs are gone, so any backfill fabricates identities the visitor never had (P2); '
            . 'NULL on old rows is the true statement';
    }
}

echo "\n";
if ([] !== $failures) {
    fwrite(STDERR, 'FAIL: anonymous identity (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: cookieless identity is 128-bit vid_hash, session-stable, indexed, erasable; "
    . "visit_id is sequential session-only; the migration backfills nothing\n";
exit(0);
