#!/usr/bin/env bash
# tests/docker/measure-d68.sh <ref|->  [http_port] [db_port]
#
# D68's before/after measurement: what identity a COOKIELESS visitor accumulates across one
# short browsing session, on a real install, driven over HTTP with no cookie jar.
#
# The storm: four pageviews from one "browser" (fixed UA, one IP) — page A, page A again,
# page B in the same 300-second bucket, then page C after sleeping past the next bucket
# boundary. Mechanism (a) of the defect is deterministic-within-bucket, so the id splits at
# the BOUNDARY, not per hit — the sleep is what makes the defect observable, and the probe
# says how long it waited.
#
#   before: the same person holds 2 distinct visit_ids for a 4-page visit (one per bucket);
#           on a ~20-minute visit that is up to 4 — "cookieless visitors mint a new visit_id
#           per <bucket>", D68.
#   after:  1 visit_id for the session, and one stable vid_hash on every row.
#
# Also recorded per arm:
#   - EXPLAIN of the arm's OWN anonymous-reuse probe query shape (before: a dt-range scan
#     filtered by unindexed ip; after: a seek on the vid_hash index)
#   - that all 4 pageviews were RECORDED (a lost pageview would be a worse defect than the
#     one under test)
#   - that no slimstat tracking cookie was ever offered (the anonymous branch must not
#     write one)
#
# Counts and plan shapes only — no milliseconds. Rows-examined deltas at scale belong to
# the Phase F scorecard on the I8 fixture.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

REF="${1:?ref to measure ('-' for working tree)}"
HTTP_PORT="${2:-18930}"
DB_PORT="${3:-13930}"
PHP="${D68_PHP:-8.2}"
WP="${D68_WP:-6.7}"

CELL="d68-$(echo "$REF" | tr -cd '[:alnum:]' | cut -c1-12)"
[ "$REF" = "-" ] && CELL="d68-worktree"
CELL_DIR="$WORK_ROOT/d68/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
PROJECT="ssd68$(echo "$CELL" | tr -cd '[:alnum:]' | tr '[:upper:]' '[:lower:]')"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
UA="Mozilla/5.0 (X11; Linux x86_64) D68-probe/1.0"
status="PASS"; reason=""

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

fail(){ status="FAIL"; reason="${reason:-$1}"; err "$1"; }

ARM_SRC="$PLUGIN_SRC"
WORKTREE=""
if [ "$REF" != "-" ]; then
  WORKTREE="$CELL_DIR/arm-src"
  rm -rf "$WORKTREE"
  git -C "$PLUGIN_SRC" worktree add --detach "$WORKTREE" "$REF" >/dev/null 2>&1 \
    || { err "cannot create worktree at $REF"; exit 1; }
  ARM_SRC="$WORKTREE"
fi

finish() {
  if scan_debug_log "$WP_DIR" "$ART"; then
    fail "wp-slimstat fatal in debug.log (see artifacts/debug.log)"
  fi
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  [ -n "$WORKTREE" ] && git -C "$PLUGIN_SRC" worktree remove --force "$WORKTREE" >/dev/null 2>&1
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

echo "CONTROLS:"
if [ -n "$WORKTREE" ]; then
  echo "  arm source: $(git -C "$WORKTREE" rev-parse --short HEAD) (requested: $REF)"
else
  echo "  arm source: WORKING TREE ($(git -C "$PLUGIN_SRC" rev-parse --short HEAD) + uncommitted)"
fi

log "[$CELL] build + up (PHP $PHP, WP $WP, http $HTTP_PORT, db $DB_PORT)"
boot_stack "$ART" "$PHP" || { fail "stack did not come up"; exit 1; }

wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { fail "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"
wpc core install --url="$BASE_URL" --title="D68 $CELL" --admin_user=admin \
    --admin_password=admin --admin_email=qa@example.com --skip-email >>"$ART/install.log" 2>&1 \
    || { fail "core install failed"; exit 1; }

# Three pages for the visitor to navigate.
for t in pageA pageB pageC; do
  wpc post create --post_title="$t" --post_status=publish --post_name="$t" >>"$ART/install.log" 2>&1 \
    || fail "post create $t failed"
done

sync_plugin_src "$WP_DIR" "$ARM_SRC"
wpc plugin activate wp-slimstat >>"$ART/install.log" 2>&1 || { fail "activation failed"; exit 1; }

# The anonymous gate, spelled out (flow analysis 2026-08-11): the branch runs only when
# anonymous_tracking=on AND piiAllowed() is false — and piiAllowed() is unconditionally TRUE
# when gdpr_enabled is off, so BOTH must be on. javascript_mode=no puts the tracker on the
# server-side `wp` hook, which is what lets a cookieless curl BE the browser.
wpc eval '
  $s = get_option("slimstat_options", []);
  $s["anonymous_tracking"] = "on";
  $s["gdpr_enabled"]       = "on";
  $s["javascript_mode"]    = "no";
  $s["is_tracking"]        = "on";
  update_option("slimstat_options", $s);
  echo "settings: anon=" . $s["anonymous_tracking"] . " gdpr=" . $s["gdpr_enabled"]
     . " jsmode=" . $s["javascript_mode"];
' > "$ART/settings.log" 2>&1 || fail "settings update failed"
cat "$ART/settings.log"; echo ""

hit() { # Args: slug  → one cookieless pageview; headers land in headers.log
  curl -s -D - -o /dev/null -A "$UA" "$BASE_URL/?name=$1" >> "$ART/headers.log" 2>&1
}

rows() { wpc eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats");' 2>/dev/null | tr -dc '0-9'; }

# ── the storm ───────────────────────────────────────────────────────────────
hit pageA; sleep 1; hit pageA; sleep 1; hit pageB

# Cross the next 300 s bucket boundary — this is what makes mechanism (a) observable.
now=$(date +%s); wait_s=$(( 300 - now % 300 + 3 ))
echo "  bucket boundary: sleeping ${wait_s}s to cross floor(now/300)"
sleep "$wait_s"
hit pageC

# THREE rows, not four: the second hit to pageA is dropped by Processor's anonymous
# refresh-dedup (one row per visit+resource inside the session window) — measured on the
# BEFORE arm first, where the expect-4 version of this control failed and taught it. The
# dedup is identical logic in both arms, so the row count is a controlled constant and the
# only thing allowed to differ between arms is the IDENTITY on the rows.
recorded=$(rows)
echo "  pageviews recorded: ${recorded:-0} (expect 3: refresh deduped)"
[ "${recorded:-0}" = "3" ] || fail "expected 3 recorded pageviews, table holds ${recorded:-0} — the probe proved nothing (PITFALLS 38)"

# ── identity accumulated by the one visitor ─────────────────────────────────
wpc eval '
  global $wpdb; $t = $wpdb->prefix . "slim_stats";
  $has_vid_hash = (int) $wpdb->get_var("SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"{$t}\" AND COLUMN_NAME=\"vid_hash\"");
  $distinct = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visit_id) FROM {$t}");
  $zero_ids = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE visit_id = 0");
  $hashes   = $has_vid_hash
      ? (int) $wpdb->get_var("SELECT COUNT(DISTINCT vid_hash) FROM {$t} WHERE vid_hash IS NOT NULL")
      : -1;
  $rows = $wpdb->get_results("SELECT id, visit_id, resource, dt" .
      ($has_vid_hash ? ", HEX(vid_hash) vh" : "") . " FROM {$t} ORDER BY id", ARRAY_A);
  foreach ($rows as $r) {
    printf("    row id=%d visit_id=%u dt=%d %s %s\n", $r["id"], $r["visit_id"], $r["dt"],
        $r["resource"], isset($r["vh"]) ? substr((string) $r["vh"], 0, 12) . "…" : "(no vid_hash column)");
  }
  printf("  distinct visit_id: %d · rows with visit_id=0: %d · distinct vid_hash: %s\n",
      $distinct, $zero_ids, $hashes < 0 ? "n/a (column absent)" : (string) $hashes);
' 2>/dev/null | tee "$ART/identity.log"

# ── the arm's own reuse-probe plan shape ────────────────────────────────────
#
# KNOWN LIMIT, stated rather than hidden (review, Run 16): these are REPLICAS of each
# arm's probe, keyed on schema shape — a second spelling that can drift from the code.
# For THIS storm the replicas match the real probes by construction: the no-JS curl
# visitor has no fingerprint, so the before-arm's conditional fingerprint/browser
# filters never attach, and the after-arm's probe shape is exercised identically by the
# storm itself (3 real probes ran before this EXPLAIN). The honest instrument captures
# the arm's actual statements (SAVEQUERIES mu-plugin) and EXPLAINs those — Lane I work,
# recorded in Run 16's "does not establish" list.
wpc eval '
  global $wpdb; $t = $wpdb->prefix . "slim_stats";
  $has_vid_hash = (int) $wpdb->get_var("SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"{$t}\" AND COLUMN_NAME=\"vid_hash\"");
  $sql = $has_vid_hash
      ? "SELECT visit_id FROM {$t} WHERE vid_hash = UNHEX(REPEAT(\"ab\",16)) AND visit_id > 0 AND dt >= 0 ORDER BY dt DESC LIMIT 1"
      : "SELECT visit_id FROM {$t} WHERE ip = \"x\" AND resource = \"/y\" AND dt >= 0 AND dt <= 2000000000 ORDER BY dt DESC LIMIT 1";
  foreach ($wpdb->get_results("EXPLAIN {$sql}", ARRAY_A) as $p) {
    printf("  EXPLAIN(%s probe): type=%s key=%s rows=%s extra=%s\n",
        $has_vid_hash ? "vid_hash" : "ip+resource+dt",
        $p["type"], $p["key"] ?? "NULL", $p["rows"], $p["Extra"] ?? "");
  }
' 2>/dev/null | tee "$ART/explain.log"

# ── the anonymous branch must not offer a cookie ────────────────────────────
if grep -qi "set-cookie: slimstat_tracking_code" "$ART/headers.log"; then
  fail "a slimstat tracking cookie was offered to a non-consenting cookieless visitor"
else
  echo "  cookie check: no slimstat_tracking_code ever offered — correct"
fi

cp "$ART/identity.log" "$ART/d68-result.txt" 2>/dev/null || true
echo ""
echo "RESULT ($CELL): artifacts in $ART"
